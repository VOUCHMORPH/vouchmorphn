<?php

declare(strict_types=1);

namespace Domain\Services\Settlement;

use PDO;
use DateTimeImmutable;
use Exception;

/**
 * Hybrid Settlement Strategy - NON-CUSTODIAL
 * 
 * RESPONSIBILITIES:
 * - Track net positions between participants (who owes whom)
 * - Send settlement instructions to participants
 * - Invoice fees to participants
 * - Handle cross-border corridor settlements
 * - Manage cashout retry logic (unearned fees)
 * - Perform multilateral netting (daily/weekly/monthly)
 * - Record settlement acknowledgements
 * - Generate comprehensive regulator reports
 * 
 * VouchMorph NEVER holds customer funds. Only orchestrates settlement messages.
 */
class HybridSettlementStrategy
{
    private PDO $db;
    private string $defaultCurrency = 'BWP';
    private array $vouchmorphCorridorAccounts = [];
    private array $participants = [];   // NEW
    
    private const VOUCHMORPH_FEE_ACCOUNT = 'VOUCHMORPH_OPERATIONS';
    private const VOUCHMORPH_FEE_ACCOUNT_NUMBER = 'VM-OP-001';
    private const VOUCHMORPH_CORRIDOR_PREFIX = 'VM-CB-';
    
    private const STATUS_PENDING = 'PENDING';
    private const STATUS_SENT = 'SENT';
    private const STATUS_ACKNOWLEDGED = 'ACK';
    private const STATUS_COMPLETED = 'COMPLETED';
    private const STATUS_FAILED = 'FAILED';
    
    private const MSG_SETTLEMENT_INSTRUCTION = 'SETTLEMENT_INSTRUCTION';
    private const MSG_DEBIT_INSTRUCTION = 'DEBIT_INSTRUCTION';
    private const MSG_CREDIT_INSTRUCTION = 'CREDIT_INSTRUCTION';
    private const MSG_FEE_INVOICE = 'FEE_INVOICE';
    private const MSG_RECONCILIATION = 'RECONCILIATION';
    private const MSG_CROSS_BORDER = 'CROSS_BORDER_SETTLEMENT';
    private const MSG_CORRIDOR_INSTRUCTION = 'CORRIDOR_INSTRUCTION';

    public function __construct(PDO $db, array $vouchmorphCorridorAccounts = [], array $participants = [])
{
    $this->db = $db;
    $this->vouchmorphCorridorAccounts = $vouchmorphCorridorAccounts;
    $this->participants = $participants;
    $this->ensureTablesExist();
}
    
    /**
     * Ensure all required tables exist with proper columns
     */
    private function ensureTablesExist(): void
    {
        // net_positions - already exists
        // settlement_outbox - already exists
        // settlement_queue - already exists
        // settlement_reports - already exists
        // settlement_acknowledgements - already exists
        
        // Add any missing columns to existing tables
        $this->addMissingColumns();
    }
    
    /**
     * Add missing columns to existing tables if needed
     */
    private function addMissingColumns(): void
    {
        // Add composite_signature to settlement_outbox if missing
        try {
            $this->db->exec("
                ALTER TABLE settlement_outbox 
                ADD COLUMN IF NOT EXISTS composite_signature TEXT,
                ADD COLUMN IF NOT EXISTS is_multi_source BOOLEAN DEFAULT FALSE,
                ADD COLUMN IF NOT EXISTS master_reference VARCHAR(100)
            ");
        } catch (Exception $e) {
            error_log("[SETTLEMENT] Could not add columns to settlement_outbox: " . $e->getMessage());
        }
        
        // Add exchange_rate and corridor_fee to settlement_reports
        try {
            $this->db->exec("
                ALTER TABLE settlement_reports 
                ADD COLUMN IF NOT EXISTS exchange_rate NUMERIC(24,10),
                ADD COLUMN IF NOT EXISTS corridor_fee NUMERIC(12,2) DEFAULT 0,
                ADD COLUMN IF NOT EXISTS regulator_reference VARCHAR(100)
            ");
        } catch (Exception $e) {
            error_log("[SETTLEMENT] Could not add columns to settlement_reports: " . $e->getMessage());
        }
        
        // Add currency to settlement_queue
        try {
            $this->db->exec("
                ALTER TABLE settlement_queue 
                ADD COLUMN IF NOT EXISTS currency CHAR(3) DEFAULT 'BWP',
                ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'PENDING',
                ADD COLUMN IF NOT EXISTS reference VARCHAR(100)
            ");
        } catch (Exception $e) {
            error_log("[SETTLEMENT] Could not add columns to settlement_queue: " . $e->getMessage());
        }
    }

    // ============================================================
    // NET POSITION & SETTLEMENT METHODS
    // ============================================================
    
    /**
     * UPDATE NET POSITION - Track who owes whom
     * Called by SwapService after successful swap completion
     */
    public function updateNetPosition(
        string $swapRef,
        string $sourceInstitution, 
        string $destinationInstitution, 
        float $amount, 
        string $transactionType,
        string $currency = 'BWP'
    ): array {
        try {
            // Update net positions table
            $this->updateNetPositionsTable($sourceInstitution, $destinationInstitution, $amount, $currency);
            
            // Add to settlement queue
            $this->addToSettlementQueue($sourceInstitution, $destinationInstitution, $amount, $currency, $swapRef);
            
            // Send settlement instruction to both parties
            $messageUuid = $this->sendSettlementInstruction(
                $swapRef, $sourceInstitution, $destinationInstitution, $amount, $currency, $transactionType
            );
            
            $this->logObligation($sourceInstitution, $destinationInstitution, $amount, $currency, $transactionType, $messageUuid);
            
            error_log("[SETTLEMENT] Obligation recorded for swap $swapRef: $sourceInstitution owes $destinationInstitution $amount $currency ($transactionType)");
            
            return [
                'success' => true,
                'message_uuid' => $messageUuid,
                'debtor' => $sourceInstitution,
                'creditor' => $destinationInstitution,
                'amount' => $amount,
                'currency' => $currency,
                'swap_reference' => $swapRef
            ];
            
        } catch (Exception $e) {
            error_log("[SETTLEMENT] Failed to update net position: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Update net_positions table
     */
    private function updateNetPositionsTable(string $debtor, string $creditor, float $amount, string $currency): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO net_positions (debtor, creditor, amount, currency_code, created_at, updated_at)
            VALUES (:debtor, :creditor, :amount, :currency, NOW(), NOW())
            ON CONFLICT (debtor, creditor, currency_code) 
            DO UPDATE SET amount = net_positions.amount + :amount, updated_at = NOW()
        ");
        $stmt->execute([
            ':debtor' => $debtor, 
            ':creditor' => $creditor, 
            ':amount' => $amount, 
            ':currency' => $currency
        ]);
    }
    
    /**
     * Add to settlement queue for processing
     */
    private function addToSettlementQueue(string $debtor, string $creditor, float $amount, string $currency, string $reference): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO settlement_queue (debtor, creditor, amount, currency, reference, created_at, updated_at)
            VALUES (:debtor, :creditor, :amount, :currency, :reference, NOW(), NOW())
        ");
        $stmt->execute([
            ':debtor' => $debtor,
            ':creditor' => $creditor,
            ':amount' => $amount,
            ':currency' => $currency,
            ':reference' => $reference
        ]);
    }
    
    /**
     * Send settlement instruction to participants
     */
    private function sendSettlementInstruction(
        string $swapRef,
        string $sourceInstitution,
        string $destinationInstitution,
        float $amount,
        string $currency,
        string $transactionType
    ): string {
        $messageUuid = $this->generateUuid();
        
        $instruction = [
            'instruction_id' => $messageUuid,
            'swap_reference' => $swapRef,
            'type' => 'SETTLEMENT_INSTRUCTION',
            'debtor' => $sourceInstitution,
            'creditor' => $destinationInstitution,
            'amount' => $amount,
            'currency' => $currency,
            'transaction_type' => $transactionType,
            'settlement_deadline' => date('Y-m-d H:i:s', strtotime('+2 hours')),
            'instructions' => [
                'method' => 'DIRECT_PARTICIPANT_SETTLEMENT',
                'reference' => $swapRef,
                'notes' => 'Please settle directly with counterparty. VouchMorph does not hold funds.',
                'reconciliation_required' => true
            ]
        ];
        
        $stmt = $this->db->prepare("
            INSERT INTO settlement_outbox 
            (message_uuid, swap_reference, source_institution, destination_institution, 
             amount, currency, message_type, message_payload, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', NOW())
        ");
        
        $stmt->execute([
            $messageUuid, $swapRef, $sourceInstitution, $destinationInstitution,
            $amount, $currency, self::MSG_SETTLEMENT_INSTRUCTION, json_encode($instruction)
        ]);
        
        $this->deliverToParticipant($sourceInstitution, $instruction);
        $this->deliverToParticipant($destinationInstitution, $instruction);
        
        return $messageUuid;
    }

    // ============================================================
    // CASHOUT RETRY TRACKING METHODS
    // ============================================================
    
    /**
     * Store unearned cashout fee for future retry
     */
    public function storeUnearnedCashoutFee(
        string $swapRef,
        string $clientIdentifier,
        float $unearnedFee,
        string $error
    ): void {
        // Use settlement_queue for tracking retries
        $stmt = $this->db->prepare("
            INSERT INTO settlement_queue 
            (debtor, creditor, amount, currency, reference, status, created_at, updated_at)
            VALUES (:client, 'UNEARNED_FEE', :fee, 'BWP', :swap_ref, 'PENDING', NOW(), NOW())
        ");
        
        $stmt->execute([
            ':client' => $clientIdentifier,
            ':fee' => $unearnedFee,
            ':swap_ref' => $swapRef
        ]);
        
        error_log("[SETTLEMENT] Unearned cashout fee stored: $unearnedFee for $swapRef");
    }
    
    /**
     * Get unearned cashout fee for retry
     */
    public function getUnearnedCashoutFee(string $originalSwapRef, string $clientIdentifier): float
    {
        $stmt = $this->db->prepare("
            SELECT amount FROM settlement_queue 
            WHERE reference = :swap_ref AND debtor = :client AND creditor = 'UNEARNED_FEE' AND status = 'PENDING'
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([':swap_ref' => $originalSwapRef, ':client' => $clientIdentifier]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (float)$result['amount'] : 0;
    }
    
    /**
     * Get retry count for original swap
     */
    public function getRetryCount(string $originalSwapRef, string $clientIdentifier): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as retry_count FROM settlement_queue 
            WHERE reference LIKE :pattern AND debtor = :client AND creditor = 'RETRY'
        ");
        $stmt->execute([
            ':pattern' => $originalSwapRef . '%',
            ':client' => $clientIdentifier
        ]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (int)$result['retry_count'] : 0;
    }
    
    /**
     * Mark unearned fee as used
     */
    public function markUnearnedFeeAsUsed(string $originalSwapRef, string $clientIdentifier, float $amountUsed): void
    {
        $stmt = $this->db->prepare("
            UPDATE settlement_queue 
            SET status = 'COMPLETED', updated_at = NOW()
            WHERE reference = :swap_ref AND debtor = :client AND creditor = 'UNEARNED_FEE' AND status = 'PENDING'
        ");
        
        $stmt->execute([
            ':swap_ref' => $originalSwapRef,
            ':client' => $clientIdentifier
        ]);
        
        error_log("[SETTLEMENT] Unearned cashout fee marked as used: $amountUsed for $originalSwapRef");
    }
    
    /**
     * Update retry count on failure
     */
    public function updateRetryCount(string $originalSwapRef, string $clientIdentifier, string $error): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO settlement_queue 
            (debtor, creditor, amount, currency, reference, status, created_at, updated_at)
            VALUES (:client, 'RETRY', 0, 'BWP', :swap_ref, 'FAILED', NOW(), NOW())
        ");
        
        $stmt->execute([
            ':client' => $clientIdentifier,
            ':swap_ref' => $originalSwapRef . '_RETRY_' . time()
        ]);
        
        error_log("[SETTLEMENT] Retry recorded for $originalSwapRef");
    }
    
    /**
     * Check if free retry is available (first retry only)
     */
    public function isFreeRetryAvailable(string $originalSwapRef, string $clientIdentifier): bool
    {
        $retryCount = $this->getRetryCount($originalSwapRef, $clientIdentifier);
        $unearnedFee = $this->getUnearnedCashoutFee($originalSwapRef, $clientIdentifier);
        
        return $retryCount === 0 && $unearnedFee > 0;
    }

    // ============================================================
    // FEE INVOICING METHODS
    // ============================================================
    
    /**
     * Invoice participants for fees
     */
    public function invoiceFee(
        string $swapReference,
        string $institution,
        int $participantId,
        string $feeType,
        float $feeAmount,
        string $currency = 'BWP',
        float $vatRate = 0.14
    ): string {
        $invoiceUuid = $this->generateUuid();
        // FIX (2026-09-21): the fee the customer is shown and pays is VAT-inclusive
        // (consumer prices in Botswana include VAT), so the VAT is carved out of it,
        // not added on top. Before, a P6.00 fee was invoiced as P6.84.
        // Set FEES_EXCLUDE_VAT=1 only if the accountant confirms fees are quoted
        // excluding VAT.
        $feesExcludeVat = in_array(strtolower((string)getenv('FEES_EXCLUDE_VAT')), ['1', 'true', 'yes'], true);
        if ($feesExcludeVat) {
            $netAmount = $feeAmount;
            $vatAmount = round($feeAmount * $vatRate, 2);
            $totalAmount = round($feeAmount + $vatAmount, 2);
        } else {
            $totalAmount = round($feeAmount, 2);
            $vatAmount = round($feeAmount * $vatRate / (1 + $vatRate), 2);
            $netAmount = round($totalAmount - $vatAmount, 2);
        }

        // Real payment details from the environment (the placeholders VM-FEE-001 / VM001 are gone).
        $feeBank = getenv('VOUCHMORPH_FEE_BANK') ?: 'ZURUBANK';
        $feeAccount = getenv('VOUCHMORPH_FEE_ACCOUNT') ?: 'VOUCHMORPH-FEES';

        $invoice = [
            'invoice_uuid' => $invoiceUuid,
            'swap_reference' => $swapReference,
            'fee_type' => $feeType,
            'fee_amount' => $netAmount,
            'fee_amount_excl_vat' => $netAmount,
            'vat_rate' => $vatRate,
            'vat_amount' => $vatAmount,
            'total_amount' => $totalAmount,
            'vat_basis' => $feesExcludeVat ? 'EXCLUSIVE' : 'INCLUSIVE',
            'currency' => $currency,
            'payee' => self::VOUCHMORPH_FEE_ACCOUNT,
            'payee_account' => $feeAccount,
            'payment_instructions' => [
                'bank' => $feeBank,
                'account_name' => 'VouchMorph (Pty) Ltd',
                'account_number' => $feeAccount,
                'bank_code' => $feeBank,
                'reference' => $invoiceUuid,
                'notes' => 'Fee for swap transaction ' . $swapReference
            ],
            'due_date' => date('Y-m-d H:i:s', strtotime('+30 days')),
            'late_fee' => $totalAmount * 0.05
        ];
        
        // Store in settlement_outbox
        $stmt = $this->db->prepare("
            INSERT INTO settlement_outbox 
            (message_uuid, swap_reference, source_institution, destination_institution, 
             amount, currency, message_type, message_payload, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', NOW())
        ");
        
        $stmt->execute([
            $invoiceUuid, $swapReference, $institution, self::VOUCHMORPH_FEE_ACCOUNT,
            $totalAmount, $currency, self::MSG_FEE_INVOICE, json_encode($invoice)
        ]);
        
        $invoice['instruction_id'] = $invoiceUuid;
        $this->deliverToParticipant($institution, $invoice);
        
        error_log("[SETTLEMENT] Fee invoice sent to $institution: $totalAmount $currency for $feeType");
        
        return $invoiceUuid;
    }
    
    /**
     * Record fee payment received
     */
    public function recordFeePayment(string $invoiceUuid, string $paymentReference): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE settlement_outbox 
                SET status = 'ACKNOWLEDGED', acknowledged_at = NOW()
                WHERE message_uuid = ? AND message_type = ?
            ");
            
            $stmt->execute([$invoiceUuid, self::MSG_FEE_INVOICE]);
            $updated = $stmt->rowCount();
            
            if ($updated) {
                error_log("[SETTLEMENT] Fee payment recorded for invoice $invoiceUuid");
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("[SETTLEMENT] Failed to record fee payment: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get outstanding invoices for an institution
     */
    public function getOutstandingInvoices(string $institutionName): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM settlement_outbox 
            WHERE source_institution = ? AND message_type = ? AND status = 'PENDING'
            ORDER BY created_at ASC
        ");
        $stmt->execute([$institutionName, self::MSG_FEE_INVOICE]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ============================================================
    // COMPREHENSIVE REPORTING FOR REGULATORS
    // ============================================================
    
    /**
     * Generate comprehensive settlement report for regulator
     */
    public function generateRegulatorReport(
        string $startDate,
        string $endDate,
        string $currency = 'BWP'
    ): array {
        $reportId = $this->generateReportId();
        $cycleId = 'CYCLE_' . date('Ymd') . '_' . uniqid();
        
        // Get all settlements AND fees in date range (split by type)
        $settlements = $this->getSettlementsInRange($startDate, $endDate);
        $feeInvoices = $this->getFeeInvoicesInRange($startDate, $endDate);
        
        // Split settlements by actual status so the report is honest about
        // what's confirmed vs merely dispatched.
        $confirmedSettlements = array_values(array_filter($settlements, fn($s) => in_array($s['status'], ['COMPLETED', 'ACKNOWLEDGED'])));
        $unconfirmedSettlements = array_values(array_filter($settlements, fn($s) => !in_array($s['status'], ['COMPLETED', 'ACKNOWLEDGED'])));
        
        // Calculate net positions from ALL settlements (confirmed + unconfirmed)
        $netPositions = $this->calculateNetPositionsForReport($settlements);
        
        // Participant breakdown
        $participantBreakdown = $this->getParticipantBreakdown($settlements);
        
        // Calculate totals
        $totalSettlementAmount = array_sum(array_column($settlements, 'amount'));
        $totalFeeAmount = array_sum(array_column($feeInvoices, 'amount'));
        
        // Generate BISS (Bank of International Settlements) reference
        $bissReferences = $this->generateBISSReferences($settlements);
        
        // Generate report hash
        $reportHash = $this->generateReportHash($settlements, $netPositions);
        
        // Store the report
        $this->storeSettlementReport(
            $reportId,
            $cycleId,
            count($settlements),
            $totalSettlementAmount,
            $netPositions,
            $participantBreakdown,
            $bissReferences,
            $reportHash
        );
        
        // Also store in settlement_queue for audit
        foreach ($netPositions as $position) {
            $this->db->prepare("
                INSERT INTO settlement_queue (debtor, creditor, amount, currency, reference, status, created_at)
                VALUES (:debtor, :creditor, :amount, :currency, :reference, 'REPORT', NOW())
            ")->execute([
                ':debtor' => $position['debtor'],
                ':creditor' => $position['creditor'],
                ':amount' => $position['net_amount'],
                ':currency' => $currency,
                ':reference' => $reportId
            ]);
        }
        
        return [
            'report_id' => $reportId,
            'cycle_id' => $cycleId,
            'date_range' => ['start' => $startDate, 'end' => $endDate],
            'total_settlements' => count($settlements),
            'confirmed_settlements' => count($confirmedSettlements),
            'unconfirmed_settlements' => count($unconfirmedSettlements),
            'total_settlement_amount' => $totalSettlementAmount,
            'total_fee_invoices' => count($feeInvoices),
            'total_fee_amount' => $totalFeeAmount,
            'currency' => $currency,
            'net_positions' => $netPositions,
            'participant_breakdown' => $participantBreakdown,
            'biss_references' => $bissReferences,
            'report_hash' => $reportHash,
            'generated_at' => date('Y-m-d H:i:s'),
            'regulator_ready' => count($unconfirmedSettlements) === 0,
            'reconciliation_warning' => count($unconfirmedSettlements) > 0
                ? count($unconfirmedSettlements) . " of " . count($settlements) . " settlement instructions have not been acknowledged by both counterparties. Figures include unconfirmed obligations."
                : null,
            'settlements' => $settlements,
            'fee_invoices' => $feeInvoices
        ];
    }
    
    /**
     * Get all settlements in date range (ALL statuses, not just completed)
     * NOTE: As of now, nothing in the system ever calls acknowledgeSettlement(),
     * so no row has ever reached COMPLETED/ACKNOWLEDGED. Reporting only on those
     * statuses returns zero rows even with real settlement activity present.
     * Until a reconciliation/acknowledgement trigger exists, report on
     * PENDING/SENT too, clearly labeled as "unconfirmed".
     */
    private function getSettlementsInRange(string $startDate, string $endDate): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                so.*,
                sq.amount as queue_amount,
                np.amount as net_position_amount
            FROM settlement_outbox so
            LEFT JOIN settlement_queue sq ON so.swap_reference = sq.reference
            LEFT JOIN net_positions np ON so.source_institution = np.debtor AND so.destination_institution = np.creditor
            WHERE so.created_at BETWEEN :start AND :end
            AND so.message_type = 'SETTLEMENT_INSTRUCTION'
            ORDER BY so.created_at
        ");
        $stmt->execute([':start' => $startDate, ':end' => $endDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get fee invoices in date range
     */
    private function getFeeInvoicesInRange(string $startDate, string $endDate): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                so.*,
                sq.amount as queue_amount
            FROM settlement_outbox so
            LEFT JOIN settlement_queue sq ON so.swap_reference = sq.reference
            WHERE so.created_at BETWEEN :start AND :end
            AND so.message_type = 'FEE_INVOICE'
            ORDER BY so.created_at
        ");
        $stmt->execute([':start' => $startDate, ':end' => $endDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Calculate net positions for report
     */
    private function calculateNetPositionsForReport(array $settlements): array
    {
        $positions = [];
        
        foreach ($settlements as $settlement) {
            $debtor = $settlement['source_institution'];
            $creditor = $settlement['destination_institution'];
            $amount = (float)$settlement['amount'];
            
            $key = $debtor . '|' . $creditor;
            
            if (!isset($positions[$key])) {
                $positions[$key] = [
                    'debtor' => $debtor,
                    'creditor' => $creditor,
                    'amount' => 0,
                    'count' => 0
                ];
            }
            
            $positions[$key]['amount'] += $amount;
            $positions[$key]['count']++;
        }
        
        // Convert to array and add net amount
        $result = [];
        foreach ($positions as $position) {
            $result[] = [
                'debtor' => $position['debtor'],
                'creditor' => $position['creditor'],
                'gross_amount' => $position['amount'],
                'settlement_count' => $position['count'],
                'net_amount' => $position['amount']
            ];
        }
        
        return $result;
    }
    
    /**
     * Get participant breakdown
     */
    private function getParticipantBreakdown(array $settlements): array
    {
        $breakdown = [];
        
        foreach ($settlements as $settlement) {
            $source = $settlement['source_institution'];
            $dest = $settlement['destination_institution'];
            $amount = (float)$settlement['amount'];
            
            if (!isset($breakdown[$source])) {
                $breakdown[$source] = [
                    'total_sent' => 0,
                    'total_received' => 0,
                    'settlements' => []
                ];
            }
            
            if (!isset($breakdown[$dest])) {
                $breakdown[$dest] = [
                    'total_sent' => 0,
                    'total_received' => 0,
                    'settlements' => []
                ];
            }
            
            $breakdown[$source]['total_sent'] += $amount;
            $breakdown[$dest]['total_received'] += $amount;
            
            $breakdown[$source]['settlements'][] = [
                'to' => $dest,
                'amount' => $amount,
                'reference' => $settlement['swap_reference']
            ];
        }
        
        return $breakdown;
    }
    
    /**
     * Generate BISS (Bank of International Settlements) references
     */
    private function generateBISSReferences(array $settlements): array
    {
        $bissRefs = [];
        
        foreach ($settlements as $settlement) {
            $bissRefs[] = [
                'biss_reference' => 'BISS_' . date('Ymd') . '_' . uniqid(),
                'swap_reference' => $settlement['swap_reference'],
                'source' => $settlement['source_institution'],
                'destination' => $settlement['destination_institution'],
                'amount' => $settlement['amount'],
                'currency' => $settlement['currency']
            ];
        }
        
        return $bissRefs;
    }
    
    /**
     * Generate report hash for integrity
     */
    private function generateReportHash(array $settlements, array $netPositions): string
    {
        $data = json_encode([
            'settlements' => $settlements,
            'net_positions' => $netPositions,
            'timestamp' => time()
        ]);
        return hash('sha256', $data);
    }
    
    /**
     * Store settlement report
     */
    private function storeSettlementReport(
        string $reportId,
        string $cycleId,
        int $totalSettlements,
        float $totalAmount,
        array $netPositions,
        array $participantBreakdown,
        array $bissReferences,
        string $reportHash
    ): void {
        $stmt = $this->db->prepare("
            INSERT INTO settlement_reports 
            (settlement_report_id, report_date, cycle_id, total_settlements, total_amount, 
             net_positions, participant_breakdown, biss_references, generated_at, report_hash)
            VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, NOW(), ?)
        ");
        
        $stmt->execute([
            $reportId,
            $cycleId,
            $totalSettlements,
            $totalAmount,
            json_encode($netPositions),
            json_encode($participantBreakdown),
            json_encode($bissReferences),
            $reportHash
        ]);
    }
    
    /**
     * Get report by ID
     */
    public function getReport(string $reportId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM settlement_reports 
            WHERE settlement_report_id = :report_id
        ");
        $stmt->execute([':report_id' => $reportId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $result['net_positions'] = json_decode($result['net_positions'], true);
            $result['participant_breakdown'] = json_decode($result['participant_breakdown'], true);
            $result['biss_references'] = json_decode($result['biss_references'], true);
        }
        
        return $result;
    }
    
    /**
     * Get all reports for a date range
     */
    public function getReportsForDateRange(string $startDate, string $endDate): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM settlement_reports 
            WHERE report_date BETWEEN :start AND :end
            ORDER BY report_date DESC
        ");
        $stmt->execute([':start' => $startDate, ':end' => $endDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ============================================================
    // CROSS-BORDER SETTLEMENT METHODS
    // ============================================================
    
    public function processCrossBorderSettlement(
        string $swapRef,
        string $sourceCountry,
        string $destinationCountry,
        string $sourceInstitution,
        string $destinationInstitution,
        float $amount,
        string $sourceCurrency,
        string $destinationCurrency,
        float $exchangeRate,
        float $corridorFee = 0
    ): array {
        
        $this->logEvent('CROSS_BORDER_START', [
            'swap_ref' => $swapRef,
            'from' => $sourceCountry,
            'to' => $destinationCountry,
            'amount' => $amount,
            'currencies' => "{$sourceCurrency}→{$destinationCurrency}"
        ]);
        
        $convertedAmount = $amount * $exchangeRate;
        
        $sourceAccount = $this->getVouchMorphCorridorAccount($sourceCountry, $sourceCurrency);
        $destinationAccount = $this->getVouchMorphCorridorAccount($destinationCountry, $destinationCurrency);
        
        if (!$sourceAccount || !$destinationAccount) {
            throw new Exception("VouchMorph corridor accounts not configured for {$sourceCountry}/{$destinationCountry}");
        }
        
        // Step 1: Source → VouchMorph
        $this->updateNetPosition($swapRef, $sourceInstitution, $sourceAccount['account_name'], $amount, 'cross_border_source_to_vm', $sourceCurrency);
        
        // Step 2: Internal transfer
        $this->recordInternalTransfer($swapRef, $sourceAccount['account_number'], $destinationAccount['account_number'], $convertedAmount, $destinationCurrency, $exchangeRate);
        
        // Step 3: VouchMorph → Destination
        $this->updateNetPosition($swapRef, $destinationAccount['account_name'], $destinationInstitution, $convertedAmount, 'cross_border_vm_to_destination', $destinationCurrency);
        
        // Step 4: Corridor fee
        if ($corridorFee > 0) {
            $this->invoiceFee($swapRef, $sourceInstitution, 0, 'CORRIDOR_FEE', $corridorFee, $sourceCurrency);
        }
        
        $transactionId = $this->recordCrossBorderTransaction(
            $swapRef, $sourceCountry, $destinationCountry, $sourceInstitution, $destinationInstitution,
            $amount, $sourceCurrency, $convertedAmount, $destinationCurrency, $exchangeRate,
            $corridorFee, $sourceAccount['account_number'], $destinationAccount['account_number']
        );
        
        return [
            'success' => true,
            'transaction_id' => $transactionId,
            'source_amount' => $amount,
            'source_currency' => $sourceCurrency,
            'destination_amount' => $convertedAmount,
            'destination_currency' => $destinationCurrency,
            'exchange_rate' => $exchangeRate,
            'corridor_fee' => $corridorFee
        ];
    }
    
    public function processCorridorSettlement(
        string $swapRef,
        string $sourceInstitution,
        string $destinationInstitution,
        string $sourceCountry,
        string $destinationCountry,
        float $amount,
        string $currency,
        float $corridorFee = 0
    ): array {
        
        $vmSourceAccount = $this->getVouchMorphCorridorAccount($sourceCountry, $currency);
        $vmDestAccount = $this->getVouchMorphCorridorAccount($destinationCountry, $currency);
        
        if (!$vmSourceAccount || !$vmDestAccount) {
            $this->updateNetPosition($swapRef, $sourceInstitution, $destinationInstitution, $amount, 'direct_settlement', $currency);
            return ['method' => 'direct', 'corridor_fee' => 0, 'message' => 'Direct settlement used'];
        }
        
        $this->updateNetPosition($swapRef, $sourceInstitution, $vmSourceAccount['account_name'], $amount, 'corridor_inbound', $currency);
        $this->updateNetPosition($swapRef, $vmDestAccount['account_name'], $destinationInstitution, $amount, 'corridor_outbound', $currency);
        
        $actualCorridorFee = $corridorFee > 0 ? ($corridorFee < 1 ? $amount * $corridorFee : $corridorFee) : 0;
        if ($actualCorridorFee > 0) {
            $this->invoiceFee($swapRef, $sourceInstitution, 0, 'CORRIDOR_FEE', $actualCorridorFee, $currency);
        }
        
        $this->recordCorridorTransaction($swapRef, $sourceCountry, $destinationCountry, $sourceInstitution, $destinationInstitution, $amount, $currency, $actualCorridorFee, $vmSourceAccount['account_number'], $vmDestAccount['account_number']);
        
        return [
            'method' => 'corridor',
            'corridor_fee' => $actualCorridorFee,
            'source_vm_account' => $vmSourceAccount['account_number'],
            'destination_vm_account' => $vmDestAccount['account_number']
        ];
    }

    // ============================================================
    // NET POSITION & RECONCILIATION METHODS
    // ============================================================
    
    public function getNetPosition(string $debtor, string $creditor, string $currency = 'BWP'): float
    {
        $stmt = $this->db->prepare("
            SELECT amount FROM net_positions
            WHERE debtor = :debtor AND creditor = :creditor AND currency_code = :currency
        ");
        $stmt->execute([':debtor' => $debtor, ':creditor' => $creditor, ':currency' => $currency]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (float)$result['amount'] : 0.0;
    }
    
    public function getPendingMessagesForInstitution(string $institutionName): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM settlement_outbox 
            WHERE (source_institution = ? OR destination_institution = ?)
            AND status IN ('PENDING', 'SENT')
            ORDER BY created_at ASC
        ");
        $stmt->execute([$institutionName, $institutionName]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function generateReconciliationReport(string $institutionName, string $currency = 'BWP'): array
    {
        $netAsDebtor = $this->getTotalNetPositionAsDebtor($institutionName, $currency);
        $netAsCreditor = $this->getTotalNetPositionAsCreditor($institutionName, $currency);
        $netObligation = $netAsDebtor - $netAsCreditor;
        $pendingMessages = $this->getPendingMessagesForInstitution($institutionName);
        $outstandingInvoices = $this->getOutstandingInvoices($institutionName);
        
        return [
            'institution' => $institutionName,
            'currency' => $currency,
            'as_at' => date('Y-m-d H:i:s'),
            'total_owed_to_others' => $netAsDebtor,
            'total_owed_by_others' => $netAsCreditor,
            'net_position' => $netObligation,
            'net_position_text' => $netObligation > 0 ? "OWES $netObligation $currency" : "IS OWED " . abs($netObligation) . " $currency",
            'pending_settlements' => count($pendingMessages),
            'pending_messages' => $pendingMessages,
            'outstanding_invoices' => array_map(function($inv) {
                return [
                    'invoice_uuid' => $inv['message_uuid'],
                    'fee_type' => $inv['message_type'],
                    'total_amount' => (float)$inv['amount'],
                    'currency' => $inv['currency'],
                    'created_at' => $inv['created_at']
                ];
            }, $outstandingInvoices)
        ];
    }
    
    /**
     * Calculate multilateral net obligations for all participants
     */
    public function calculateMultilateralNetting(): array
    {
        $allPositions = $this->getAllNetPositions();
        $netObligations = [];
        $processed = [];
        
        foreach ($allPositions as $debtor => $creditorData) {
            if (in_array($debtor, $processed)) continue;
            
            foreach ($creditorData as $creditor => $amounts) {
                foreach ($amounts as $currency => $amount) {
                    if ($amount <= 0.01) continue;
                    
                    $reverseAmount = $this->getNetPosition($creditor, $debtor, $currency);
                    
                    if ($reverseAmount > 0) {
                        $netAmount = abs($amount - $reverseAmount);
                        $netObligations[] = [
                            'debtor' => $amount > $reverseAmount ? $debtor : $creditor,
                            'creditor' => $amount > $reverseAmount ? $creditor : $debtor,
                            'gross_amount' => $amount,
                            'reverse_amount' => $reverseAmount,
                            'net_amount' => $netAmount,
                            'currency' => $currency
                        ];
                        
                        // Clear these positions after netting
                        $this->clearNetPosition($debtor, $creditor, $currency);
                        $this->clearNetPosition($creditor, $debtor, $currency);
                        
                        error_log("[SETTLEMENT] Multilateral netting: $debtor owes $creditor net $netAmount $currency");
                    } else {
                        $netObligations[] = [
                            'debtor' => $debtor,
                            'creditor' => $creditor,
                            'gross_amount' => $amount,
                            'reverse_amount' => 0,
                            'net_amount' => $amount,
                            'currency' => $currency
                        ];
                    }
                }
            }
            
            $processed[] = $debtor;
        }
        
        // Send net settlement instructions
        foreach ($netObligations as $obligation) {
            $this->sendSettlementInstruction(
                'NETTING_BATCH_' . date('Ymd'),
                $obligation['debtor'],
                $obligation['creditor'],
                $obligation['net_amount'],
                $obligation['currency'],
                'MULTILATERAL_NETTING'
            );
        }
        
        return $netObligations;
    }

    // ============================================================
    // CORRIDOR ACCOUNT METHODS
    // ============================================================
    
    public function getVouchMorphCorridorAccount(string $countryCode, string $currency): ?array
    {
        $key = strtoupper($countryCode) . '_' . strtoupper($currency);
        if (isset($this->vouchmorphCorridorAccounts[$key])) {
            return $this->vouchmorphCorridorAccounts[$key];
        }
        
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM vouchmorph_corridor_accounts
                WHERE country_code = ? AND currency = ? AND is_active = TRUE
                LIMIT 1
            ");
            $stmt->execute([$countryCode, $currency]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                $result['account_name'] = self::VOUCHMORPH_CORRIDOR_PREFIX . $countryCode;
            }
            
            return $result ?: null;
        } catch (Exception $e) {
            error_log("[SETTLEMENT] Failed to get corridor account: " . $e->getMessage());
            return null;
        }
    }
    
    public function getAllCorridorAccounts(): array
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM vouchmorph_corridor_accounts WHERE is_active = TRUE");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("[SETTLEMENT] Failed to get corridor accounts: " . $e->getMessage());
            return [];
        }
    }
    
    public function registerCorridorAccount(string $countryCode, string $accountNumber, string $currency, float $initialBalance = 0): array
    {
        $accountName = self::VOUCHMORPH_CORRIDOR_PREFIX . $countryCode;
        
        $stmt = $this->db->prepare("
            INSERT INTO vouchmorph_corridor_accounts 
            (country_code, account_number, account_name, currency, balance, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
            ON CONFLICT (country_code, currency) DO UPDATE SET
                account_number = EXCLUDED.account_number,
                updated_at = NOW()
            RETURNING id
        ");
        
        $stmt->execute([$countryCode, $accountNumber, $accountName, $currency, $initialBalance]);
        
        return [
            'country_code' => $countryCode,
            'account_number' => $accountNumber,
            'account_name' => $accountName,
            'currency' => $currency,
            'balance' => $initialBalance
        ];
    }

    // ============================================================
    // SETTLEMENT ACKNOWLEDGEMENT METHODS
    // ============================================================
    
    public function acknowledgeSettlement(string $messageUuid, string $institutionName, array $proofData = []): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO settlement_acknowledgements 
                (message_uuid, swap_reference, source_institution, ack_type, ack_payload, received_at)
                SELECT ?, swap_reference, ?, 'SETTLED', ?, NOW()
                FROM settlement_outbox 
                WHERE message_uuid = ?
            ");
            $stmt->execute([$messageUuid, $institutionName, json_encode($proofData), $messageUuid]);
            
            $stmt = $this->db->prepare("
                UPDATE settlement_outbox 
                SET status = 'ACKNOWLEDGED', acknowledged_at = NOW()
                WHERE message_uuid = ? AND destination_institution = ?
            ");
            $stmt->execute([$messageUuid, $institutionName]);
            
            error_log("[SETTLEMENT] Settlement acknowledged by $institutionName for $messageUuid");
            $this->checkSettlementComplete($messageUuid);
            
            return true;
            
        } catch (Exception $e) {
            error_log("[SETTLEMENT] Failed to acknowledge settlement: " . $e->getMessage());
            return false;
        }
    }
    
    private function checkSettlementComplete(string $messageUuid): void
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as ack_count, 
                   (SELECT COUNT(*) FROM settlement_outbox WHERE message_uuid = ?) as expected_count
            FROM settlement_acknowledgements 
            WHERE message_uuid = ? AND ack_type = 'SETTLED'
        ");
        $stmt->execute([$messageUuid, $messageUuid]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && $result['ack_count'] >= 2) {
            $stmt = $this->db->prepare("UPDATE settlement_outbox SET status = 'COMPLETED' WHERE message_uuid = ?");
            $stmt->execute([$messageUuid]);
            error_log("[SETTLEMENT] Settlement $messageUuid fully completed by both parties");
        }
    }

    // ============================================================
    // HELPER METHODS
    // ============================================================
    
    /**
 * Actually delivers a settlement message to a participant institution,
 * via a simple webhook POST, instead of only logging locally.
 *
 * Deliberately fail-open: a delivery failure here must never throw back
 * into updateNetPosition()/invoiceFee() and block the swap or the fee
 * invoice from being recorded locally. The settlement_outbox row stays
 * PENDING (not SENT) on failure, so a future retry sweep can pick it up
 * -- see redeliverPendingSettlements() below.
 */
private function deliverToParticipant(string $institutionName, array $message): void
{
    $webhookUrl = $this->participants[$institutionName]['settlement_webhook_url']
        ?? $this->participants[strtoupper($institutionName)]['settlement_webhook_url']
        ?? null;

    if (!$webhookUrl) {
        error_log("[SETTLEMENT] No settlement_webhook_url configured for {$institutionName} -- message recorded locally only, not delivered.");
        return;
    }

    $payload = json_encode($message);
    $ch = curl_init($webhookUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $delivered = $httpCode >= 200 && $httpCode < 300 && !$curlError;

    if (!isset($message['instruction_id'])) {
        return; // nothing to key the per-recipient row on
    }

    $stmt = $this->db->prepare("
        INSERT INTO settlement_outbox_delivery
            (message_uuid, institution, status, delivery_attempts, last_delivery_error, sent_at)
        VALUES (:uuid, :institution, :status, 1, :error, CASE WHEN :status2 = 'SENT' THEN NOW() ELSE NULL END)
        ON CONFLICT (message_uuid, institution) DO UPDATE SET
            status = EXCLUDED.status,
            delivery_attempts = settlement_outbox_delivery.delivery_attempts + 1,
            last_delivery_error = EXCLUDED.last_delivery_error,
            sent_at = COALESCE(settlement_outbox_delivery.sent_at, EXCLUDED.sent_at)
    ");
    $stmt->execute([
        ':uuid' => $message['instruction_id'],
        ':institution' => $institutionName,
        ':status' => $delivered ? 'SENT' : 'PENDING',
        ':status2' => $delivered ? 'SENT' : 'PENDING',
        ':error' => $delivered ? null : ($curlError ?: "HTTP {$httpCode}"),
    ]);

    // Roll the parent settlement_outbox.status up to SENT only once BOTH
    // recipient rows report SENT — this replaces the old single-column write.
    $this->syncOutboxStatus($message['instruction_id']);
}

private function syncOutboxStatus(string $messageUuid): void
{
    $stmt = $this->db->prepare("
        SELECT COUNT(*) FILTER (WHERE status = 'SENT') AS sent_count, COUNT(*) AS total
        FROM settlement_outbox_delivery WHERE message_uuid = ?
    ");
    $stmt->execute([$messageUuid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && (int)$row['total'] > 0 && (int)$row['sent_count'] === (int)$row['total']) {
        $this->db->prepare("UPDATE settlement_outbox SET status = 'SENT', sent_at = NOW() WHERE message_uuid = ? AND status = 'PENDING'")
            ->execute([$messageUuid]);
    }
}

/**
 * Retry sweep for messages that failed delivery. Call this on a schedule
 * (same cron/worker pattern as settlement_confirmation_worker.php),
 * separately from the main swap flow.
 */
public function redeliverPendingSettlements(int $maxAttempts = 10, int $limit = 100): array
{
    $stmt = $this->db->prepare("
        SELECT so.message_uuid, so.message_payload, sod.institution
        FROM settlement_outbox so
        JOIN settlement_outbox_delivery sod ON sod.message_uuid = so.message_uuid
        WHERE sod.status = 'PENDING' AND sod.delivery_attempts < :max_attempts
        ORDER BY so.created_at ASC
        LIMIT :limit
    ");
    $stmt->bindValue(':max_attempts', $maxAttempts, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = ['attempted' => count($rows), 'delivered' => 0];
    foreach ($rows as $row) {
        $message = json_decode($row['message_payload'], true) ?? [];
        $message['instruction_id'] = $row['message_uuid'];
        $this->deliverToParticipant($row['institution'], $message);

        $check = $this->db->prepare("SELECT status FROM settlement_outbox_delivery WHERE message_uuid = ? AND institution = ?");
        $check->execute([$row['message_uuid'], $row['institution']]);
        if ($check->fetchColumn() === 'SENT') $results['delivered']++;
    }
    return $results;
}
    
    private function clearNetPosition(string $debtor, string $creditor, string $currency): void
    {
        $stmt = $this->db->prepare("DELETE FROM net_positions WHERE debtor = :debtor AND creditor = :creditor AND currency_code = :currency");
        $stmt->execute([':debtor' => $debtor, ':creditor' => $creditor, ':currency' => $currency]);
    }
    
    private function getTotalNetPositionAsDebtor(string $institution, string $currency): float
    {
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(amount), 0) FROM net_positions WHERE debtor = ? AND currency_code = ?");
        $stmt->execute([$institution, $currency]);
        return (float)($stmt->fetchColumn() ?? 0);
    }
    
    private function getTotalNetPositionAsCreditor(string $institution, string $currency): float
    {
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(amount), 0) FROM net_positions WHERE creditor = ? AND currency_code = ?");
        $stmt->execute([$institution, $currency]);
        return (float)($stmt->fetchColumn() ?? 0);
    }
    
    private function getAllNetPositions(): array
    {
        $stmt = $this->db->prepare("SELECT debtor, creditor, amount, currency_code FROM net_positions ORDER BY created_at");
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $positions = [];
        foreach ($results as $row) {
            $positions[$row['debtor']][$row['creditor']][$row['currency_code']] = (float)$row['amount'];
        }
        
        return $positions;
    }
    
    private function recordInternalTransfer(string $swapRef, string $fromAccount, string $toAccount, float $amount, string $currency, float $exchangeRate): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO corridor_settlement_ledger
            (transaction_uuid, swap_reference, source_country, destination_country,
             source_amount, source_currency, converted_amount, destination_currency,
             exchange_rate, source_vm_account, destination_vm_account, status, created_at)
            VALUES (gen_random_uuid(), ?, 
                    (SELECT country_code FROM vouchmorph_corridor_accounts WHERE account_number = ?),
                    (SELECT country_code FROM vouchmorph_corridor_accounts WHERE account_number = ?),
                    ?, ?, ?, ?, ?, ?, ?, 'COMPLETED', NOW())
        ");
        $stmt->execute([$swapRef, $fromAccount, $toAccount, $amount, $currency, $amount, $currency, $exchangeRate, $fromAccount, $toAccount]);
    }
    
    private function recordCorridorTransaction(string $swapRef, string $sourceCountry, string $destinationCountry, string $sourceInstitution, string $destinationInstitution, float $amount, string $currency, float $corridorFee, string $sourceVmAccount, string $destinationVmAccount): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO corridor_settlement_ledger
            (transaction_uuid, swap_reference, source_country, destination_country,
             source_amount, source_currency, converted_amount, destination_currency,
             exchange_rate, corridor_fee, source_vm_account, destination_vm_account, status, created_at)
            VALUES (gen_random_uuid(), ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, 'PENDING', NOW())
        ");
        $stmt->execute([$swapRef, $sourceCountry, $destinationCountry, $amount, $currency, $amount, $currency, $corridorFee, $sourceVmAccount, $destinationVmAccount]);
    }
    
    private function recordCrossBorderTransaction(
        string $swapRef, string $sourceCountry, string $destinationCountry,
        string $sourceInstitution, string $destinationInstitution,
        float $sourceAmount, string $sourceCurrency,
        float $destinationAmount, string $destinationCurrency,
        float $exchangeRate, float $corridorFee,
        string $sourceVmAccount, string $destinationVmAccount
    ): int {
        $stmt = $this->db->prepare("
            INSERT INTO cross_border_messages
            (message_uuid, swap_reference, source_country, destination_country,
             source_institution, destination_institution, amount, source_currency,
             destination_currency, exchange_rate, corridor_fee, status, created_at)
            VALUES (gen_random_uuid(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', NOW())
            RETURNING message_id
        ");
        $stmt->execute([$swapRef, $sourceCountry, $destinationCountry, $sourceInstitution, $destinationInstitution, $sourceAmount, $sourceCurrency, $destinationCurrency, $exchangeRate, $corridorFee]);
        return (int)$stmt->fetchColumn();
    }
    
    private function logObligation(string $source, string $dest, float $amount, string $currency, string $type, string $uuid): void
    {
        error_log("[SETTLEMENT_OBLIGATION] $source owes $dest $amount $currency for $type (Msg: $uuid)");
    }
    
    private function logEvent(string $event, array $data): void
    {
        $logEntry = json_encode(['timestamp' => date('c'), 'event' => $event, 'data' => $data]);
        file_put_contents('/tmp/settlement_audit.log', $logEntry . PHP_EOL, FILE_APPEND);
    }
    
    private function generateUuid(): string
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    
    private function generateReportId(): string
    {
        return 'REP_' . date('Ymd') . '_' . uniqid();
    }
}
