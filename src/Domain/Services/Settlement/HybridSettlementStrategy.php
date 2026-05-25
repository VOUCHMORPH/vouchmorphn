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
 * Real money movement happens directly between participants via:
 * - RTGS
 * - SWIFT  
 * - Central bank rails
 * - Correspondent banking
 * - VouchMorph corridor accounts (for cross-border)
 * 
 * VouchMorph's only account: Where financial institutions pay fees
 * 
 * TERMINOLOGY NOTE: This class uses source_institution and destination_institution
 * to align with SwapService terminology (not from_participant/to_participant)
 */
class HybridSettlementStrategy
{
    private PDO $db;
    private string $defaultCurrency = 'BWP';
    private array $vouchmorphCorridorAccounts = [];
    
    // VouchMorph's operational account (where fees are paid)
    private const VOUCHMORPH_FEE_ACCOUNT = 'VOUCHMORPH_OPERATIONS';
    private const VOUCHMORPH_FEE_ACCOUNT_NUMBER = 'VM-OP-001';
    private const VOUCHMORPH_CORRIDOR_PREFIX = 'VM-CB-';
    
    // Settlement statuses - messages only, not fund status
    private const STATUS_PENDING = 'PENDING';
    private const STATUS_SENT = 'SENT';
    private const STATUS_ACKNOWLEDGED = 'ACK';
    private const STATUS_COMPLETED = 'COMPLETED';
    private const STATUS_FAILED = 'FAILED';
    
    // Message types
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
        // Settlement messages outbox - messages to participants
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
        
        // Net position tracking - for reconciliation only
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
        
        // Fee invoices sent to participants
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
        
        // Settlement acknowledgements from participants
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
     * UPDATE NET POSITION - Track who owes whom (message-level only)
     * 
     * @param string $swapRef - The swap reference (from SwapService)
     * @param string $sourceInstitution - The institution sending funds (debtor)
     * @param string $destinationInstitution - The institution receiving funds (creditor)
     * @param float $amount - Amount
     * @param string $transactionType - Type of transaction
     * @param string $currency - Currency code
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
            // Update net positions table for reconciliation
            $this->updateNetPositionsTable($sourceInstitution, $destinationInstitution, $amount, $currency);
            
            // Send settlement instruction message to participants
            $messageUuid = $this->sendSettlementInstruction(
                $swapRef,
                $sourceInstitution,
                $destinationInstitution,
                $amount,
                $currency,
                $transactionType
            );
            
            // Log the obligation (not the fund movement)
            $this->logObligation($sourceInstitution, $destinationInstitution, $amount, $currency, $transactionType, $messageUuid);
            
            error_log("[SETTLEMENT] Obligation recorded for swap $swapRef: $sourceInstitution owes $destinationInstitution $amount $currency ($transactionType)");
            
        } catch (Exception $e) {
            error_log("[SETTLEMENT] Failed to update net position: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Send settlement instruction to participants
     * 
     * @param string $swapRef - The actual swap reference (CRITICAL for traceability)
     * @param string $sourceInstitution - Source institution name
     * @param string $destinationInstitution - Destination institution name
     * @param float $amount - Amount to settle
     * @param string $currency - Currency code
     * @param string $transactionType - Type of transaction
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
        
        // Construct settlement instruction message with REAL swap reference
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
        
        // Store in outbox with REAL swap reference
        $stmt = $this->db->prepare("
            INSERT INTO settlement_outbox 
            (message_uuid, swap_reference, source_institution, destination_institution, 
             amount, currency, message_type, message_payload, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', NOW())
        ");
        
        $stmt->execute([
            $messageUuid,
            $swapRef,
            $sourceInstitution,
            $destinationInstitution,
            $amount,
            $currency,
            self::MSG_SETTLEMENT_INSTRUCTION,
            json_encode($instruction)
        ]);
        
        // Trigger delivery to participants (webhook/queue)
        $this->deliverToParticipant($sourceInstitution, $instruction);
        $this->deliverToParticipant($destinationInstitution, $instruction);
        
        return $messageUuid;
    }
    
    /**
     * Process cross-border settlement through VouchMorph corridor accounts
     * 
     * Flow: Source Institution → VouchMorph (source country) → VouchMorph (destination country) → Destination Institution
     * 
     * @param string $swapRef Swap reference
     * @param string $sourceCountry Source country code (ISO 2)
     * @param string $destinationCountry Destination country code (ISO 2)
     * @param string $sourceInstitution Source institution name
     * @param string $destinationInstitution Destination institution name
     * @param float $amount Amount in source currency
     * @param string $sourceCurrency Source currency
     * @param string $destinationCurrency Destination currency
     * @param float $exchangeRate Exchange rate
     * @param float $corridorFee VouchMorph corridor service fee
     * @return array Settlement result
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
        
        // Get VouchMorph accounts for both countries
        $sourceAccount = $this->getVouchMorphCorridorAccount($sourceCountry, $sourceCurrency);
        $destinationAccount = $this->getVouchMorphCorridorAccount($destinationCountry, $destinationCurrency);
        
        if (!$sourceAccount || !$destinationAccount) {
            throw new Exception("VouchMorph corridor accounts not configured for {$sourceCountry}/{$destinationCountry}");
        }
        
        // Step 1: Source institution pays VouchMorph (source country account)
        $this->logEvent('CROSS_BORDER_STEP_1', [
            'step' => 'Source→VouchMorph',
            'from' => $sourceInstitution,
            'to' => $sourceAccount['account_name'],
            'amount' => $amount,
            'currency' => $sourceCurrency
        ]);
        
        $this->updateNetPosition(
            $swapRef,
            $sourceInstitution,
            $sourceAccount['account_name'],
            $amount,
            'cross_border_source_to_vm',
            $sourceCurrency
        );
        
        // Step 2: Record internal transfer between VouchMorph accounts
        $this->logEvent('CROSS_BORDER_STEP_2', [
            'step' => 'VouchMorph Internal Transfer',
            'from' => $sourceAccount['account_name'],
            'to' => $destinationAccount['account_name'],
            'amount' => $convertedAmount,
            'source_currency' => $sourceCurrency,
            'destination_currency' => $destinationCurrency,
            'rate' => $exchangeRate
        ]);
        
        $this->recordInternalTransfer(
            $swapRef,
            $sourceAccount['account_name'],
            $destinationAccount['account_name'],
            $convertedAmount,
            $destinationCurrency,
            $exchangeRate
        );
        
        // Step 3: VouchMorph (destination) pays destination institution
        $this->logEvent('CROSS_BORDER_STEP_3', [
            'step' => 'VouchMorph→Destination',
            'from' => $destinationAccount['account_name'],
            'to' => $destinationInstitution,
            'amount' => $convertedAmount,
            'currency' => $destinationCurrency
        ]);
        
        $this->updateNetPosition(
            $swapRef,
            $destinationAccount['account_name'],
            $destinationInstitution,
            $convertedAmount,
            'cross_border_vm_to_destination',
            $destinationCurrency
        );
        
        // Step 4: Charge corridor fee
        if ($corridorFee > 0) {
            $this->invoiceFee(
                $swapRef,
                $sourceInstitution,
                0,
                'CORRIDOR_FEE',
                $corridorFee,
                $sourceCurrency
            );
        }
        
        // Record the cross-border transaction
        $transactionId = $this->recordCrossBorderTransaction(
            $swapRef,
            $sourceCountry,
            $destinationCountry,
            $sourceInstitution,
            $destinationInstitution,
            $amount,
            $sourceCurrency,
            $convertedAmount,
            $destinationCurrency,
            $exchangeRate,
            $corridorFee,
            $sourceAccount['account_number'],
            $destinationAccount['account_number']
        );
        
        $this->logEvent('CROSS_BORDER_COMPLETE', [
            'swap_ref' => $swapRef,
            'transaction_id' => $transactionId,
            'original_amount' => $amount,
            'converted_amount' => $convertedAmount,
            'corridor_fee' => $corridorFee
        ]);
        
        return [
            'success' => true,
            'transaction_id' => $transactionId,
            'source_amount' => $amount,
            'source_currency' => $sourceCurrency,
            'destination_amount' => $convertedAmount,
            'destination_currency' => $destinationCurrency,
            'exchange_rate' => $exchangeRate,
            'corridor_fee' => $corridorFee,
            'source_vm_account' => $sourceAccount['account_number'],
            'destination_vm_account' => $destinationAccount['account_number']
        ];
    }
    
    /**
     * Process corridor settlement (simplified version for direct corridor routing)
     * 
     * @param string $swapRef Swap reference
     * @param string $sourceInstitution Source institution
     * @param string $destinationInstitution Destination institution
     * @param string $sourceCountry Source country
     * @param string $destinationCountry Destination country
     * @param float $amount Amount
     * @param string $currency Currency
     * @param float $corridorFee Corridor fee percentage or amount
     * @return array Corridor routing result
     */
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
        
        // Get VouchMorph corridor accounts
        $vmSourceAccount = $this->getVouchMorphCorridorAccount($sourceCountry, $currency);
        $vmDestAccount = $this->getVouchMorphCorridorAccount($destinationCountry, $currency);
        
        if (!$vmSourceAccount || !$vmDestAccount) {
            // Fall back to direct settlement if corridor not configured
            $this->updateNetPosition($swapRef, $sourceInstitution, $destinationInstitution, $amount, 'direct_settlement', $currency);
            return [
                'method' => 'direct',
                'corridor_fee' => 0,
                'message' => 'Direct settlement used (corridor not configured)'
            ];
        }
        
        // Route: Source → VouchMorph (source) → VouchMorph (dest) → Destination
        $this->updateNetPosition($swapRef, $sourceInstitution, $vmSourceAccount['account_name'], $amount, 'corridor_inbound', $currency);
        $this->updateNetPosition($swapRef, $vmDestAccount['account_name'], $destinationInstitution, $amount, 'corridor_outbound', $currency);
        
        // Apply corridor fee if any
        $actualCorridorFee = 0;
        if ($corridorFee > 0) {
            if ($corridorFee < 1) {
                $actualCorridorFee = $amount * $corridorFee;
            } else {
                $actualCorridorFee = $corridorFee;
            }
            
            $this->invoiceFee(
                $swapRef,
                $sourceInstitution,
                0,
                'CORRIDOR_FEE',
                $actualCorridorFee,
                $currency
            );
        }
        
        // Record corridor transaction
        $this->recordCorridorTransaction(
            $swapRef,
            $sourceCountry,
            $destinationCountry,
            $sourceInstitution,
            $destinationInstitution,
            $amount,
            $currency,
            $actualCorridorFee,
            $vmSourceAccount['account_number'],
            $vmDestAccount['account_number']
        );
        
        return [
            'method' => 'corridor',
            'corridor_fee' => $actualCorridorFee,
            'source_vm_account' => $vmSourceAccount['account_number'],
            'destination_vm_account' => $vmDestAccount['account_number'],
            'source_country' => $sourceCountry,
            'destination_country' => $destinationCountry
        ];
    }
    
    /**
     * Get VouchMorph corridor account for a country
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
    
    /**
     * Register a VouchMorph corridor account
     */
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
    
    /**
     * Check balance of a VouchMorph corridor account
     */
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
            
            $stmt->execute([
                $account['account_number'],
                $account['account_number'],
                $account['account_number'],
                $account['account_number']
            ]);
            
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
     * Update corridor account balance (for reconciliation)
     */
    public function updateCorridorAccountBalance(string $countryCode, string $currency, float $newBalance): void
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE vouchmorph_corridor_accounts 
                SET balance = ?, last_reconciled_at = NOW(), updated_at = NOW()
                WHERE country_code = ? AND currency = ?
            ");
            $stmt->execute([$newBalance, $countryCode, $currency]);
        } catch (Exception $e) {
            error_log("[SETTLEMENT] Failed to update corridor balance: " . $e->getMessage());
        }
    }
    
    /**
     * Check if a settlement needs cross-border routing
     */
    public function needsCrossBorder(string $sourceInstitution, string $destinationInstitution, array $participants): bool
    {
        $sourceCountry = $this->getInstitutionCountry($sourceInstitution, $participants);
        $destinationCountry = $this->getInstitutionCountry($destinationInstitution, $participants);
        
        return $sourceCountry !== $destinationCountry;
    }
    
    /**
     * Get institution country from participants config
     */
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
    
    /**
     * Record internal transfer between VouchMorph accounts
     */
    private function recordInternalTransfer(
        string $swapRef,
        string $fromAccount,
        string $toAccount,
        float $amount,
        string $currency,
        float $exchangeRate
    ): void {
        try {
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
            
            $stmt->execute([
                $swapRef,
                $fromAccount,
                $toAccount,
                $amount,
                $currency,
                $amount,
                $currency,
                $exchangeRate,
                $fromAccount,
                $toAccount
            ]);
        } catch (Exception $e) {
            error_log("[SETTLEMENT] Failed to record internal transfer: " . $e->getMessage());
        }
    }
    
    /**
     * Record corridor transaction
     */
    private function recordCorridorTransaction(
        string $swapRef,
        string $sourceCountry,
        string $destinationCountry,
        string $sourceInstitution,
        string $destinationInstitution,
        float $amount,
        string $currency,
        float $corridorFee,
        string $sourceVmAccount,
        string $destinationVmAccount
    ): void {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO corridor_settlement_ledger
                (transaction_uuid, swap_reference, source_country, destination_country,
                 source_amount, source_currency, converted_amount, destination_currency,
                 exchange_rate, corridor_fee, source_vm_account, destination_vm_account, status, created_at)
                VALUES (gen_random_uuid(), ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, 'PENDING', NOW())
            ");
            
            $stmt->execute([
                $swapRef,
                $sourceCountry,
                $destinationCountry,
                $amount,
                $currency,
                $amount,
                $currency,
                $corridorFee,
                $sourceVmAccount,
                $destinationVmAccount
            ]);
        } catch (Exception $e) {
            error_log("[SETTLEMENT] Failed to record corridor transaction: " . $e->getMessage());
        }
    }
    
    /**
     * Record cross-border transaction
     */
    private function recordCrossBorderTransaction(
        string $swapRef,
        string $sourceCountry,
        string $destinationCountry,
        string $sourceInstitution,
        string $destinationInstitution,
        float $sourceAmount,
        string $sourceCurrency,
        float $destinationAmount,
        string $destinationCurrency,
        float $exchangeRate,
        float $corridorFee,
        string $sourceVmAccount,
        string $destinationVmAccount
    ): int {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO cross_border_messages
                (message_uuid, swap_reference, source_country, destination_country,
                 source_institution, destination_institution, amount, source_currency,
                 destination_currency, exchange_rate, corridor_fee, status, created_at)
                VALUES (gen_random_uuid(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', NOW())
                RETURNING message_id
            ");
            
            $stmt->execute([
                $swapRef,
                $sourceCountry,
                $destinationCountry,
                $sourceInstitution,
                $destinationInstitution,
                $sourceAmount,
                $sourceCurrency,
                $destinationCurrency,
                $exchangeRate,
                $corridorFee
            ]);
            
            return (int)$stmt->fetchColumn();
            
        } catch (Exception $e) {
            error_log("[SETTLEMENT] Failed to record cross-border transaction: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Invoice participants for fees
     * This is the ONLY money that moves to VouchMorph
     * 
     * @param string $swapReference The swap reference
     * @param string $sourceInstitution The institution being invoiced
     * @param int $participantId Participant ID (can be 0 if not available)
     * @param string $feeType Type of fee
     * @param float $feeAmount Amount of fee
     * @param string $currency Currency code
     * @param float $vatRate VAT rate (default 0.14)
     * @return string Invoice UUID
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
            $invoiceUuid,
            $swapReference,
            $participantId,
            $sourceInstitution,
            $feeType,
            $feeAmount,
            $currency,
            $vatAmount,
            $totalAmount
        ]);
        
        // Send invoice to participant
        $this->deliverToParticipant($sourceInstitution, $invoice);
        
        error_log("[SETTLEMENT] Fee invoice sent to $sourceInstitution: $totalAmount $currency");
        
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
     * Deliver message to participant via webhook/queue
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
    
    /**
     * Acknowledge settlement from participant
     */
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
    
    /**
     * Check if both parties have acknowledged settlement
     */
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
            $stmt = $this->db->prepare("
                UPDATE settlement_outbox 
                SET status = 'COMPLETED'
                WHERE message_uuid = ?
            ");
            $stmt->execute([$messageUuid]);
            
            error_log("[SETTLEMENT] Settlement $messageUuid fully completed by both parties");
        }
    }
    
    /**
     * Log obligation for audit trail
     */
    private function logObligation(
        string $sourceInstitution,
        string $destinationInstitution,
        float $amount,
        string $currency,
        string $transactionType,
        string $messageUuid
    ): void {
        // Obligation logging - can be extended as needed
        error_log("[SETTLEMENT_OBLIGATION] $sourceInstitution owes $destinationInstitution $amount $currency for $transactionType (Msg: $messageUuid)");
    }
    
    /**
     * Calculate net obligations between participants
     */
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
                        
                        error_log("[SETTLEMENT] Net calculation: $debtor owes $creditor $amount $currency, reverse $reverseAmount, net: $netAmount");
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
    
    /**
     * Get net position with currency support
     */
    public function getNetPosition(string $debtor, string $creditor, string $currency = 'BWP'): float
    {
        try {
            $stmt = $this->db->prepare("
                SELECT amount FROM net_positions
                WHERE debtor = :debtor AND creditor = :creditor AND currency_code = :currency
            ");
            $stmt->execute([
                ':debtor' => $debtor,
                ':creditor' => $creditor,
                ':currency' => $currency
            ]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? (float)$result['amount'] : 0.0;
            
        } catch (Exception $e) {
            error_log("[SETTLEMENT] Failed to get net position: " . $e->getMessage());
            return 0.0;
        }
    }
    
    /**
     * Update net positions table
     */
    private function updateNetPositionsTable(
        string $debtorInstitution,
        string $creditorInstitution,
        float $amount,
        string $currency
    ): void {
        try {
            $checkStmt = $this->db->prepare("
                SELECT amount FROM net_positions 
                WHERE debtor = :debtor AND creditor = :creditor AND currency_code = :currency
            ");
            $checkStmt->execute([
                ':debtor' => $debtorInstitution,
                ':creditor' => $creditorInstitution,
                ':currency' => $currency
            ]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                $stmt = $this->db->prepare("
                    UPDATE net_positions 
                    SET amount = amount + :amount,
                        updated_at = NOW()
                    WHERE debtor = :debtor AND creditor = :creditor AND currency_code = :currency
                ");
                $stmt->execute([
                    ':debtor' => $debtorInstitution,
                    ':creditor' => $creditorInstitution,
                    ':amount' => $amount,
                    ':currency' => $currency
                ]);
            } else {
                $stmt = $this->db->prepare("
                    INSERT INTO net_positions 
                        (debtor, creditor, amount, currency_code, created_at, updated_at)
                    VALUES 
                        (:debtor, :creditor, :amount, :currency, NOW(), NOW())
                ");
                $stmt->execute([
                    ':debtor' => $debtorInstitution,
                    ':creditor' => $creditorInstitution,
                    ':amount' => $amount,
                    ':currency' => $currency
                ]);
            }
            
        } catch (Exception $e) {
            error_log("[SETTLEMENT] Failed to update net_positions table: " . $e->getMessage());
        }
    }
    
    /**
     * Clear net position after settlement
     */
    private function clearNetPosition(string $debtor, string $creditor, string $currency): void
    {
        $stmt = $this->db->prepare("
            DELETE FROM net_positions
            WHERE debtor = :debtor AND creditor = :creditor AND currency_code = :currency
        ");
        
        $stmt->execute([
            ':debtor' => $debtor,
            ':creditor' => $creditor,
            ':currency' => $currency
        ]);
    }
    
    /**
     * Generate UUID
     */
    private function generateUuid(): string
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    
    /**
     * Log settlement events
     */
    private function logEvent(string $event, array $data): void
    {
        $logEntry = json_encode([
            'timestamp' => date('c'),
            'event' => $event,
            'data' => $data
        ]);
        file_put_contents('/tmp/settlement_audit.log', $logEntry . PHP_EOL, FILE_APPEND);
    }

    /**
     * Get pending settlement messages for an institution
     */
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
    
    /**
     * Get outstanding fee invoices for an institution
     */
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
    
    /**
     * Generate reconciliation report for an institution
     */
    public function generateReconciliationReport(string $institutionName, string $currency = 'BWP'): array
    {
        $netAsDebtor = $this->getTotalNetPositionAsDebtor($institutionName, $currency);
        $netAsCreditor = $this->getTotalNetPositionAsCreditor($institutionName, $currency);
        
        $netObligation = $netAsDebtor - $netAsCreditor;
        
        $pendingMessages = $this->getPendingMessagesForInstitution($institutionName);
        $corridorActivity = $this->getCorridorActivityForInstitution($institutionName);
        
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
            'corridor_activity' => $corridorActivity
        ];
    }
    
    /**
     * Get corridor activity for an institution
     */
    private function getCorridorActivityForInstitution(string $institutionName): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    source_country,
                    destination_country,
                    COUNT(*) as transaction_count,
                    SUM(source_amount) as total_source_amount,
                    SUM(converted_amount) as total_destination_amount
                FROM corridor_settlement_ledger
                WHERE source_vm_account IN (SELECT account_number FROM vouchmorph_corridor_accounts)
                AND created_at >= NOW() - INTERVAL '30 days'
                GROUP BY source_country, destination_country
            ");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("[SETTLEMENT] Failed to get corridor activity: " . $e->getMessage());
            return [];
        }
    }
    
    private function getTotalNetPositionAsDebtor(string $institution, string $currency): float
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount), 0) as total 
            FROM net_positions 
            WHERE debtor = ? AND currency_code = ?
        ");
        $stmt->execute([$institution, $currency]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (float)($result['total'] ?? 0);
    }
    
    private function getTotalNetPositionAsCreditor(string $institution, string $currency): float
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount), 0) as total 
            FROM net_positions 
            WHERE creditor = ? AND currency_code = ?
        ");
        $stmt->execute([$institution, $currency]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (float)($result['total'] ?? 0);
    }
    
    /**
     * Get all VouchMorph corridor accounts
     */
    public function getAllCorridorAccounts(): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM vouchmorph_corridor_accounts WHERE is_active = TRUE
            ");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("[SETTLEMENT] Failed to get corridor accounts: " . $e->getMessage());
            return [];
        }
    }
}
