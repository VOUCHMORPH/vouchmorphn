<?php
declare(strict_types=1);

namespace Domain\Services;

require_once __DIR__ . '/../../Infrastructure/Cards/CardNumberGenerator.php';
require_once __DIR__ . '/../Helpers/CardHelper.php';

use PDO;
use Exception;
use DateTimeImmutable;
use RuntimeException;
use Domain\Helpers\CardHelper;
use Domain\Services\FeeService;
use Domain\Services\ForexService;
use Domain\Services\SwapService;
use Domain\Services\Settlement\HybridSettlementStrategy;
use Domain\Services\ContributionCalculator;
use Infrastructure\Cards\CardNumberGenerator;
use Core\Config\AssetTypeRegistry;
use Security\Encryption\KeyVault;
use PragmaRX\Google2FA\Google2FA;

/**
 * CardService - Message Card Management
 * Handles issuance, authorization, and lifecycle of message-based cards
 * 
 * FEE/FOREX INTEGRATION:
 * - Card issuance fees are calculated via FeeService
 * - Card load fees (when funding from a swap) use the same fee structure
 * - Forex conversion for cross-currency card loads
 * 
 * PCI-DSS COMPLIANCE FIXES:
 * - CVV is NEVER stored (replaced with TOTP dynamic code)
 * - PAN is hashed with HMAC-SHA256 (not bare SHA-256)
 * - PIN/CVV material is never logged in plaintext
 * - TOTP secrets are encrypted with AES-256-GCM using KeyVault
 * - TOTP secrets are NEVER returned after issuance (one-time reveal only)
 */
class CardService
{
    private PDO $db;
    private string $countryCode;
    private array $config;
    private CardNumberGenerator $cardGenerator;
    private ?FeeService $feeService = null;
    private ?ForexService $forexService = null;
    private ?array $feeCalculationDetails = null;
    private ?string $panHmacKey = null;
    
    // Card constants
    private const CARD_EXPIRY_YEARS = 3;
    private const DAILY_SPEND_LIMIT = 10000;
    private const MONTHLY_SPEND_LIMIT = 50000;
    private const ATM_DAILY_LIMIT = 2000;
    private const POS_MAX_TRANSACTION = 5000;
    
    public function __construct(
        PDO $db, 
        string $countryCode, 
        array $config,
        ?FeeService $feeService = null,
        ?ForexService $forexService = null
    ) {
        $this->db = $db;
        $this->countryCode = $countryCode;
        $this->config = $config;
        $this->cardGenerator = new CardNumberGenerator($config);
        $this->feeService = $feeService;
        $this->forexService = $forexService;
        
        // ============================================================
        // FIXED: PAN HMAC key from KeyVault - NO HARDCODED FALLBACK
        // ============================================================
        try {
            $keyVault = KeyVault::getInstance();
            $this->panHmacKey = $keyVault->getKey('pan_hmac_key') ?? getenv('PAN_HMAC_KEY');
        } catch (Exception $e) {
            $this->panHmacKey = getenv('PAN_HMAC_KEY');
        }
        
        if (empty($this->panHmacKey) || strlen($this->panHmacKey) < 32) {
            throw new RuntimeException(
                'PAN HMAC key is missing or too short (min 32 bytes required). ' .
                'CardService cannot start without a real key - refusing to fall back to a hardcoded default.'
            );
        }
    }
    
    /**
     * Hash a PAN for lookup using HMAC-SHA256 (PCI-DSS compliant)
     * Replaces bare hash('sha256', $cardNumber) across all 4 call sites
     */
    private function hashPan(string $cardNumber): string
    {
        $cleanPan = preg_replace('/\D/', '', $cardNumber);
        return hash_hmac('sha256', $cleanPan, $this->panHmacKey);
    }
    
    // ============================================================
    // TOTP DYNAMIC CODE - REPLACES STATIC CVV
    // ============================================================
    
    /**
     * Encrypt a TOTP secret at rest using KeyVault's master encryption key.
     * Uses AES-256-GCM directly rather than routing through HSMKeyManager,
     * since HSMKeyManager's software fallback does not persist keys across
     * requests.
     */
    private function encryptTotpSecret(string $secret): array
    {
        $keyVault = KeyVault::getInstance();
        $key = $keyVault->getEncryptionKey();
        
        if (empty($key) || strlen($key) < 32) {
            throw new RuntimeException('Encryption key missing or too short');
        }
        
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $secret,
            'aes-256-gcm',
            substr(hash('sha256', $key, true), 0, 32),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        
        if ($ciphertext === false) {
            throw new RuntimeException('Failed to encrypt TOTP secret');
        }
        
        return [
            'ciphertext' => base64_encode($ciphertext),
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
        ];
    }
    
    /**
     * Decrypt a TOTP secret from storage.
     */
    private function decryptTotpSecret(string $ciphertext, string $iv, string $tag): string
    {
        $keyVault = KeyVault::getInstance();
        $key = $keyVault->getEncryptionKey();
        
        if (empty($key) || strlen($key) < 32) {
            throw new RuntimeException('Encryption key missing or too short');
        }
        
        $plaintext = openssl_decrypt(
            base64_decode($ciphertext),
            'aes-256-gcm',
            substr(hash('sha256', $key, true), 0, 32),
            OPENSSL_RAW_DATA,
            base64_decode($iv),
            base64_decode($tag)
        );
        
        if ($plaintext === false) {
            throw new RuntimeException('Failed to decrypt TOTP secret - key mismatch or data corruption');
        }
        
        return $plaintext;
    }
    
    /**
     * Verify a dynamic code the app displayed against the card's stored secret.
     * Replaces static CVV verification entirely.
     */
    public function verifyDynamicCode(string $cardSuffix, string $providedCode): bool
    {
        $stmt = $this->db->prepare("
            SELECT totp_secret_encrypted, totp_secret_iv, totp_secret_tag
            FROM message_cards WHERE card_suffix = ? AND status = 'ACTIVE'
        ");
        $stmt->execute([$cardSuffix]);
        $card = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$card || empty($card['totp_secret_encrypted'])) {
            return false;
        }
        
        try {
            $secret = $this->decryptTotpSecret(
                $card['totp_secret_encrypted'],
                $card['totp_secret_iv'],
                $card['totp_secret_tag']
            );
            
            $google2fa = new Google2FA();
            return $google2fa->verifyKey($secret, $providedCode, 1); // 1 window of drift tolerance
        } catch (Exception $e) {
            error_log("[CardService] TOTP verification failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Authorize a card load (for message-based cards)
     * 
     * FIXED: Now calculates fees and forex if services are available
     */
    public function authorizeCardLoad(array $data): array
    {
        error_log("[CardService] authorizeCardLoad called with: " . json_encode([
            'hold_reference' => $data['hold_reference'] ?? null,
            'card_suffix' => $data['card_suffix'] ?? null,
            'amount' => $data['amount'] ?? null
        ]));
        
        try {
            if (empty($data['hold_reference'])) {
                throw new RuntimeException("hold_reference is required for card authorization");
            }
            if (empty($data['card_suffix'])) {
                throw new RuntimeException("card_suffix is required for card authorization");
            }
            if (empty($data['amount']) || $data['amount'] <= 0) {
                throw new RuntimeException("Valid amount is required for card authorization");
            }
            
            // Calculate fees and forex for this card load
            $feeResult = $this->calculateCardFees($data);
            
            $loadData = [
                'hold_reference' => $data['hold_reference'],
                'swap_reference' => $data['swap_reference'] ?? null,
                'card_suffix' => $data['card_suffix'],
                'amount' => $data['amount'],
                'fee_amount' => $feeResult['total_fee'] ?? 0,
                'forex_applied' => $feeResult['forex_applied'] ?? false,
                'exchange_rate' => $feeResult['exchange_rate'] ?? 1.0
            ];
            
            $result = $this->loadCard($loadData);
            
            $result['authorized'] = true;
            $result['authorization_id'] = $result['card_id'] ?? null;
            $result['authorized_amount'] = $data['amount'];
            $result['remaining_balance'] = $result['new_balance'] ?? $data['amount'];
            $result['status'] = 'AUTHORIZED';
            $result['fee_breakdown'] = $feeResult;
            
            error_log("[CardService] authorizeCardLoad successful: " . json_encode([
                'card_suffix' => $data['card_suffix'],
                'amount' => $data['amount'],
                'fee' => $feeResult['total_fee'] ?? 0,
                'status' => $result['status']
            ]));
            
            return $result;
            
        } catch (Exception $e) {
            error_log("[CardService] authorizeCardLoad failed: " . $e->getMessage());
            
            return [
                'success' => false,
                'authorized' => false,
                'error' => $e->getMessage(),
                'message' => 'Card authorization failed: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Issue a new message card from a hold
     * 
     * FIXED: Removed cvv_hash storage (PCI-DSS violation)
     * FIXED: Uses HMAC-SHA256 for PAN hashing
     * FIXED: Now applies card issuance fees
     * FIXED: Generates TOTP secret for dynamic code verification
     */
    public function issueCard(array $data): array
    {
        $this->db->beginTransaction();
        
        try {
            if (empty($data['hold_reference'])) {
                throw new RuntimeException("hold_reference is required");
            }
            
            $holdStmt = $this->db->prepare("
                SELECT ht.*, sr.swap_uuid, sr.source_details 
                FROM hold_transactions ht
                LEFT JOIN swap_requests sr ON ht.swap_reference = sr.swap_uuid
                WHERE ht.hold_reference = ? 
                AND ht.status = 'ACTIVE'
            ");
            $holdStmt->execute([$data['hold_reference']]);
            $hold = $holdStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$hold) {
                throw new RuntimeException("Hold not found or not active");
            }
            
            $initialAmount = $data['initial_amount'] ?? (float)$hold['amount'];
            
            if ($initialAmount <= 0) {
                throw new RuntimeException("Initial amount must be positive");
            }
            
            if ($initialAmount > (float)$hold['amount']) {
                throw new RuntimeException("Initial amount exceeds hold amount");
            }
            
            // Calculate card issuance fee
            $feeResult = $this->calculateCardFees([
                'amount' => $initialAmount,
                'currency' => $hold['currency'] ?? 'BWP',
                'fee_type' => 'CARD_ISSUE',
                'purpose' => $data['purpose'] ?? 'student'
            ]);
            
            $totalFee = $feeResult['total_fee'] ?? 0;
            $netAmount = $initialAmount - $totalFee;
            
            if ($netAmount <= 0) {
                throw new RuntimeException("Amount after fees ({$netAmount}) is too small to issue card");
            }
            
            $purpose = $data['purpose'] ?? 'student';
            $cardDetails = $this->cardGenerator->generateForPurpose($purpose);
            
            $userId = $this->getOrCreateUser($data);
            
            // ============================================================
            // GENERATE TOTP SECRET - replaces CVV
            // ============================================================
            $google2fa = new Google2FA();
            $totpSecret = $google2fa->generateSecretKey();
            $encryptedSecret = $this->encryptTotpSecret($totpSecret);
            
            // PCI-DSS COMPLIANCE: cvv_hash REMOVED - replaced with TOTP secret
            $cardStmt = $this->db->prepare("
                INSERT INTO message_cards (
                    card_number_hash,
                    card_suffix,
                    hold_reference,
                    swap_reference,
                    user_id,
                    cardholder_name,
                    initial_amount,
                    remaining_amount,
                    currency,
                    status,
                    issued_at,
                    expiry_year,
                    expiry_month,
                    daily_limit,
                    monthly_limit,
                    atm_daily_limit,
                    fee_amount,
                    metadata,
                    totp_secret_encrypted,
                    totp_secret_iv,
                    totp_secret_tag
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVE', NOW(), ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?)
                RETURNING card_id
            ");
            
            $cardStmt->execute([
                $this->hashPan($cardDetails['pan_formatted']), // HMAC-SHA256 PAN hash
                $cardDetails['pan_suffix'],
                $hold['hold_reference'],
                $hold['swap_reference'] ?? null,
                $userId,
                $data['cardholder_name'] ?? 'Cardholder',
                $netAmount,
                $netAmount,
                $hold['currency'] ?? 'BWP',
                $cardDetails['expiry_year'],
                $cardDetails['expiry_month'],
                $data['daily_limit'] ?? self::DAILY_SPEND_LIMIT,
                $data['monthly_limit'] ?? self::MONTHLY_SPEND_LIMIT,
                $data['atm_daily_limit'] ?? self::ATM_DAILY_LIMIT,
                $totalFee,
                json_encode([
                    'source_institution' => $hold['source_institution'] ?? 'unknown',
                    'purpose' => $purpose,
                    'issued_by' => $data['issued_by'] ?? 'system',
                    'notes' => $data['notes'] ?? null,
                    'fee_breakdown' => $feeResult,
                    'original_amount' => $initialAmount,
                    'currency' => $hold['currency'] ?? 'BWP'
                ]),
                $encryptedSecret['ciphertext'],
                $encryptedSecret['iv'],
                $encryptedSecret['tag'],
            ]);
            
            $cardId = $cardStmt->fetchColumn();
            
            if ($initialAmount < (float)$hold['amount']) {
                $this->splitHold($hold['hold_reference'], $initialAmount);
            }
            
            $this->logTransaction([
                'card_id' => $cardId,
                'type' => 'ISSUANCE',
                'amount' => $netAmount,
                'fee_amount' => $totalFee,
                'auth_code' => CardHelper::generateAuthCode(),
                'reference' => $hold['hold_reference'],
                'channel' => 'ISSUANCE'
            ]);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'card_id' => $cardId,
                'card_number' => $cardDetails['pan_formatted'],
                'card_suffix' => $cardDetails['pan_suffix'],
                'expiry' => $cardDetails['expiry_formatted'],
                'expiry_month' => $cardDetails['expiry_month'],
                'expiry_year' => $cardDetails['expiry_year'],
                'cardholder_name' => $data['cardholder_name'] ?? 'Cardholder',
                'brand' => $cardDetails['brand'],
                'initial_amount' => $initialAmount,
                'fee_amount' => $totalFee,
                'net_amount' => $netAmount,
                'remaining_amount' => $netAmount,
                'currency' => $hold['currency'] ?? 'BWP',
                'fee_breakdown' => $feeResult,
                'totp_secret' => $totpSecret,  // SHOWN ONCE at issuance, never retrievable again
                'message' => 'Card issued successfully'
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("[CARD] Issue failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Load funds onto an existing card (link hold to card)
     * 
     * FIXED: Now applies fees and forex on card loads
     */
    public function loadCard(array $data): array
    {
        try {
            if (empty($data['hold_reference'])) {
                throw new RuntimeException("hold_reference is required");
            }
            if (empty($data['card_suffix'])) {
                throw new RuntimeException("card_suffix is required");
            }
            if (empty($data['amount']) || $data['amount'] <= 0) {
                throw new RuntimeException("Valid amount is required");
            }
            
            $cardStmt = $this->db->prepare("
                SELECT * FROM message_cards 
                WHERE card_suffix = :suffix 
                AND status IN ('ASSIGNED', 'DELIVERED', 'ACTIVE')
                FOR UPDATE
            ");
            $cardStmt->execute([':suffix' => $data['card_suffix']]);
            $card = $cardStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$card) {
                throw new RuntimeException("Card not found or not available for loading");
            }
            
            $holdStmt = $this->db->prepare("
                SELECT * FROM hold_transactions 
                WHERE hold_reference = :hold_ref 
                AND status = 'ACTIVE'
            ");
            $holdStmt->execute([':hold_ref' => $data['hold_reference']]);
            $hold = $holdStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$hold) {
                throw new RuntimeException("Hold not found or not active");
            }
            
            // Calculate fees for card load
            $feeResult = $this->calculateCardFees([
                'amount' => $data['amount'],
                'currency' => $card['currency'] ?? 'BWP',
                'fee_type' => 'CARD_LOAD'
            ]);
            
            $totalFee = $feeResult['total_fee'] ?? 0;
            $netAmount = $data['amount'] - $totalFee;
            
            $updateStmt = $this->db->prepare("
                UPDATE message_cards 
                SET hold_reference = :hold_ref,
                    swap_reference = :swap_ref,
                    initial_amount = initial_amount + :amount,
                    remaining_amount = remaining_amount + :amount,
                    fee_amount = COALESCE(fee_amount, 0) + :fee,
                    status = 'ACTIVE',
                    activated_at = COALESCE(activated_at, NOW()),
                    updated_at = NOW(),
                    metadata = COALESCE(metadata, '{}'::jsonb) || :metadata::jsonb
                WHERE card_id = :card_id
                RETURNING *
            ");
            
            $updateStmt->execute([
                ':hold_ref' => $data['hold_reference'],
                ':swap_ref' => $data['swap_reference'] ?? null,
                ':amount' => $netAmount,
                ':fee' => $totalFee,
                ':card_id' => $card['card_id'],
                ':metadata' => json_encode([
                    'load_fee_breakdown' => $feeResult,
                    'original_load_amount' => $data['amount']
                ])
            ]);
            
            $updatedCard = $updateStmt->fetch(PDO::FETCH_ASSOC);
            
            $this->recordCardLoadTransaction(
                $updatedCard['card_id'],
                $data['hold_reference'],
                $data['amount'],
                $totalFee
            );
            
            return [
                'success' => true,
                'card_id' => $updatedCard['card_id'],
                'card_suffix' => $updatedCard['card_suffix'],
                'new_balance' => (float)$updatedCard['remaining_amount'],
                'old_balance' => (float)$card['remaining_amount'],
                'amount_loaded' => $data['amount'],
                'fee_amount' => $totalFee,
                'net_loaded' => $netAmount,
                'fee_breakdown' => $feeResult,
                'hold_reference' => $data['hold_reference'],
                'message' => 'Card loaded successfully'
            ];
            
        } catch (Exception $e) {
            error_log("Card load error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Calculate card fees and forex conversion
     * 
     * NEW: Fee/forex integration for card operations
     */
    private function calculateCardFees(array $data): array
    {
        $this->feeCalculationDetails = [];
        
        $amount = (float)($data['amount'] ?? 0);
        $currency = $data['currency'] ?? 'BWP';
        $feeType = $data['fee_type'] ?? 'CARD_ISSUE';
        $destinationCurrency = $data['destination_currency'] ?? $currency;
        
        $totalFee = 0;
        $forexApplied = false;
        $exchangeRate = 1.0;
        $convertedAmount = $amount;
        
        // Apply forex if destination currency differs
        if ($this->forexService !== null && $currency !== $destinationCurrency) {
            try {
                $rate = $this->forexService->getClientRate($currency, $destinationCurrency, 'retail');
                $exchangeRate = $rate['rate'] ?? 1.0;
                $convertedAmount = $amount * $exchangeRate;
                $forexApplied = true;
            } catch (Exception $e) {
                error_log("[CardService] Forex conversion failed: " . $e->getMessage());
                // Continue with 1:1 rate if forex fails
            }
        }
        
        // Apply fee if FeeService is available
        if ($this->feeService !== null) {
            try {
                $feeResult = $this->feeService->calculateFees($feeType, $amount, $data);
                $totalFee = $feeResult['total_fee'] ?? 0;
                $this->feeCalculationDetails = $feeResult;
            } catch (Exception $e) {
                error_log("[CardService] Fee calculation failed: " . $e->getMessage());
                // Continue with zero fee if fee service fails
            }
        }
        
        return [
            'total_fee' => $totalFee,
            'fee_type' => $feeType,
            'fee_currency' => $currency,
            'forex_applied' => $forexApplied,
            'exchange_rate' => $exchangeRate,
            'original_amount' => $amount,
            'converted_amount' => $convertedAmount,
            'breakdown' => $this->feeCalculationDetails,
            'net_amount' => $convertedAmount - $totalFee
        ];
    }

    /**
     * Get card authorization details (the message)
     */
    public function getCardAuthorization(string $cardSuffix): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                ca.authorization_id,
                ca.swap_id,
                ca.swap_reference,
                ca.card_suffix,
                ca.authorized_amount,
                ca.remaining_balance,
                ca.hold_reference,
                ca.source_institution,
                ca.fee_amount,
                ca.vat_amount,
                ca.status,
                ca.expiry_at,
                ca.created_at,
                mc.cardholder_name,
                mc.currency,
                mc.daily_limit,
                mc.monthly_limit,
                mc.atm_daily_limit,
                mc.fee_amount as card_issuance_fee
            FROM card_authorizations ca
            JOIN message_cards mc ON ca.card_suffix = mc.card_suffix
            WHERE ca.card_suffix = ? 
            AND ca.status = 'ACTIVE'
            AND ca.expiry_at > NOW()
            ORDER BY ca.created_at DESC
            LIMIT 1
        ");
        
        $stmt->execute([$cardSuffix]);
        $auth = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$auth) {
            $cardStmt = $this->db->prepare("
                SELECT * FROM message_cards 
                WHERE card_suffix = ? 
                AND status = 'ACTIVE'
                LIMIT 1
            ");
            $cardStmt->execute([$cardSuffix]);
            $card = $cardStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($card) {
                return [
                    'authorization_id' => null,
                    'card_suffix' => $card['card_suffix'],
                    'cardholder_name' => $card['cardholder_name'],
                    'authorized_amount' => (float)$card['initial_amount'],
                    'remaining_balance' => (float)$card['remaining_amount'],
                    'hold_reference' => $card['hold_reference'],
                    'source_institution' => $card['source_institution'] ?? 'VOUCHMORPH',
                    'expiry' => $card['expiry_year'] . '-' . $card['expiry_month'] . '-01',
                    'currency' => $card['currency'] ?? 'BWP',
                    'fee_amount' => (float)($card['fee_amount'] ?? 0),
                    'is_authorization' => false
                ];
            }
            
            throw new RuntimeException("Card not found or no active authorization");
        }
        
        return [
            'authorization_id' => (int)$auth['authorization_id'],
            'card_suffix' => $auth['card_suffix'],
            'cardholder_name' => $auth['cardholder_name'],
            'authorized_amount' => (float)$auth['authorized_amount'],
            'remaining_balance' => (float)$auth['remaining_balance'],
            'hold_reference' => $auth['hold_reference'],
            'source_institution' => $auth['source_institution'] ?? 'VOUCHMORPH',
            'expiry' => $auth['expiry_at'],
            'currency' => $auth['currency'] ?? 'BWP',
            'fee_amount' => (float)($auth['fee_amount'] ?? 0),
            'card_issuance_fee' => (float)($auth['card_issuance_fee'] ?? 0),
            'vat_amount' => (float)($auth['vat_amount'] ?? 0),
            'status' => $auth['status'],
            'daily_limit' => (float)$auth['daily_limit'],
            'monthly_limit' => (float)$auth['monthly_limit'],
            'atm_daily_limit' => (float)$auth['atm_daily_limit'],
            'is_authorization' => true
        ];
    }

    private function generateVRN($cardSuffix, $amount, $holdReference): array
    {
        $timestamp = date('YmdHis');
        $random = bin2hex(random_bytes(4));
        $uniqueId = substr(md5($cardSuffix . $amount . $holdReference . $timestamp), 0, 8);
        
        $vrn = "VRN-{$timestamp}-{$random}-{$uniqueId}";
        
        $signature = hash_hmac('sha256', 
            $vrn . $cardSuffix . $amount . $holdReference, 
            getenv('VRN_SIGNING_KEY') ?: 'default-vrn-key-32-chars-long!!'
        );
        
        return [
            'vrn' => $vrn,
            'signature' => $signature,
            'format' => 'ISO-8583-COMPLIANT'
        ];
    }
    
    /**
     * Authorize a transaction (called by ATM/POS/online)
     * 
     * FIXED: CVV check REPLACED with TOTP dynamic code verification
     * FIXED: Uses HMAC-SHA256 for PAN lookup
     */
    public function authorizeTransaction(array $data): array
    {
        $startTime = microtime(true);
        $this->db->beginTransaction();
        
        try {
            $cardHash = $this->hashPan($data['card_number']);
            
            $cardStmt = $this->db->prepare("
                SELECT mc.*, ht.source_institution, ht.amount as hold_amount
                FROM message_cards mc
                JOIN hold_transactions ht ON mc.hold_reference = ht.hold_reference
                WHERE mc.card_number_hash = ?
                AND mc.status = 'ACTIVE'
                FOR UPDATE
            ");
            $cardStmt->execute([$cardHash]);
            $card = $cardStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$card) {
                throw new RuntimeException("Card not found or inactive");
            }
            
            // ============================================================
            // TOTP DYNAMIC CODE CHECK - replaces static CVV
            // ============================================================
            if (empty($data['dynamic_code'])) {
                throw new RuntimeException("Dynamic code is required");
            }
            if (!$this->verifyDynamicCode($card['card_suffix'], $data['dynamic_code'])) {
                throw new RuntimeException("Invalid or expired dynamic code");
            }
            
            $currentYear = (int)date('Y');
            $currentMonth = (int)date('m');
            
            if ($card['expiry_year'] < $currentYear || 
                ($card['expiry_year'] == $currentYear && $card['expiry_month'] < $currentMonth)) {
                throw new RuntimeException("Card expired");
            }
            
            $amount = (float)$data['amount'];
            
            if ((float)$card['remaining_amount'] < $amount) {
                throw new RuntimeException("Insufficient funds");
            }
            
            $channel = $data['channel'] ?? 'POS';
            
            if ($channel === 'ATM' && $amount > (float)($card['atm_daily_limit'] ?? self::ATM_DAILY_LIMIT)) {
                throw new RuntimeException("ATM withdrawal exceeds daily limit");
            }
            
            if ($channel === 'POS' && $amount > self::POS_MAX_TRANSACTION) {
                throw new RuntimeException("Transaction exceeds POS limit");
            }
            
            $dailyStmt = $this->db->prepare("
                SELECT COALESCE(SUM(amount), 0) as daily_total
                FROM card_transactions
                WHERE card_id = ?
                AND DATE(created_at) = CURRENT_DATE
                AND auth_status = 'APPROVED'
            ");
            $dailyStmt->execute([$card['card_id']]);
            $dailyTotal = (float)$dailyStmt->fetchColumn();
            
            if ($dailyTotal + $amount > (float)($card['daily_limit'] ?? self::DAILY_SPEND_LIMIT)) {
                throw new RuntimeException("Daily spending limit exceeded");
            }
            
            $holdStmt = $this->db->prepare("
                UPDATE hold_transactions 
                SET amount = amount - ?
                WHERE hold_reference = ?
                AND amount >= ?
                RETURNING amount
            ");
            $holdStmt->execute([$amount, $card['hold_reference'], $amount]);
            $newHoldAmount = $holdStmt->fetchColumn();
            
            if ($newHoldAmount === false) {
                throw new RuntimeException("Failed to update hold");
            }
            
            $updateCard = $this->db->prepare("
                UPDATE message_cards 
                SET remaining_amount = remaining_amount - ?,
                    last_used_at = NOW()
                WHERE card_id = ?
                RETURNING remaining_amount
            ");
            $updateCard->execute([$amount, $card['card_id']]);
            $newCardBalance = $updateCard->fetchColumn();
            
            $authCode = CardHelper::generateAuthCode();
            
            $settlementStmt = $this->db->prepare("
                INSERT INTO settlement_queue (
                    debtor,
                    creditor,
                    amount,
                    hold_reference,
                    reference,
                    status,
                    metadata,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, 'PENDING', ?::jsonb, NOW())
                RETURNING id
            ");
            
            $settlementStmt->execute([
                $card['source_institution'] ?? 'VOUCHMORPH',
                $data['acquirer'] ?? 'UNKNOWN',
                $amount,
                $card['hold_reference'],
                $authCode,
                json_encode([
                    'card_id' => $card['card_id'],
                    'card_suffix' => $card['card_suffix'],
                    'channel' => $channel,
                    'merchant' => $data['merchant_name'] ?? null,
                    'terminal' => $data['terminal_id'] ?? null
                ])
            ]);
            
            $settlementId = $settlementStmt->fetchColumn();
            
            $this->logTransaction([
                'card_id' => $card['card_id'],
                'type' => $channel === 'ATM' ? 'ATM_WITHDRAWAL' : 'PURCHASE',
                'amount' => $amount,
                'auth_code' => $authCode,
                'auth_status' => 'APPROVED',
                'merchant_name' => $data['merchant_name'] ?? null,
                'merchant_id' => $data['merchant_id'] ?? null,
                'terminal_id' => $data['terminal_id'] ?? null,
                'atm_id' => $data['atm_id'] ?? null,
                'channel' => $channel,
                'settlement_id' => $settlementId,
                'reference' => $data['reference'] ?? null
            ]);
            
            $this->db->commit();
            
            $responseTime = round((microtime(true) - $startTime) * 1000);
            
            $this->logAuthRequest($card['card_id'], $data, [
                'success' => true,
                'auth_code' => $authCode,
                'response_time' => $responseTime
            ]);
            
            return [
                'success' => true,
                'authorized' => true,
                'auth_code' => $authCode,
                'remaining_balance' => (float)$newCardBalance,
                'transaction_id' => $authCode,
                'response_code' => '00',
                'response_message' => 'Approved',
                'processing_time_ms' => $responseTime
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            
            $responseTime = round((microtime(true) - $startTime) * 1000);
            
            $this->logAuthRequest(
                $data['card_number'] ?? null,
                $data,
                [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'response_time' => $responseTime
                ]
            );
            
            return [
                'success' => false,
                'authorized' => false,
                'error' => $e->getMessage(),
                'response_code' => '51',
                'response_message' => 'Declined',
                'processing_time_ms' => $responseTime
            ];
        }
    }
    
    /**
     * Get card balance
     * 
     * FIXED: Uses HMAC-SHA256 for PAN lookup
     */
    public function getCardBalance(string $cardNumber): array
    {
        $cardHash = $this->hashPan($cardNumber);
        
        $stmt = $this->db->prepare("
            SELECT * FROM card_balances_view
            WHERE card_id = (
                SELECT card_id FROM message_cards 
                WHERE card_number_hash = ?
            )
        ");
        $stmt->execute([$cardHash]);
        $card = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$card) {
            throw new RuntimeException("Card not found");
        }
        
        return [
            'success' => true,
            'card_suffix' => $card['card_suffix'],
            'cardholder_name' => $card['cardholder_name'],
            'balance' => (float)$card['balance'],
            'currency' => $card['currency'],
            'expiry' => $card['expiry'],
            'status' => $card['status'],
            'total_spent' => (float)$card['total_spent'],
            'total_fees' => (float)($card['total_fees'] ?? 0),
            'transaction_count' => (int)$card['transaction_count'],
            'last_transaction' => $card['last_transaction'],
            'source_institution' => $card['source_institution']
        ];
    }
    
    /**
     * Block a card
     */
    public function blockCard(string $cardNumber, string $reason): array
    {
        $this->db->beginTransaction();
        
        try {
            $cardHash = $this->hashPan($cardNumber);
            
            $cardStmt = $this->db->prepare("
                UPDATE message_cards 
                SET status = 'BLOCKED',
                    blocked_at = NOW(),
                    block_reason = ?
                WHERE card_number_hash = ?
                AND status = 'ACTIVE'
                RETURNING card_id, hold_reference
            ");
            $cardStmt->execute([$reason, $cardHash]);
            $card = $cardStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$card) {
                throw new RuntimeException("Card not found or already inactive");
            }
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Card blocked successfully',
                'card_id' => $card['card_id'],
                'hold_reference' => $card['hold_reference']
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Get transaction history for a card
     * 
     * FIXED: Uses HMAC-SHA256 for PAN lookup
     */
    public function getCardTransactions(string $cardNumber, int $limit = 10): array
    {
        $cardHash = $this->hashPan($cardNumber);
        
        $stmt = $this->db->prepare("
            SELECT 
                ct.transaction_type,
                ct.amount,
                ct.currency,
                ct.auth_code,
                ct.auth_status,
                ct.merchant_name,
                ct.atm_id,
                ct.channel,
                ct.created_at,
                ct.response_code,
                ct.response_message,
                ct.fee_amount
            FROM card_transactions ct
            JOIN message_cards mc ON ct.card_id = mc.card_id
            WHERE mc.card_number_hash = ?
            ORDER BY ct.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$cardHash, $limit]);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'success' => true,
            'transactions' => $transactions,
            'count' => count($transactions)
        ];
    }
    
    /**
     * Reverse a transaction (for disputes/errors)
     */
    public function reverseTransaction(string $authCode): array
    {
        $this->db->beginTransaction();
        
        try {
            $txStmt = $this->db->prepare("
                SELECT ct.*, mc.hold_reference
                FROM card_transactions ct
                JOIN message_cards mc ON ct.card_id = mc.card_id
                WHERE ct.auth_code = ?
                AND ct.auth_status = 'APPROVED'
                FOR UPDATE
            ");
            $txStmt->execute([$authCode]);
            $transaction = $txStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$transaction) {
                throw new RuntimeException("Transaction not found or already reversed");
            }
            
            $holdStmt = $this->db->prepare("
                UPDATE hold_transactions 
                SET amount = amount + ?
                WHERE hold_reference = ?
                RETURNING amount
            ");
            $holdStmt->execute([$transaction['amount'], $transaction['hold_reference']]);
            
            $cardStmt = $this->db->prepare("
                UPDATE message_cards 
                SET remaining_amount = remaining_amount + ?
                WHERE card_id = ?
            ");
            $cardStmt->execute([$transaction['amount'], $transaction['card_id']]);
            
            $updateTx = $this->db->prepare("
                UPDATE card_transactions 
                SET auth_status = 'REVERSED'
                WHERE transaction_id = ?
            ");
            $updateTx->execute([$transaction['transaction_id']]);
            
            $queueStmt = $this->db->prepare("
                DELETE FROM settlement_queue 
                WHERE reference = ?
            ");
            $queueStmt->execute([$authCode]);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Transaction reversed successfully',
                'amount' => $transaction['amount'],
                'auth_code' => $authCode
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Split a hold (for partial card issuance)
     */
    private function splitHold(string $holdReference, float $cardAmount): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO hold_transactions (
                hold_reference,
                swap_reference,
                participant_id,
                participant_name,
                asset_type,
                amount,
                currency,
                status,
                hold_expiry,
                source_details,
                destination_institution,
                metadata
            )
            SELECT 
                concat('HOLD-', gen_random_uuid()::text),
                swap_reference,
                participant_id,
                participant_name,
                asset_type,
                amount - ?,
                currency,
                'ACTIVE',
                hold_expiry,
                source_details,
                destination_institution,
                jsonb_build_object('parent_hold', ?)
            FROM hold_transactions
            WHERE hold_reference = ?
            RETURNING hold_reference
        ");
        $stmt->execute([$cardAmount, $holdReference, $holdReference]);
        $newHoldRef = $stmt->fetchColumn();
        
        $updateStmt = $this->db->prepare("
            UPDATE hold_transactions 
            SET amount = ?,
                metadata = jsonb_set(
                    COALESCE(metadata, '{}'::jsonb),
                    '{child_hold}',
                    to_jsonb(?)
                )
            WHERE hold_reference = ?
        ");
        $updateStmt->execute([$cardAmount, $newHoldRef, $holdReference]);
    }
    
    /**
     * Record card load transaction
     */
    private function recordCardLoadTransaction(int $cardId, string $holdReference, float $amount, float $fee = 0): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO card_transactions (
                card_id,
                transaction_type,
                amount,
                fee_amount,
                hold_reference,
                auth_status,
                created_at
            ) VALUES (
                :card_id,
                'LOAD',
                :amount,
                :fee,
                :hold_ref,
                'APPROVED',
                NOW()
            )
        ");
        
        $stmt->execute([
            ':card_id' => $cardId,
            ':amount' => $amount - $fee,
            ':fee' => $fee,
            ':hold_ref' => $holdReference
        ]);
    }
    
    /**
     * Get or create user from data
     */
    private function getOrCreateUser(array $data): ?int
    {
        if (!empty($data['user_id'])) {
            return (int)$data['user_id'];
        }
        
        if (!empty($data['student_id'])) {
            $stmt = $this->db->prepare("
                SELECT user_id FROM users 
                WHERE metadata->>'student_id' = ?
            ");
            $stmt->execute([$data['student_id']]);
            $userId = $stmt->fetchColumn();
            
            if ($userId) {
                return (int)$userId;
            }
        }
        
        return null;
    }
    
    /**
     * Log card transaction
     * 
     * FIXED: Redacts sensitive data before logging
     */
    private function logTransaction(array $data): void
    {
        try {
            // Redact sensitive fields
            $logData = $data;
            unset($logData['cvv'], $logData['pin'], $logData['card_number'], $logData['dynamic_code']);
            
            $stmt = $this->db->prepare("
                INSERT INTO card_transactions (
                    card_id,
                    transaction_type,
                    amount,
                    fee_amount,
                    auth_code,
                    auth_status,
                    merchant_name,
                    merchant_id,
                    terminal_id,
                    atm_id,
                    channel,
                    settlement_queue_id,
                    reference,
                    response_code,
                    response_message
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $data['card_id'],
                $data['type'],
                $data['amount'],
                $data['fee_amount'] ?? 0,
                $data['auth_code'] ?? null,
                $data['auth_status'] ?? 'APPROVED',
                $data['merchant_name'] ?? null,
                $data['merchant_id'] ?? null,
                $data['terminal_id'] ?? null,
                $data['atm_id'] ?? null,
                $data['channel'] ?? null,
                $data['settlement_id'] ?? null,
                $data['reference'] ?? null,
                $data['response_code'] ?? '00',
                $data['response_message'] ?? 'Approved'
            ]);
        } catch (Exception $e) {
            error_log("Failed to log transaction: " . $e->getMessage());
        }
    }
    
    /**
     * Log authorization request for audit
     * 
     * FIXED: Redacts sensitive data before logging
     */
    private function logAuthRequest($cardId, array $request, array $response): void
    {
        try {
            // Redact sensitive fields
            $safeRequest = $request;
            unset($safeRequest['cvv'], $safeRequest['pin'], $safeRequest['card_number'], $safeRequest['dynamic_code']);
            
            $safeResponse = $response;
            unset($safeResponse['cvv'], $safeResponse['pin'], $safeResponse['card_number'], $safeResponse['dynamic_code']);
            
            $stmt = $this->db->prepare("
                INSERT INTO card_auth_logs (
                    card_id,
                    request_payload,
                    response_payload,
                    http_status_code,
                    response_time_ms,
                    success,
                    error_message,
                    created_at
                ) VALUES (?, ?::jsonb, ?::jsonb, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                is_numeric($cardId) ? $cardId : null,
                json_encode($safeRequest),
                json_encode($safeResponse),
                200,
                $response['response_time_ms'] ?? null,
                $response['success'] ?? false,
                $response['error'] ?? null
            ]);
        } catch (Exception $e) {
            error_log("Failed to log auth request: " . $e->getMessage());
        }
    }

    // ============================================================
    // POOLED CARD HOOK/SWIPE/FINALIZE - VouchMorph's own network
    // ============================================================

    /**
     * Hook multiple sources to a card as a single all-or-nothing event.
     * Every source gets a real hold placed. If ANY source hold fails,
     * every hold already placed in this attempt is released and the
     * hook itself fails - "hook successful" always means every source
     * is genuinely held, never a partial state.
     * 
     * CONSENT GATE: Any source owned by someone OTHER than the card owner
     * must already exist as an active linked/consented source in the
     * user_authorized_sources table. This endpoint never trusts
     * owner_user_id + credentials from the request body alone as proof
     * of consent. The consent check runs as its own pass BEFORE any
     * holds are placed, so a consent failure costs nothing (no rollback needed).
     */
    public function hookSourcesToCard(
        string $cardSuffix,
        array $sources,
        SwapService $swapService,
        int $cardOwnerUserId
    ): array {
        $this->db->beginTransaction();
        $placedHolds = [];

        try {
            if (count($sources) < 1) {
                throw new RuntimeException("At least one source is required to hook");
            }

            // ============================================================
            // CONSENT GATE - runs BEFORE any holds are placed
            // ============================================================
            foreach ($sources as $source) {
                $sourceOwnerId = (int)($source['owner_user_id'] ?? $cardOwnerUserId);

                if ($sourceOwnerId !== $cardOwnerUserId) {
                    $consentStmt = $this->db->prepare("
                        SELECT 1 FROM user_authorized_sources
                        WHERE user_id = :owner_id
                          AND institution = :institution
                          AND status = 'active'
                        LIMIT 1
                    ");
                    $consentStmt->execute([
                        ':owner_id' => $sourceOwnerId,
                        ':institution' => $source['institution'],
                    ]);

                    if (!$consentStmt->fetchColumn()) {
                        throw new RuntimeException(
                            "Source owned by user {$sourceOwnerId} at {$source['institution']} " .
                            "has not consented to be linked - reject rather than hold blind. " .
                            "The source owner must complete source-linking consent before this card can hook their funds."
                        );
                    }
                }
            }

            $hookReference = 'HOOK_' . bin2hex(random_bytes(8));
            $totalHeld = 0.0;
            $minExpirySeconds = PHP_INT_MAX;
            $currency = $sources[0]['currency'] ?? 'BWP';

            foreach ($sources as $source) {
                $balance = $swapService->getSourceAvailableBalance($source);
                if ($balance <= 0) {
                    throw new RuntimeException("Source {$source['institution']} has no available balance to hook");
                }

                $holdPayload = array_merge($source, [
                    'amount' => $balance,
                    'currency' => $source['currency'] ?? $currency,
                    'hold_reason' => 'CARD_POOL_HOOK_' . $hookReference,
                ]);

                $verifyResult = $swapService->verifyAssetSigned($holdPayload, $source['institution']);
                if (!($verifyResult['verified'] ?? false)) {
                    throw new RuntimeException("Verification failed for {$source['institution']}: " . ($verifyResult['message'] ?? 'unknown'));
                }

                $holdResult = $swapService->placeHoldSigned($holdPayload, $source['institution'], $verifyResult);
                if (!($holdResult['hold_placed'] ?? false)) {
                    throw new RuntimeException("Hold failed for {$source['institution']}: " . ($holdResult['message'] ?? 'unknown'));
                }

                $placedHolds[] = [
                    'source' => $source,
                    'hold_reference' => $holdResult['hold_reference'] ?? null,
                    'hold_id' => $holdResult['local_hold_id'] ?? null,
                    'amount' => $balance,
                ];

                $totalHeld += $balance;
                $expirySeconds = AssetTypeRegistry::getHoldExpiry($source['asset_type'] ?? 'ACCOUNT');
                $minExpirySeconds = min($minExpirySeconds, $expirySeconds);
            }

            $expiresAt = date('Y-m-d H:i:s', time() + $minExpirySeconds);

            $hookStmt = $this->db->prepare("
                INSERT INTO card_pool_hooks (hook_reference, card_suffix, user_id, total_held_amount, currency, status, expires_at)
                VALUES (?, ?, ?, ?, ?, 'HOOKED', ?)
                RETURNING id
            ");
            $hookStmt->execute([$hookReference, $cardSuffix, $cardOwnerUserId, $totalHeld, $currency, $expiresAt]);
            $hookId = $hookStmt->fetchColumn();

            foreach ($placedHolds as $held) {
                $sourceStmt = $this->db->prepare("
                    INSERT INTO card_pool_hook_sources
                        (hook_id, owner_user_id, institution, asset_type, source_identifier, held_amount, hold_reference, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'HELD')
                ");
                $sourceStmt->execute([
                    $hookId,
                    $held['source']['owner_user_id'] ?? $cardOwnerUserId,
                    $held['source']['institution'],
                    $held['source']['asset_type'] ?? 'ACCOUNT',
                    $held['source']['identifier'] ?? '',
                    $held['amount'],
                    $held['hold_reference'],
                ]);
            }

            $this->db->commit();

            return [
                'success' => true,
                'hook_reference' => $hookReference,
                'total_held' => $totalHeld,
                'currency' => $currency,
                'expires_at' => $expiresAt,
                'source_count' => count($placedHolds),
                'message' => 'Hook successful - all sources held',
            ];

        } catch (Exception $e) {
            $this->db->rollBack();

            foreach ($placedHolds as $held) {
                try {
                    $swapService->releaseHold($held['source'], $held['source']['institution'], $held['hold_id'] ?? null, $held['hold_reference'] ?? null);
                } catch (Exception $releaseErr) {
                    error_log("[CardService] Failed to release hold during hook rollback: " . $releaseErr->getMessage());
                }
            }

            error_log("[CardService] hookSourcesToCard failed: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage(), 'message' => 'Hook failed - no sources were held'];
        }
    }

    /**
     * FAST PATH ONLY. Called at swipe time. No bank calls - a local check
     * against currently-valid held totals. This is what has to happen in
     * milliseconds; the real debits happen afterward in finalizePooledSwipe().
     * 
     * FIXED: Added TOTP dynamic code verification (same as authorizeTransaction)
     */
    public function authorizePooledSwipe(string $cardSuffix, float $amount, array $merchantContext): array
    {
        $startTime = microtime(true);

        // ============================================================
        // TOTP DYNAMIC CODE CHECK - required for pooled swipes too
        // ============================================================
        if (empty($merchantContext['dynamic_code'])) {
            return [
                'success' => false, 'authorized' => false,
                'response_code' => '57', 'response_message' => 'Dynamic code required',
            ];
        }
        if (!$this->verifyDynamicCode($cardSuffix, $merchantContext['dynamic_code'])) {
            return [
                'success' => false, 'authorized' => false,
                'response_code' => '57', 'response_message' => 'Invalid or expired dynamic code',
            ];
        }

        $stmt = $this->db->prepare("
            SELECT * FROM card_pool_hooks
            WHERE card_suffix = ? AND status = 'HOOKED' AND expires_at > NOW()
            ORDER BY created_at DESC LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$cardSuffix]);
        $hook = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$hook) {
            return [
                'success' => false, 'authorized' => false,
                'response_code' => '51', 'response_message' => 'No active hook - card unhooked, please re-hook',
            ];
        }

        if ((float)$hook['total_held_amount'] < $amount) {
            return [
                'success' => false, 'authorized' => false,
                'response_code' => '51', 'response_message' => 'Amount exceeds held total',
                'held' => (float)$hook['total_held_amount'], 'requested' => $amount,
            ];
        }

        $update = $this->db->prepare("
            UPDATE card_pool_hooks
            SET status = 'SWIPE_RECEIVED', swipe_amount = ?, merchant_reference = ?
            WHERE id = ?
        ");
        $update->execute([$amount, $merchantContext['merchant_reference'] ?? null, $hook['id']]);

        $authCode = CardHelper::generateAuthCode();
        $responseTime = round((microtime(true) - $startTime) * 1000);

        return [
            'success' => true, 'authorized' => true,
            'auth_code' => $authCode, 'response_code' => '00', 'response_message' => 'Approved',
            'hook_reference' => $hook['hook_reference'],
            'processing_time_ms' => $responseTime,
        ];
    }

    /**
     * ASYNC, runs right after approval. Debits only what the merchant
     * actually charged, releases the unused remainder of every hold back
     * to its source, settles to the merchant, and bills any source whose
     * debit fails post-approval to that source's OWNER - never the card
     * owner, never the other sources.
     */
    public function finalizePooledSwipe(
        string $hookReference,
        SwapService $swapService,
        HybridSettlementStrategy $settlement,
        ContributionCalculator $contributionCalculator,
        array $merchantContext
    ): array {
        $this->db->beginTransaction();

        try {
            $hookStmt = $this->db->prepare("SELECT * FROM card_pool_hooks WHERE hook_reference = ? FOR UPDATE");
            $hookStmt->execute([$hookReference]);
            $hook = $hookStmt->fetch(PDO::FETCH_ASSOC);
            if (!$hook || $hook['status'] !== 'SWIPE_RECEIVED') {
                throw new RuntimeException("Hook not found or not in SWIPE_RECEIVED state");
            }

            $sourcesStmt = $this->db->prepare("SELECT * FROM card_pool_hook_sources WHERE hook_id = ?");
            $sourcesStmt->execute([$hook['id']]);
            $sources = $sourcesStmt->fetchAll(PDO::FETCH_ASSOC);

            $swipeAmount = (float)$hook['swipe_amount'];

            $contributions = $contributionCalculator->calculateContributions(
                $swipeAmount,
                array_map(fn($s) => [
                    'institution' => $s['institution'],
                    'asset_type' => $s['asset_type'],
                    'identifier' => $s['source_identifier'],
                    'available_balance' => (float)$s['held_amount'],
                ], $sources),
                'SMART'
            );

            $bills = [];
            $totalDebited = 0.0;

            foreach ($contributions as $i => $contribution) {
                $sourceRow = $sources[$i];
                $debitPayload = [
                    'amount' => $contribution['actual_amount'],
                    'hold_reference' => $sourceRow['hold_reference'],
                    'from_institution' => $sourceRow['institution'],
                    'source_institution' => $sourceRow['institution'],
                ];

                try {
                    $debitResult = $swapService->debitSource($debitPayload, $sourceRow['institution']);
                    if (!($debitResult['debited'] ?? false)) {
                        throw new RuntimeException($debitResult['message'] ?? 'Debit failed');
                    }

                    $this->db->prepare("
                        UPDATE card_pool_hook_sources
                        SET status = 'DEBITED', debited_amount = ?, debit_reference = ?
                        WHERE id = ?
                    ")->execute([$contribution['actual_amount'], $debitResult['transaction_reference'] ?? null, $sourceRow['id']]);

                    $totalDebited += $contribution['actual_amount'];

                    $unused = (float)$sourceRow['held_amount'] - $contribution['actual_amount'];
                    if ($unused > 0.01) {
                        $swapService->releaseHold([], $sourceRow['institution'], null, $sourceRow['hold_reference']);
                    }

                } catch (Exception $debitErr) {
                    $this->db->prepare("
                        UPDATE card_pool_hook_sources SET status = 'SHORTFALL' WHERE id = ?
                    ")->execute([$sourceRow['id']]);

                    $billStmt = $this->db->prepare("
                        INSERT INTO card_pool_shortfall_bills
                            (hook_source_id, owner_user_id, amount, currency, reason, due_at)
                        VALUES (?, ?, ?, ?, ?, NOW() + INTERVAL '7 days')
                        RETURNING id
                    ");
                    $billStmt->execute([
                        $sourceRow['id'],
                        $sourceRow['owner_user_id'],
                        $contribution['actual_amount'],
                        $hook['currency'],
                        'Debit failed post-authorization: ' . $debitErr->getMessage(),
                    ]);
                    $bills[] = $billStmt->fetchColumn();

                    error_log("[CardService] SHORTFALL billed to user {$sourceRow['owner_user_id']} for {$contribution['actual_amount']} {$hook['currency']}");
                }
            }

            $settlementResult = $settlement->updateNetPosition(
                $hookReference,
                'VOUCHMORPH_CARD_POOL',
                $merchantContext['acquirer'] ?? $merchantContext['merchant_id'] ?? 'MERCHANT',
                $swipeAmount,
                'CARD_POOL_SWIPE_SETTLED',
                $hook['currency']
            );

            $status = 'SETTLED';
            $this->db->prepare("
                UPDATE card_pool_hooks SET status = ?, settlement_reference = ?, finalized_at = NOW() WHERE id = ?
            ")->execute([$status, $settlementResult['message_uuid'] ?? null, $hook['id']]);

            $this->db->commit();

            return [
                'success' => true,
                'hook_reference' => $hookReference,
                'swipe_amount' => $swipeAmount,
                'total_debited' => $totalDebited,
                'shortfall_bills' => $bills,
                'settlement' => $settlementResult,
            ];

        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("[CardService] finalizePooledSwipe failed: " . $e->getMessage());
            throw $e;
        }
    }
}
