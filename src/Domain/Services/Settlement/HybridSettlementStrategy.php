<?php

declare(strict_types=1);

namespace Domain\Services\Settlement;

use PDO;
use DateTimeImmutable;
use Exception;

/**
 * Hybrid Settlement Strategy - NON-CUSTODIAL
 * 
 * VouchMorph NEVER holds customer funds.
 * VouchMorph ONLY:
 * 1. Orchestrates settlement messages between participants
 * 2. Tracks net positions for reconciliation
 * 3. Bills participants for fees into VouchMorph's operational account
 * 4. Routes cross-border settlements through VouchMorph corridor accounts
 * 
 * Fee Splitting & Retry Logic:
 * - First Attempt: Client pays full fee, unearned cashout fee stored for retry
 * - Free Retry (1st retry): VouchMorph pays generate code fee, cashout from unearned
 * - Paid Retry (2nd+): Client pays generate code fee, cashout from unearned
 */
class HybridSettlementStrategy
{
    private PDO $db;
    private string $defaultCurrency = 'BWP';
    private array $vouchmorphCorridorAccounts = [];
    
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

    public function __construct(PDO $db, array $vouchmorphCorridorAccounts = [])
    {
        $this->db = $db;
        $this->vouchmorphCorridorAccounts = $vouchmorphCorridorAccounts;
        $this->ensureMessageTablesExist();
    }
    
    /**
     * Ensure message tracking tables exist
     */
    private function ensureMessageTablesExist(): void
    {
        // Settlement messages outbox
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS settlement_outbox (
                message_id BIGSERIAL PRIMARY KEY,
                message_uuid UUID UNIQUE NOT NULL,
                swap_reference VARCHAR(100) NOT NULL,
                source_institution VARCHAR(100) NOT NULL,
                destination_institution VARCHAR(100) NOT NULL,
                amount NUMERIC(24,2) NOT NULL,
                currency CHAR(3) NOT NULL,
                message_type VARCHAR(50) NOT NULL,
                message_payload JSONB NOT NULL,
                status VARCHAR(20) DEFAULT 'PENDING',
                retry_count INT DEFAULT 0,
                sent_at TIMESTAMP,
                acknowledged_at TIMESTAMP,
                error_message TEXT,
                created_at TIMESTAMP DEFAULT NOW()
            )
        ");
        
        // Net position tracking
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS net_positions (
                id BIGSERIAL PRIMARY KEY,
                debtor VARCHAR(100) NOT NULL,
                creditor VARCHAR(100) NOT NULL,
                amount NUMERIC(24,2) NOT NULL,
                currency_code CHAR(3) NOT NULL,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW(),
                UNIQUE(debtor, creditor, currency_code)
            )
        ");
        
        // Fee invoices
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS fee_invoices (
                invoice_id BIGSERIAL PRIMARY KEY,
                invoice_uuid UUID UNIQUE NOT NULL,
                swap_reference VARCHAR(100) NOT NULL,
                participant_id BIGINT NOT NULL,
                source_institution VARCHAR(100) NOT NULL,
                fee_type VARCHAR(50) NOT NULL,
                fee_amount NUMERIC(12,2) NOT NULL,
                currency CHAR(3) NOT NULL,
                vat_amount NUMERIC(12,2) DEFAULT 0,
                total_amount NUMERIC(12,2) NOT NULL,
                status VARCHAR(20) DEFAULT 'SENT',
                paid_at TIMESTAMP,
                paid_reference VARCHAR(100),
                created_at TIMESTAMP DEFAULT NOW()
            )
        ");
        
        // Cashout retry tracking table
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS cashout_retry_tracking (
                id BIGSERIAL PRIMARY KEY,
                client_identifier VARCHAR(100) NOT NULL,
                original_swap_ref VARCHAR(100) NOT NULL,
                retry_count INT DEFAULT 0,
                unearned_cashout_fee NUMERIC(12,2) DEFAULT 0,
                used BOOLEAN DEFAULT FALSE,
                used_amount NUMERIC(12,2) DEFAULT 0,
                used_at TIMESTAMP,
                last_error TEXT,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW(),
                UNIQUE(client_identifier, original_swap_ref)
            )
        ");
        
        // Cross-border message routing
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS cross_border_messages (
                message_id BIGSERIAL PRIMARY KEY,
                message_uuid UUID UNIQUE NOT NULL,
                swap_reference VARCHAR(100) NOT NULL,
                source_country CHAR(2) NOT NULL,
                destination_country CHAR(2) NOT NULL,
                source_institution VARCHAR(100) NOT NULL,
                destination_institution VARCHAR(100) NOT NULL,
                amount NUMERIC(24,2) NOT NULL,
                source_currency CHAR(3) NOT NULL,
                destination_currency CHAR(3) NOT NULL,
                exchange_rate NUMERIC(24,10),
                fx_provider_id BIGINT,
                corridor_fee NUMERIC(12,2) DEFAULT 0,
                message_type VARCHAR(50),
                swift_reference VARCHAR(50),
                status VARCHAR(20) DEFAULT 'PENDING',
                created_at TIMESTAMP DEFAULT NOW()
            )
        ");
        
        // Corridor accounts table
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS vouchmorph_corridor_accounts (
                id BIGSERIAL PRIMARY KEY,
                country_code CHAR(2) NOT NULL,
                account_number VARCHAR(50) NOT NULL,
                account_name VARCHAR(100) NOT NULL,
                currency CHAR(3) NOT NULL,
                is_active BOOLEAN DEFAULT TRUE,
                balance NUMERIC(24,2) DEFAULT 0,
                last_reconciled_at TIMESTAMP,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW(),
                UNIQUE(country_code, currency)
            )
        ");
        
        // Settlement acknowledgements
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS settlement_acknowledgements (
                ack_id BIGSERIAL PRIMARY KEY,
                message_uuid UUID NOT NULL,
                swap_reference VARCHAR(100) NOT NULL,
                source_institution VARCHAR(100) NOT NULL,
                ack_type VARCHAR(20) NOT NULL,
                ack_payload JSONB,
                received_at TIMESTAMP DEFAULT NOW()
            )
        ");
        
        // Corridor settlement ledger
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS corridor_settlement_ledger (
                id BIGSERIAL PRIMARY KEY,
                transaction_uuid UUID NOT NULL,
                swap_reference VARCHAR(100) NOT NULL,
                source_country CHAR(2) NOT NULL,
                destination_country CHAR(2) NOT NULL,
                source_amount NUMERIC(24,2) NOT NULL,
                source_currency CHAR(3) NOT NULL,
                converted_amount NUMERIC(24,2) NOT NULL,
                destination_currency CHAR(3) NOT NULL,
                exchange_rate NUMERIC(24,10) NOT NULL,
                corridor_fee NUMERIC(12,2) DEFAULT 0,
                source_vm_account VARCHAR(50),
                destination_vm_account VARCHAR(50),
                status VARCHAR(20) DEFAULT 'PENDING',
                settled_at TIMESTAMP,
                created_at TIMESTAMP DEFAULT NOW()
            )
        ");
    }

    /**
     * UPDATE NET POSITION - Track who owes whom
     */
    public function updateNetPosition(
        string $swapRef,
        string $sourceInstitution, 
        string $destinationInstitution, 
        float $amount, 
        string $transactionType,
        string $currency = 'BWP'
    ): void {
        try {
            $this->updateNetPositionsTable($sourceInstitution, $destinationInstitution, $amount, $currency);
            
            $messageUuid = $this->sendSettlementInstruction(
                $swapRef, $sourceInstitution, $destinationInstitution, $amount, $currency, $transactionType
            );
            
            $this->logObligation($sourceInstitution, $destinationInstitution, $amount, $currency, $transactionType, $messageUuid);
            
            error_log("[SETTLEMENT] Obligation recorded for swap $swapRef: $sourceInstitution owes $destinationInstitution $amount $currency ($transactionType)");
            
        } catch (Exception $e) {
            error_log("[SETTLEMENT] Failed to update net position: " . $e->getMessage());
            throw $e;
        }
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
    
    /**
     * ============================================================
     * CASHOUT RETRY TRACKING METHODS
     * ============================================================
     */
    
    /**
     * Store unearned cashout fee for future retry
     */
    public function storeUnearnedCashoutFee(
        string $swapRef,
        string $clientIdentifier,
        float $unearnedFee,
        string $error
    ): void {
        $stmt = $this->db->prepare("
            INSERT INTO cashout_retry_tracking 
            (client_identifier, original_swap_ref, retry_count, unearned_cashout_fee, last_error, created_at)
            VALUES (:client, :swap_ref, 0, :fee, :error, NOW())
            ON CONFLICT (client_identifier, original_swap_ref) 
            DO UPDATE SET unearned_cashout_fee = EXCLUDED.unearned_cashout_fee, last_error = EXCLUDED.last_error
        ");
        
        $stmt->execute([
            ':client' => $clientIdentifier,
            ':swap_ref' => $swapRef,
            ':fee' => $unearnedFee,
            ':error' => $error
        ]);
        
        error_log("[SETTLEMENT] Unearned cashout fee stored: $unearnedFee for $swapRef");
    }
    
    /**
     * Get unearned cashout fee for retry
     */
    public function getUnearnedCashoutFee(string $originalSwapRef, string $clientIdentifier): float
    {
        $stmt = $this->db->prepare("
            SELECT unearned_cashout_fee FROM cashout_retry_tracking 
            WHERE original_swap_ref = :swap_ref AND client_identifier = :client AND used = false
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([':swap_ref' => $originalSwapRef, ':client' => $clientIdentifier]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (float)$result['unearned_cashout_fee'] : 0;
    }
    
    /**
     * Get retry count for original swap
     */
    public function getRetryCount(string $originalSwapRef, string $clientIdentifier): int
    {
        $stmt = $this->db->prepare("
            SELECT retry_count FROM cashout_retry_tracking 
            WHERE original_swap_ref = :swap_ref AND client_identifier = :client
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([':swap_ref' => $originalSwapRef, ':client' => $clientIdentifier]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (int)$result['retry_count'] : 0;
    }
    
    /**
     * Mark unearned fee as used
     */
    public function markUnearnedFeeAsUsed(string $originalSwapRef, string $clientIdentifier, float $amountUsed): void
    {
        $stmt = $this->db->prepare("
            UPDATE cashout_retry_tracking 
            SET used = true, used_amount = :amount, used_at = NOW(), updated_at = NOW()
            WHERE original_swap_ref = :swap_ref AND client_identifier = :client AND used = false
        ");
        
        $stmt->execute([
            ':swap_ref' => $originalSwapRef,
            ':client' => $clientIdentifier,
            ':amount' => $amountUsed
        ]);
        
        error_log("[SETTLEMENT] Unearned cashout fee marked as used: $amountUsed for $originalSwapRef");
    }
    
    /**
     * Update retry count on failure
     */
    public function updateRetryCount(string $originalSwapRef, string $clientIdentifier, string $error): void
    {
        $stmt = $this->db->prepare("
            UPDATE cashout_retry_tracking 
            SET retry_count = retry_count + 1, last_error = :error, updated_at = NOW()
            WHERE original_swap_ref = :swap_ref AND client_identifier = :client
        ");
        
        $stmt->execute([
            ':swap_ref' => $originalSwapRef,
            ':client' => $clientIdentifier,
            ':error' => $error
        ]);
        
        error_log("[SETTLEMENT] Retry count updated for $originalSwapRef, now at " . ($this->getRetryCount($originalSwapRef, $clientIdentifier) + 1));
    }
    
    /**
     * Check if free retry is available (first retry only)
     */
    public function isFreeRetryAvailable(string $originalSwapRef, string $clientIdentifier): bool
    {
        $retryCount = $this->getRetryCount($originalSwapRef, $clientIdentifier);
        $unearnedFee = $this->getUnearnedCashoutFee($originalSwapRef, $clientIdentifier);
        
        // Free retry available if:
        // 1. It's the first retry (retry_count == 0 means first attempt failed, retry_count == 1 means first retry)
        // 2. There's unearned cashout fee available
        return $retryCount === 0 && $unearnedFee > 0;
    }
    
    /**
     * ============================================================
     * FEE INVOICING METHODS
     * ============================================================
     */
    
    /**
     * Invoice participants for fees
     * 
     * Fee Types:
     * - VOUCHMORPH_FEE: Swap levy + platform share
     * - SOURCE_INSTITUTION_FEE: 15% of after-levy
     * - GENERATE_CODE_FEE: 10% of destination share (earned immediately)
     * - CASHOUT_COMPLETION_FEE: 90% of destination share (only on success)
     * - GENERATE_CODE_FEE_PAID_BY_VM: On free retry, VouchMorph pays
     * - GENERATE_CODE_FEE_PAID_BY_CLIENT: On paid retry, client pays
     * - CASHOUT_FEE_FROM_UNEARNED: From stored unearned fee
     * - CORRIDOR_FEE: Cross-border corridor fee
     */
    public function invoiceFee(
        string $swapReference,
        string $sourceInstitution,
        int $participantId,
        string $feeType,
        float $feeAmount,
        string $currency = 'BWP',
        float $vatRate = 0.14
    ): string {
        $invoiceUuid = $this->generateUuid();
        $vatAmount = $feeAmount * $vatRate;
        $totalAmount = $feeAmount + $vatAmount;
        
        $invoice = [
            'invoice_uuid' => $invoiceUuid,
            'swap_reference' => $swapReference,
            'fee_type' => $feeType,
            'fee_amount' => $feeAmount,
            'vat_rate' => $vatRate,
            'vat_amount' => $vatAmount,
            'total_amount' => $totalAmount,
            'currency' => $currency,
            'payee' => self::VOUCHMORPH_FEE_ACCOUNT,
            'payee_account' => self::VOUCHMORPH_FEE_ACCOUNT_NUMBER,
            'payment_instructions' => [
                'bank' => 'VouchMorph Operations Account',
                'account_name' => 'VouchMorph Pty Ltd',
                'account_number' => 'VM-FEE-001',
                'bank_code' => 'VM001',
                'reference' => $invoiceUuid,
                'notes' => 'Fee for swap transaction ' . $swapReference
            ],
            'due_date' => date('Y-m-d H:i:s', strtotime('+30 days')),
            'late_fee' => $totalAmount * 0.05
        ];
        
        $stmt = $this->db->prepare("
            INSERT INTO fee_invoices 
            (invoice_uuid, swap_reference, participant_id, source_institution, 
             fee_type, fee_amount, currency, vat_amount, total_amount, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'SENT', NOW())
        ");
        
        $stmt->execute([
            $invoiceUuid, $swapReference, $participantId, $sourceInstitution,
            $feeType, $feeAmount, $currency, $vatAmount, $totalAmount
        ]);
        
        $this->deliverToParticipant($sourceInstitution, $invoice);
        
        error_log("[SETTLEMENT] Fee invoice sent to $sourceInstitution: $totalAmount $currency for $feeType");
        
        return $invoiceUuid;
    }
    
    /**
     * Record fee payment received by VouchMorph
     */
    public function recordFeePayment(string $invoiceUuid, string $paymentReference): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE fee_invoices 
                SET status = 'PAID',
                    paid_at = NOW(),
                    paid_reference = ?
                WHERE invoice_uuid = ? AND status = 'SENT'
                RETURNING invoice_id
            ");
            
            $stmt->execute([$paymentReference, $invoiceUuid]);
            $updated = $stmt->fetchColumn();
            
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
     * ============================================================
     * CROSS-BORDER SETTLEMENT METHODS
     * ============================================================
     */
    
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
        $this->recordInternalTransfer($swapRef, $sourceAccount['account_name'], $destinationAccount['account_name'], $convertedAmount, $destinationCurrency, $exchangeRate);
        
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
    
    /**
     * ============================================================
     * NET POSITION & RECONCILIATION METHODS
     * ============================================================
     */
    
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
    
    public function getOutstandingInvoices(string $institutionName): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM fee_invoices 
            WHERE source_institution = ? AND status = 'SENT'
            ORDER BY created_at ASC
        ");
        $stmt->execute([$institutionName]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function generateReconciliationReport(string $institutionName, string $currency = 'BWP'): array
    {
        $netAsDebtor = $this->getTotalNetPositionAsDebtor($institutionName, $currency);
        $netAsCreditor = $this->getTotalNetPositionAsCreditor($institutionName, $currency);
        $netObligation = $netAsDebtor - $netAsCreditor;
        $pendingMessages = $this->getPendingMessagesForInstitution($institutionName);
        
        return [
            'institution' => $institutionName,
            'currency' => $currency,
            'as_at' => date('Y-m-d H:i:s'),
            'total_owed_to_others' => $netAsDebtor,
            'total_owed_by_others' => $netAsCreditor,
            'net_position' => $netObligation,
            'net_position_text' => $netObligation > 0 ? "OWES $netObligation $currency" : "IS OWED " . abs($netObligation) . " $currency",
            'pending_settlements' => count($pendingMessages),
            'pending_messages' => $pendingMessages
        ];
    }
    
    /**
     * ============================================================
     * CORRIDOR ACCOUNT METHODS
     * ============================================================
     */
    
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
    
    public function checkCorridorAccountBalance(string $countryCode, string $currency): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM vouchmorph_corridor_accounts
                WHERE country_code = ? AND currency = ? AND is_active = TRUE
                LIMIT 1
            ");
            $stmt->execute([$countryCode, $currency]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$account) {
                return ['error' => "No corridor account for {$countryCode}/{$currency}"];
            }
            
            $stmt = $this->db->prepare("
                SELECT 
                    COALESCE(SUM(CASE WHEN source_vm_account = ? THEN -source_amount ELSE 0 END), 0) +
                    COALESCE(SUM(CASE WHEN destination_vm_account = ? THEN converted_amount ELSE 0 END), 0) as ledger_balance
                FROM corridor_settlement_ledger
                WHERE source_vm_account = ? OR destination_vm_account = ?
            ");
            
            $stmt->execute([$account['account_number'], $account['account_number'], $account['account_number'], $account['account_number']]);
            $ledgerBalance = (float)$stmt->fetchColumn();
            
            return [
                'country' => $countryCode,
                'currency' => $currency,
                'account_number' => $account['account_number'],
                'account_name' => $account['account_name'],
                'recorded_balance' => (float)$account['balance'],
                'ledger_balance' => $ledgerBalance,
                'as_at' => date('Y-m-d H:i:s')
            ];
            
        } catch (Exception $e) {
            error_log("[SETTLEMENT] Failed to check corridor balance: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * ============================================================
     * HELPER METHODS
     * ============================================================
     */
    
    private function deliverToParticipant(string $institutionName, array $message): void
    {
        error_log("[SETTLEMENT] Message delivered to $institutionName: " . json_encode($message));
        
        if (isset($message['instruction_id'])) {
            $stmt = $this->db->prepare("
                UPDATE settlement_outbox 
                SET status = 'SENT', sent_at = NOW()
                WHERE message_uuid = ?
            ");
            $stmt->execute([$message['instruction_id']]);
        }
    }
    
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
    
    public function calculateNetObligations(array $participantBalances): array
    {
        $netObligations = [];
        $batchId = 'BATCH_' . bin2hex(random_bytes(8));
        
        foreach ($participantBalances as $debtor => $creditors) {
            foreach ($creditors as $creditor => $amounts) {
                foreach ($amounts as $currency => $amount) {
                    if ($amount <= 0.01) continue;
                    
                    $reverseAmount = $this->getNetPosition($creditor, $debtor, $currency);
                    
                    if ($reverseAmount > 0) {
                        $netAmount = abs($amount - $reverseAmount);
                        $netObligations[] = [
                            'batch_id' => $batchId,
                            'debtor' => $amount > $reverseAmount ? $debtor : $creditor,
                            'creditor' => $amount > $reverseAmount ? $creditor : $debtor,
                            'gross_amount' => $amount,
                            'reverse_amount' => $reverseAmount,
                            'net_amount' => $netAmount,
                            'currency' => $currency
                        ];
                        
                        $this->clearNetPosition($debtor, $creditor, $currency);
                        $this->clearNetPosition($creditor, $debtor, $currency);
                        
                        error_log("[SETTLEMENT] Net calculation: $debtor owes $creditor $amount $currency, net: $netAmount");
                    } else {
                        $netObligations[] = [
                            'batch_id' => $batchId,
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
        }
        
        return $netObligations;
    }
    
    private function updateNetPositionsTable(string $debtor, string $creditor, float $amount, string $currency): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO net_positions (debtor, creditor, amount, currency_code, created_at, updated_at)
            VALUES (:debtor, :creditor, :amount, :currency, NOW(), NOW())
            ON CONFLICT (debtor, creditor, currency_code) 
            DO UPDATE SET amount = net_positions.amount + :amount, updated_at = NOW()
        ");
        $stmt->execute([':debtor' => $debtor, ':creditor' => $creditor, ':amount' => $amount, ':currency' => $currency]);
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
    
    private function getInstitutionCountry(string $institution, array $participants): string
    {
        foreach ($participants as $participant) {
            if (strtolower($participant['name'] ?? '') === strtolower($institution) ||
                strtolower($participant['provider_code'] ?? '') === strtolower($institution)) {
                return $participant['country_code'] ?? 'BW';
            }
        }
        return 'BW';
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
}
