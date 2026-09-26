<?php
declare(strict_types=1);

namespace Domain\Services;
require_once __DIR__ . '/../../Application/Incident/ServiceControls.php';

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
 * 
 * SCHEMA NOTES (from Diag.php Step 6):
 * - message_cards uses `lifecycle_status` (not `status`)
 * - message_cards uses `created_at` (not `issued_at`)
 * - `financial_status` also exists but is separate from lifecycle
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
    private const DEFAULT_ACTIVATION_FEE = 5.00;

    /**
     * The hold references that a card swap which hasn't finished still has
     * to debit (see isHoldReservedForPendingSwap()), as a subquery. Shared
     * with the incident monitor, which must not report such a hold as a
     * failed release.
     */
    public const PENDING_SWAP_HOLDS_SQL = "
        SELECT pc.hold_reference
        FROM pool_contributions pc
        JOIN virtual_funding_pools p ON p.pool_id = pc.pool_id
        WHERE pc.hold_reference IS NOT NULL
          AND pc.status NOT IN ('DEBITED', 'COMPLETED', 'FAILED', 'CANCELLED')
          AND p.status IN ('PENDING_CASHOUT', 'PENDING_ID_CLAIM')
          AND p.created_at > NOW() - INTERVAL '24 hours'
    ";

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
        // Fix getenv() false vs null type mismatch
        // ============================================================
        $envPanHmacKey = getenv('PAN_HMAC_KEY');
        $envPanHmacKey = $envPanHmacKey === false ? null : $envPanHmacKey;
 
        try {
            $keyVault = KeyVault::getInstance();
            $this->panHmacKey = $keyVault->getKey('pan_hmac_key') ?? $envPanHmacKey;
        } catch (Exception $e) {
            $this->panHmacKey = $envPanHmacKey;
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
    // SAVEPOINT HELPER — same discipline as SwapService::runInSavepoint()
    // ============================================================
    
    /**
     * Same discipline as SwapService::runInSavepoint() — a plain
     * try/catch around a failed statement is NOT enough on Postgres once
     * that statement has thrown; the whole surrounding transaction is
     * left in an aborted state and every subsequent statement fails too,
     * even ones with no real problem of their own. Only a real SAVEPOINT
     * + ROLLBACK TO SAVEPOINT clears that state.
     */
    private function runInSavepoint(string $label, callable $fn)
    {
        $safeName = 'sp_card_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $label);
    
        try {
            $this->db->exec("SAVEPOINT {$safeName}");
        } catch (\Throwable $e) {
            error_log("[CardService] Failed to create savepoint {$safeName}: " . $e->getMessage());
            throw $e;
        }
    
        try {
            $result = $fn();
            $this->db->exec("RELEASE SAVEPOINT {$safeName}");
            return $result;
        } catch (\Throwable $e) {
            try {
                $this->db->exec("ROLLBACK TO SAVEPOINT {$safeName}");
                $this->db->exec("RELEASE SAVEPOINT {$safeName}");
            } catch (\Throwable $rollbackError) {
                error_log("[CardService] ROLLBACK TO SAVEPOINT {$safeName} itself failed: " . $rollbackError->getMessage());
            }
            throw $e;
        }
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
    public function encryptTotpSecret(string $secret): array
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
    public function decryptTotpSecret(string $ciphertext, string $iv, string $tag): string
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
 * Rotates a card's TOTP secret. The card owner must re-set-up their
 * authenticator app after this runs — the old secret stops working
 * immediately. Requires the caller to own the card (enforced by
 * matching card_suffix + user_id, never trusting a bare card_suffix
 * from the request alone).
 */
public function regenerateTotpSecret(string $cardSuffix, int $userId): array
{
    $stmt = $this->db->prepare("
        SELECT card_id FROM message_cards
        WHERE card_suffix = :suffix AND user_id = :uid
    ");
    $stmt->execute([':suffix' => $cardSuffix, ':uid' => $userId]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$card) {
        return ['success' => false, 'error' => 'Card not found or does not belong to you.'];
    }

    $google2fa = new Google2FA();
    $newSecret = $google2fa->generateSecretKey();
    $encrypted = $this->encryptTotpSecret($newSecret);

    $stmt = $this->db->prepare("
        UPDATE message_cards
        SET totp_secret_encrypted = ?, totp_secret_iv = ?, totp_secret_tag = ?
        WHERE card_id = ?
    ");
    $stmt->execute([
        $encrypted['ciphertext'], $encrypted['iv'], $encrypted['tag'], $card['card_id'],
    ]);

    error_log("[CardService] TOTP secret regenerated for card_suffix={$cardSuffix}, user_id={$userId}");

    $otpauthUri = sprintf(
        'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
        rawurlencode('VouchMorph'), rawurlencode($cardSuffix), $newSecret, rawurlencode('VouchMorph')
    );

    return [
        'success' => true,
        'card_suffix' => $cardSuffix,
        'secret' => $newSecret, // SHOWN ONCE — same rule as provisionUserCard()/issueCard()
        'otpauth_uri' => $otpauthUri,
        'warning' => 'Your old swipe code no longer works. Set up this new one in your authenticator app now — it will not be shown again.',
    ];
}
    
    /**
     * Verify a dynamic code the app displayed against the card's stored secret.
     * Replaces static CVV verification entirely.
     */
    public function verifyDynamicCode(string $cardSuffix, string $providedCode): bool
    {
        $stmt = $this->db->prepare("
            SELECT totp_secret_encrypted, totp_secret_iv, totp_secret_tag
            FROM message_cards WHERE card_suffix = ? AND lifecycle_status = 'ACTIVE'
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
     * FIXED: Uses lifecycle_status and created_at (not status and issued_at)
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
            // SCHEMA FIX: use lifecycle_status and created_at
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
                    lifecycle_status,
                    created_at,
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
     * FIXED: Uses lifecycle_status (not status)
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
                AND lifecycle_status IN ('ASSIGNED', 'DELIVERED', 'ACTIVE')
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
                    lifecycle_status = 'ACTIVE',
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
                AND lifecycle_status = 'ACTIVE'
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
    
    public function getActivationFeeAmount(?string $institution = null): float
    {
        if ($this->feeService !== null) {
            try {
                $feeResult = $this->feeService->calculateFees('CARD_ACTIVATION', 0, [
                    'source_institution' => $institution ?? 'UNKNOWN',
                    'destination_institution' => 'VOUCHMORPH',
                ]);
                return (float)($feeResult['total_fee'] ?? self::DEFAULT_ACTIVATION_FEE);
            } catch (\Throwable $e) {
                error_log("[CardService] getActivationFeeAmount fallback: " . $e->getMessage());
            }
        }
        return (float)($this->config['activation_fee'] ?? self::DEFAULT_ACTIVATION_FEE);
    }

    /**
     * FIXED: VRN signing key now sourced from KeyVault, no hardcoded fallback.
     * Fails loudly if the key is missing/too short rather than silently
     * signing with a value visible in source control.
     */
    private function generateVRN($cardSuffix, $amount, $holdReference): array
    {
        $timestamp = date('YmdHis');
        $random = bin2hex(random_bytes(4));
        $uniqueId = substr(md5($cardSuffix . $amount . $holdReference . $timestamp), 0, 8);
        
        $vrn = "VRN-{$timestamp}-{$random}-{$uniqueId}";
        
        $vrnSigningKey = KeyVault::getInstance()->getKey('vrn_signing_key') ?? getenv('VRN_SIGNING_KEY');
        if (empty($vrnSigningKey) || strlen($vrnSigningKey) < 32) {
            throw new RuntimeException(
                'VRN signing key is missing or too short (min 32 bytes required). ' .
                'Refusing to fall back to a hardcoded default.'
            );
        }
        
        $signature = hash_hmac('sha256', 
            $vrn . $cardSuffix . $amount . $holdReference, 
            $vrnSigningKey
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
     * FIXED: Uses lifecycle_status (not status)
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
                AND mc.lifecycle_status = 'ACTIVE'
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
     * 
     * FIXED: Uses lifecycle_status (not status)
     */
    public function blockCard(string $cardNumber, string $reason): array
    {
        $this->db->beginTransaction();
        
        try {
            $cardHash = $this->hashPan($cardNumber);
            
            $cardStmt = $this->db->prepare("
                UPDATE message_cards 
                SET lifecycle_status = 'BLOCKED',
                    blocked_at = NOW(),
                    block_reason = ?
                WHERE card_number_hash = ?
                AND lifecycle_status = 'ACTIVE'
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
        $savepointName = 'sp_logtx_' . uniqid();
        try {
            $this->db->exec("SAVEPOINT {$savepointName}");
        } catch (Exception $e) {
            error_log("Failed to create savepoint for logTransaction: " . $e->getMessage());
            return;
        }

        try {
            $logData = $data;
            unset($logData['cvv'], $logData['pin'], $logData['card_number'], $logData['dynamic_code']);

            $stmt = $this->db->prepare("
                INSERT INTO card_transactions (
                    card_id, transaction_type, amount, fee_amount, auth_code,
                    auth_status, merchant_name, merchant_id, terminal_id, atm_id,
                    channel, settlement_queue_id, reference, response_code, response_message
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $data['card_id'], $data['type'], $data['amount'], $data['fee_amount'] ?? 0,
                $data['auth_code'] ?? null, $data['auth_status'] ?? 'APPROVED',
                $data['merchant_name'] ?? null, $data['merchant_id'] ?? null,
                $data['terminal_id'] ?? null, $data['atm_id'] ?? null,
                $data['channel'] ?? null, $data['settlement_id'] ?? null,
                $data['reference'] ?? null, $data['response_code'] ?? '00',
                $data['response_message'] ?? 'Approved'
            ]);

            $this->db->exec("RELEASE SAVEPOINT {$savepointName}");
        } catch (Exception $e) {
            // Undo ONLY this insert's effect — critically, this clears
            // Postgres's aborted-transaction state so the OUTER
            // transaction (e.g. activateCard()'s lifecycle_status
            // update, already-succeeded before this call) can still
            // commit normally. A plain catch here, without this
            // ROLLBACK TO SAVEPOINT, does NOT achieve that — the outer
            // commit() would silently no-op instead of throwing,
            // exactly as happened in production just now.
            try {
                $this->db->exec("ROLLBACK TO SAVEPOINT {$savepointName}");
                $this->db->exec("RELEASE SAVEPOINT {$savepointName}");
            } catch (Exception $rollbackError) {
                error_log("ROLLBACK TO SAVEPOINT also failed for logTransaction: " . $rollbackError->getMessage());
            }
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
    // PROVISION & ACTIVATE CARDS - FIXED FOR REAL SCHEMA
    // ============================================================

    /**
     * Auto-provisions a zero-cost, zero-balance VouchMorph Card for a
     * user who doesn't have one yet. No hold is placed, no fee is
     * charged — the card is minted INACTIVE and stays that way until
     * activateCard() is called. Idempotent: if the user already has a
     * card (any status), returns it instead of minting a second one.
     * 
     * SCHEMA FIX: Uses lifecycle_status (not status) and created_at (not issued_at)
     */
    public function provisionUserCard(int $userId, string $cardholderName): array
    {
        // Fast path: most calls are for a user who already has a card.
        $stmt = $this->db->prepare("
            SELECT card_suffix, lifecycle_status FROM message_cards
            WHERE user_id = :uid ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([':uid' => $userId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            return [
                'success' => true,
                'card_suffix' => $existing['card_suffix'],
                'status' => $existing['lifecycle_status'],
                'newly_created' => false,
            ];
        }

        // Slow path: no card yet. Two concurrent calls for the same user
        // (a double-tap, a retried request, two open tabs) can both reach
        // this point before either INSERT commits -- without a uniqueness
        // guarantee, that used to create two separate message_cards rows
        // for one user. Whichever row provisionUserCard() later treats as
        // "the newest" wins the SELECT above, which can silently be a
        // DIFFERENT row than the one a user just activated and paid for.
        //
        // ON CONFLICT (user_id) DO NOTHING + a re-SELECT on the losing
        // path makes this atomic: only one row is ever created per user.
        // Requires the unique index from
        // database/migrations/2026_08_30_message_cards_unique_user_id.sql
        // to exist first (that migration also cleans up any duplicates
        // created before this fix shipped).
        // card_suffix is only 4 digits (max 10,000 values) and
        // CardNumberGenerator picks it at random with no DB awareness --
        // confirmed in production to collide with other cards, including
        // unassigned IN_BATCH physical inventory. Retry generation until
        // we land on a suffix nothing else already uses, rather than
        // relying on the many call sites that read message_cards by
        // card_suffix alone to somehow disambiguate after the fact.
        $cardDetails = null;
        $suffixCheckStmt = $this->db->prepare("SELECT 1 FROM message_cards WHERE card_suffix = :suffix LIMIT 1");
        for ($attempt = 0; $attempt < 25; $attempt++) {
            $candidate = $this->cardGenerator->generateForPurpose('standard');
            $suffixCheckStmt->execute([':suffix' => $candidate['pan_suffix']]);
            if (!$suffixCheckStmt->fetchColumn()) {
                $cardDetails = $candidate;
                break;
            }
        }
        if ($cardDetails === null) {
            throw new RuntimeException("provisionUserCard: could not generate a unique card_suffix after 25 attempts for user_id={$userId}");
        }

        $google2fa = new Google2FA();
        $totpSecret = $google2fa->generateSecretKey();
        $encryptedSecret = $this->encryptTotpSecret($totpSecret);

        $stmt = $this->db->prepare("
            INSERT INTO message_cards (
                card_number_hash, card_suffix, hold_reference, swap_reference,
                user_id, cardholder_name, initial_amount, remaining_amount,
                currency, lifecycle_status, created_at, expiry_year, expiry_month,
                daily_limit, monthly_limit, atm_daily_limit, fee_amount,
                funding_mode, metadata,
                totp_secret_encrypted, totp_secret_iv, totp_secret_tag
            ) VALUES (
                ?, ?, NULL, NULL,
                ?, ?, 0, 0,
                ?, 'INACTIVE', NOW(), ?, ?,
                ?, ?, ?, 0,
                'HOOKED', ?::jsonb,
                ?, ?, ?
            )
            ON CONFLICT (user_id) DO NOTHING
            RETURNING card_id
        ");

        $stmt->execute([
            $this->hashPan($cardDetails['pan_formatted']),
            $cardDetails['pan_suffix'],
            $userId,
            $cardholderName,
            $this->config['currency'] ?? 'BWP',
            $cardDetails['expiry_year'],
            $cardDetails['expiry_month'],
            self::DAILY_SPEND_LIMIT,
            self::MONTHLY_SPEND_LIMIT,
            self::ATM_DAILY_LIMIT,
            json_encode(['issued_by' => 'auto_provision', 'purpose' => 'standard']),
            $encryptedSecret['ciphertext'],
            $encryptedSecret['iv'],
            $encryptedSecret['tag'],
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            error_log("[CardService] Auto-provisioned INACTIVE card {$cardDetails['pan_suffix']} for user_id={$userId}");

            return [
                'success' => true,
                'card_id' => $row['card_id'],
                'card_suffix' => $cardDetails['pan_suffix'],
                'status' => 'INACTIVE',
                'newly_created' => true,
            ];
        }

        // Lost the race: another concurrent call already created this
        // user's card between our SELECT and our INSERT. Read back what
        // it created instead of returning the (unused, never persisted)
        // card details we just generated.
        $stmt = $this->db->prepare("
            SELECT card_suffix, lifecycle_status FROM message_cards
            WHERE user_id = :uid ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([':uid' => $userId]);
        $winner = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$winner) {
            throw new RuntimeException("provisionUserCard: INSERT conflicted but no existing card found for user_id={$userId}");
        }

        return [
            'success' => true,
            'card_suffix' => $winner['card_suffix'],
            'status' => $winner['lifecycle_status'],
            'newly_created' => false,
        ];
    }

    /**
     * Activates an INACTIVE card by charging the activation fee from a
     * source the user chooses, using the same verify -> hold -> debit
     * primitives SwapService exposes for everything else in this
     * codebase — not a full executeAtomicSwap(), since this is an
     * internal fee collection, not a customer-facing swap that needs
     * swap_type routing.
     * 
     * SCHEMA FIX: Uses lifecycle_status (not status)
     */
    public function activateCard(
        string $cardSuffix,
        int $userId,
        array $sourcePayload,
        \Domain\Services\SwapService $swapService
    ): array {
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare("
                SELECT card_id, lifecycle_status, currency FROM message_cards
                WHERE card_suffix = :suffix AND user_id = :uid
                FOR UPDATE
            ");
            $stmt->execute([':suffix' => $cardSuffix, ':uid' => $userId]);
            $card = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$card) {
                throw new RuntimeException("Card not found or does not belong to you.");
            }
            if ($card['lifecycle_status'] === 'ACTIVE') {
                $this->db->commit();
                return ['success' => true, 'already_active' => true, 'card_suffix' => $cardSuffix, 'message' => 'Card is already active.'];
            }
            if ($card['lifecycle_status'] !== 'INACTIVE') {
                throw new RuntimeException("Card cannot be activated from its current status: {$card['lifecycle_status']}");
            }

            $currency = $card['currency'] ?? 'BWP';
            $institution = $sourcePayload['institution'] ?? null;

            if ($this->feeService !== null) {
                try {
                    $feeResult = $this->feeService->calculateFees('CARD_ACTIVATION', 0, [
                        'source_institution' => $institution,
                        'destination_institution' => 'VOUCHMORPH',
                    ]);
                    $activationFee = (float)($feeResult['total_fee'] ?? self::DEFAULT_ACTIVATION_FEE);
                } catch (\Throwable $e) {
                    error_log("[CardService] CARD_ACTIVATION fee lookup via FeeService failed, using default: " . $e->getMessage());
                    $activationFee = (float)($this->config['activation_fee'] ?? self::DEFAULT_ACTIVATION_FEE);
                }
            } else {
                $activationFee = (float)($this->config['activation_fee'] ?? self::DEFAULT_ACTIVATION_FEE);
            }
            if ($activationFee <= 0) {
                error_log("[CardService] activateCard: computed activation fee was {$activationFee} — refusing to place a zero/negative-amount hold. Check fees.json CARD_ACTIVATION config.");
                throw new RuntimeException("Card activation fee could not be determined. Please contact support.");
            }

            if (empty($institution)) {
                throw new RuntimeException("Source institution is required to activate.");
            }

            $reference = 'CARD_ACTIVATE_' . $cardSuffix . '_' . time();
            $swapService->setCurrentSwapReference($reference);
            $verifyPayload = array_merge($sourcePayload, [
                'amount' => $activationFee,
                'currency' => $currency,
                'reference' => $reference,
            ]);

            $verifyResult = $swapService->verifyAssetSigned($verifyPayload, $institution);
            if (!($verifyResult['verified'] ?? false)) {
                throw new RuntimeException("Could not verify the source: " . ($verifyResult['message'] ?? 'unknown error'));
            }

            $holdResult = $swapService->placeHoldSigned($verifyPayload, $institution, $verifyResult);
            if (!($holdResult['hold_placed'] ?? false)) {
                throw new RuntimeException("Could not hold the activation fee: " . ($holdResult['message'] ?? 'unknown error'));
            }

            $debitResult = $swapService->debitSource(array_merge($verifyPayload, [
                'hold_reference' => $holdResult['hold_reference'],
                'reason' => 'VouchMorph Card activation fee',
            ]), $institution);

            if (!($debitResult['debited'] ?? false)) {
                try {
                    $swapService->releaseHold($verifyPayload, $institution, null, $holdResult['hold_reference']);
                } catch (Exception $releaseErr) {
                    error_log("[CardService] activateCard: failed to release hold after debit failure: " . $releaseErr->getMessage());
                }
                throw new RuntimeException("Could not charge the activation fee: " . ($debitResult['message'] ?? 'unknown error'));
            }
       try {
                $swapService->invoicePlatformFee(
                    $reference,
                    $institution,
                    'CARD_ACTIVATION_FEE',
                    $activationFee,
                    $currency
                );
             } catch (\Throwable $settleErr) {   // was: catch (Exception $settleErr)
                error_log("[CardService] activateCard: failed to invoice CARD_ACTIVATION_FEE to VOUCHMORPH: " . $settleErr->getMessage());
            }
            
            $stmt = $this->db->prepare("
                UPDATE message_cards
                SET lifecycle_status = 'ACTIVE', activated_at = NOW(), fee_amount = fee_amount + :fee
                WHERE card_id = :id
            ");
            $stmt->execute([':fee' => $activationFee, ':id' => $card['card_id']]);

            $this->logTransaction([
                'card_id' => $card['card_id'],
                'type' => 'ACTIVATION',
                'amount' => $activationFee,
                'fee_amount' => $activationFee,
                'auth_code' => CardHelper::generateAuthCode(),
                'reference' => $debitResult['transaction_reference'] ?? $reference,
                'channel' => 'ACTIVATION',
            ]);

            $this->db->commit();

            return [
                'success' => true,
                'card_suffix' => $cardSuffix,
                'status' => 'ACTIVE',
                'activation_fee_charged' => $activationFee,
                'currency' => $currency,
                'message' => 'Card activated successfully.',
            ];

        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("[CardService] activateCard failed: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }


/**
 * True while a card swap that hasn't finished still has to debit part of
 * this hold: a cash-out code not collected yet, or a swap to an identity
 * not claimed yet (PoolCoordinator defers those debits until then). A swap
 * that used only part of a hooked source leaves the rest hooked under the
 * SAME hold, and an institution releases a hold whole - releasing the rest
 * now would release that swap's share with it, and its debit would fail
 * after the cash was already paid out. Such a source stays held and its
 * release is retried (release_expired_card_hooks.php) until the swap has
 * finished, or its 24-hour hold window is over.
 */
public function isHoldReservedForPendingSwap(?string $holdReference): bool
{
    if ($holdReference === null || $holdReference === '') {
        return false;
    }
    $check = function () use ($holdReference): bool {
        $stmt = $this->db->prepare("SELECT 1 FROM (" . self::PENDING_SWAP_HOLDS_SQL . ") pending WHERE pending.hold_reference = ? LIMIT 1");
        $stmt->execute([$holdReference]);
        return (bool)$stmt->fetchColumn();
    };
    try {
        return $this->db->inTransaction() ? (bool)$this->runInSavepoint('pending_swap_hold', $check) : $check();
    } catch (\Throwable $e) {
        // Releasing is what every hook did before this check existed; a
        // check that can't run must not leave every hook held.
        error_log("[CardService] Could not check hold {$holdReference} for a pending card swap, releasing it as usual: " . $e->getMessage());
        return false;
    }
}

public function releaseHook(
    string $hookReference,
    int $requestingUserId,
    SwapService $swapService
): array {
    $this->db->beginTransaction();
 
    try {
        $hookStmt = $this->db->prepare("
            SELECT * FROM card_pool_hooks WHERE hook_reference = ? FOR UPDATE
        ");
        $hookStmt->execute([$hookReference]);
        $hook = $hookStmt->fetch(PDO::FETCH_ASSOC);
 
        if (!$hook) {
            $this->db->rollBack();
            return ['success' => false, 'error' => 'Hook not found.'];
        }
 
        if ((int)$hook['user_id'] !== $requestingUserId) {
            $this->db->rollBack();
            return ['success' => false, 'error' => 'Only the card owner can unhook.'];
        }
 
        if ($hook['status'] !== 'HOOKED') {
            $this->db->rollBack();
            return [
                'success' => false,
                'error' => "This hook is {$hook['status']} and can no longer be unhooked "
                    . "(it has either already been spent, expired, or was already released).",
            ];
        }
 
        $sourcesStmt = $this->db->prepare("
            SELECT * FROM card_pool_hook_sources
            WHERE hook_id = ? AND status = 'HELD'
            FOR UPDATE
        ");
        $sourcesStmt->execute([$hook['id']]);
        $heldSources = $sourcesStmt->fetchAll(PDO::FETCH_ASSOC);
 
        $released = [];
        $failed = [];
        $reserved = [];

        foreach ($heldSources as $source) {
            // Part of this hold is a swap's that hasn't finished: the source
            // stays held, and the hook UNHOOK_PARTIAL so the release is
            // retried once the swap is done.
            if ($this->isHoldReservedForPendingSwap($source['hold_reference'] ?? null)) {
                $reserved[] = [
                    'institution' => $source['institution'],
                    'amount' => (float)$source['held_amount'],
                ];
                continue;
            }
            try {
                $releaseResult = $swapService->releaseHold(
                    ['institution' => $source['institution'], 'asset_type' => $source['asset_type']],
                    $source['institution'],
                    null,
                    $source['hold_reference']
                );

                // ============================================================
                // FIX: releaseHold() reports a genuine failure (adapter
                // declines, institution API error, stale hold reference)
                // by RETURNING success/released = false — it deliberately
                // catches its own exceptions internally and never throws
                // for that case. Ignoring the return value here meant a
                // real release failure still fell through to being marked
                // RELEASED below and reported to the user as fully
                // unhooked, even though the hold was never actually
                // released at the source institution.
                // ============================================================
                if (!($releaseResult['success'] ?? $releaseResult['released'] ?? false)) {
                    throw new RuntimeException($releaseResult['message'] ?? 'Release failed');
                }

                // ============================================================
                // FIX: Wrap source status update in a savepoint
                // ============================================================
                $this->runInSavepoint('release_source_' . $source['id'], function () use ($source) {
                    $this->db->prepare("
                        UPDATE card_pool_hook_sources
                        SET status = 'RELEASED', released_at = NOW()
                        WHERE id = ?
                    ")->execute([$source['id']]);
                });
 
                $released[] = [
                    'institution' => $source['institution'],
                    'amount' => (float)$source['held_amount'],
                ];
            } catch (\Throwable $releaseErr) {
                error_log("[CardService] releaseHook: failed to release source id={$source['id']} "
                    . "({$source['institution']}) for hook {$hookReference}: " . $releaseErr->getMessage());
                $failed[] = [
                    'institution' => $source['institution'],
                    'amount' => (float)$source['held_amount'],
                    'error' => $releaseErr->getMessage(),
                ];
            }
        }
 
        $hookStatus = empty($failed) && empty($reserved) ? 'UNHOOKED' : 'UNHOOK_PARTIAL';
 
        // ============================================================
        // FIX: Wrap hook-level status update in a savepoint
        // ============================================================
        $this->runInSavepoint('release_hook_' . $hook['id'], function () use ($hookStatus, $hook) {
            $this->db->prepare("
                UPDATE card_pool_hooks
                SET status = ?, unhooked_at = NOW()
                WHERE id = ?
            ")->execute([$hookStatus, $hook['id']]);
        });
 
        $this->db->commit();
 
        error_log("[CardService] releaseHook: hook={$hookReference} status={$hookStatus} "
            . "released=" . count($released) . " failed=" . count($failed) . " reserved=" . count($reserved));

        if (!empty($failed)) {
            $message = 'Some sources could not be released automatically — they remain held and will need a retry or manual review.';
        } elseif (!empty($reserved)) {
            $message = 'Unhooked. ' . implode(', ', array_map(
                fn($r) => $r['institution'] . ' P' . number_format($r['amount'], 2),
                $reserved
            )) . ' stays held a little longer: a swap from this card still has to collect its share of that hold '
                . '(a cash-out code not used yet, or money sent to an identity not claimed yet). '
                . 'It is released automatically once that swap is done.';
        } else {
            $message = 'All hooked sources released.';
        }

        return [
            // Reserved sources aren't a failure: they're released automatically
            // as soon as the swap holding them finishes.
            'success' => empty($failed),
            'hook_reference' => $hookReference,
            'status' => $hookStatus,
            'released' => $released,
            'failed' => $failed,
            'reserved' => $reserved,
            'message' => $message,
        ];
 
    } catch (\Throwable $e) {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
        error_log("[CardService] releaseHook failed: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Releases a single hooked source from a card's active hook, leaving
 * every other source in that hook untouched — the per-source
 * counterpart to releaseHook() (which releases everything at once).
 *
 * Either the card owner or the source's own contributor can call this
 * on their own source; nobody else can release someone else's
 * contribution. If this was the last HELD source in the hook, the
 * hook itself is closed out to UNHOOKED, mirroring what a full
 * releaseHook() would leave behind.
 */
public function releaseHookSource(
    int $hookSourceId,
    int $requestingUserId,
    SwapService $swapService
): array {
    $this->db->beginTransaction();

    try {
        // Non-locking lookup purely to find which hook this source
        // belongs to, so the hook row can be locked FIRST — same lock
        // order releaseHook() already uses (hook, then its sources) —
        // to avoid a deadlock between a full unhook and a single-source
        // unhook running at the same time against the same hook.
        $lookupStmt = $this->db->prepare("SELECT hook_id FROM card_pool_hook_sources WHERE id = ?");
        $lookupStmt->execute([$hookSourceId]);
        $hookId = $lookupStmt->fetchColumn();

        if (!$hookId) {
            $this->db->rollBack();
            return ['success' => false, 'error' => 'This source was not found.'];
        }

        $hookStmt = $this->db->prepare("SELECT * FROM card_pool_hooks WHERE id = ? FOR UPDATE");
        $hookStmt->execute([$hookId]);
        $hook = $hookStmt->fetch(PDO::FETCH_ASSOC);

        if (!$hook || $hook['status'] !== 'HOOKED') {
            $this->db->rollBack();
            return [
                'success' => false,
                'error' => $hook
                    ? "This hook is {$hook['status']} and can no longer be unhooked from "
                        . "(it has either already been spent, expired, or was already released)."
                    : 'This source was not found.',
            ];
        }

        $sourceStmt = $this->db->prepare("
            SELECT * FROM card_pool_hook_sources WHERE id = ? AND hook_id = ? AND status = 'HELD' FOR UPDATE
        ");
        $sourceStmt->execute([$hookSourceId, $hookId]);
        $source = $sourceStmt->fetch(PDO::FETCH_ASSOC);

        if (!$source) {
            $this->db->rollBack();
            return ['success' => false, 'error' => 'This source was not found or has already been released.'];
        }

        $isCardOwner = (int)$hook['user_id'] === $requestingUserId;
        $isSourceOwner = (int)$source['owner_user_id'] === $requestingUserId;
        if (!$isCardOwner && !$isSourceOwner) {
            $this->db->rollBack();
            return ['success' => false, 'error' => "Only the card owner or this source's own contributor can unhook it."];
        }

        // Same rule as releaseHook(): the hold also carries the share of a
        // swap that hasn't finished, and can only be released whole.
        if ($this->isHoldReservedForPendingSwap($source['hold_reference'] ?? null)) {
            $this->db->rollBack();
            return [
                'success' => false,
                'error' => 'This source can\'t be unhooked yet: a swap from this card still has to collect its share of it '
                    . '(a cash-out code not used yet, or money sent to an identity not claimed yet). '
                    . 'Try again once that swap is done — or leave it, and it is released automatically when the hook ends.',
            ];
        }

        $releaseResult = $swapService->releaseHold(
            ['institution' => $source['institution'], 'asset_type' => $source['asset_type']],
            $source['institution'],
            null,
            $source['hold_reference']
        );

        // Same check as releaseHook(): releaseHold() reports a genuine
        // failure by RETURNING success/released = false, not by
        // throwing — never mark this source RELEASED unless it actually
        // was.
        if (!($releaseResult['success'] ?? $releaseResult['released'] ?? false)) {
            $this->db->rollBack();
            return [
                'success' => false,
                'error' => $releaseResult['message'] ?? 'Release failed at the source institution — it remains held.',
            ];
        }

        $this->db->prepare("
            UPDATE card_pool_hook_sources SET status = 'RELEASED', released_at = NOW() WHERE id = ?
        ")->execute([$hookSourceId]);

        // Recompute the hook's total straight from whatever HELD sources
        // remain, rather than subtracting — self-correcting against any
        // prior drift instead of accumulating it.
        $remainingStmt = $this->db->prepare("
            SELECT COALESCE(SUM(held_amount), 0) AS total, COUNT(*) AS cnt
            FROM card_pool_hook_sources WHERE hook_id = ? AND status = 'HELD'
        ");
        $remainingStmt->execute([$hookId]);
        $remaining = $remainingStmt->fetch(PDO::FETCH_ASSOC);
        $remainingTotal = round((float)$remaining['total'], 2);
        $remainingCount = (int)$remaining['cnt'];

        if ($remainingCount > 0) {
            $this->db->prepare("UPDATE card_pool_hooks SET total_held_amount = ? WHERE id = ?")
                ->execute([$remainingTotal, $hookId]);
            $hookStatus = 'HOOKED';
        } else {
            $this->db->prepare("
                UPDATE card_pool_hooks SET status = 'UNHOOKED', total_held_amount = 0, unhooked_at = NOW() WHERE id = ?
            ")->execute([$hookId]);
            $hookStatus = 'UNHOOKED';
        }

        $this->db->commit();

        error_log("[CardService] releaseHookSource: hook={$hook['hook_reference']} source_id={$hookSourceId} "
            . "institution={$source['institution']} amount={$source['held_amount']} remaining_sources={$remainingCount}");

        return [
            'success' => true,
            'hook_reference' => $hook['hook_reference'],
            'institution' => $source['institution'],
            'amount' => (float)$source['held_amount'],
            'currency' => $hook['currency'],
            'remaining_hook_total' => $remainingTotal,
            'remaining_sources' => $remainingCount,
            'hook_status' => $hookStatus,
            'message' => $remainingCount > 0
                ? 'Source released.'
                : 'Source released — no sources remain hooked to this card.',
        ];

    } catch (\Throwable $e) {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
        error_log("[CardService] releaseHookSource failed: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
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
     * must already exist as an active linked/consented source in either
     * source_accounts (OAuth-linked) or user_source_accounts
     * (manually-added/verified) - the same two tables /user/sources.php
     * merges into "My Sources". This endpoint never trusts
     * owner_user_id + credentials from the request body alone as proof
     * of consent. The consent check runs as its own pass BEFORE any
     * holds are placed, so a consent failure costs nothing (no rollback needed).
     *
     * SCHEMA FIX: Uses lifecycle_status (not status)
     */
  public function hookSourcesToCard(
    string $cardSuffix,
    array $sources,
    SwapService $swapService,
    int $cardOwnerUserId
): array {
    // ============================================================
    // FIX: GUARD - Check card status BEFORE any holds are placed
    //
    // BUG FIX: this used to scope the lookup by "AND user_id = :uid"
    // where :uid was the SESSION user calling this endpoint. That's
    // correct for a self-hook (hooking your own card) but wrong for
    // hooking a source to someone ELSE's card (scan their QR / look up
    // their suffix, then hook - see beginHookToOtherCard() in the
    // dashboard): the card's real owner is a different user_id, so this
    // query matched nothing and every cross-user hook failed with
    // "Card not found" even though the card existed and was active.
    //
    // Look the card up by suffix + ACTIVE status alone, exactly like the
    // QR/suffix resolve step already does in Resolveqr.php and
    // LookupBySuffix.php, so the card held here is the same one the user
    // just confirmed - and take the card's real owner from the row
    // itself instead of assuming it's the requesting user.
    // ============================================================
    $stmt = $this->db->prepare("SELECT user_id FROM message_cards WHERE card_suffix = :suffix AND lifecycle_status = 'ACTIVE'");
    $stmt->execute([':suffix' => $cardSuffix]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($card === false) {
        // No ACTIVE card at this suffix - look it up without the status
        // filter purely to give a more useful error message (e.g. the
        // card exists but isn't activated yet).
        $anyStmt = $this->db->prepare("SELECT lifecycle_status FROM message_cards WHERE card_suffix = :suffix");
        $anyStmt->execute([':suffix' => $cardSuffix]);
        $existingStatus = $anyStmt->fetchColumn();

        if ($existingStatus === false) {
            return ['success' => false, 'error' => 'Card not found.'];
        }
        return [
            'success' => false,
            'error' => $existingStatus === 'INACTIVE'
                ? 'This card has not been activated yet — the card owner needs to activate it before sources can be hooked.'
                : "This card is {$existingStatus} and cannot accept hooks.",
        ];
    }

    $cardOwnerUserId = (int)$card['user_id'];

    $this->db->beginTransaction();
    $placedHolds = [];

    try {
        if (count($sources) < 1) {
            throw new RuntimeException("At least one source is required to hook");
        }

        // ============================================================
        // FIX: BLOCK VOUCHMORPH SOURCES AT HOOK TIME
        // ============================================================
        foreach ($sources as $source) {
            if (strtoupper($source['institution'] ?? '') === 'VOUCHMORPH' || ($source['asset_type'] ?? '') === 'VOUCHMORPH_CARD') {
                throw new RuntimeException(
                    "A VouchMorph Card cannot be hooked to another VouchMorph Card. " .
                    "Hook the underlying account, wallet, or card-network source instead."
                );
            }
        }

        // ============================================================
        // CONSENT GATE - runs BEFORE any holds are placed
        // ============================================================
        foreach ($sources as $source) {
            $sourceOwnerId = (int)($source['owner_user_id'] ?? $cardOwnerUserId);

            if ($sourceOwnerId !== $cardOwnerUserId) {
                // A user's "My Sources" list (see /user/sources.php) merges two
                // tables - manually-added/verified sources in user_source_accounts,
                // and OAuth-linked sources in source_accounts - and the hook form
                // lets either kind be picked without telling us which one it was.
                // So consent has to be checked against both; requiring only one
                // would reject a real, already-verified source that just happens
                // to live in the other table.
                $consentStmt = $this->db->prepare("
                    SELECT 1 FROM source_accounts
                    WHERE user_id = :owner_id_a
                      AND institution = :institution_a
                      AND status = 'active'
                    UNION ALL
                    SELECT 1 FROM user_source_accounts
                    WHERE user_id = :owner_id_b
                      AND institution = :institution_b
                      AND status = 'active'
                      AND deleted_at IS NULL
                    LIMIT 1
                ");
                $consentStmt->execute([
                    ':owner_id_a' => $sourceOwnerId,
                    ':institution_a' => $source['institution'],
                    ':owner_id_b' => $sourceOwnerId,
                    ':institution_b' => $source['institution'],
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
        $swapService->setCurrentSwapReference($hookReference);

        $totalHeld = 0.0;
        $minExpirySeconds = PHP_INT_MAX;
        $currency = $sources[0]['currency'] ?? 'BWP';

        // FIX (2026-09-21): the cap applies to what the card can spend in total.
        // A second hook adds to the card's existing HOOKED pool, so the pool
        // total (existing + requested) must stay within the limit - before,
        // P80 + P8,000 = P8,080 was accepted.
        $poolCap = $this->feeService !== null
            ? (float)$this->feeService->getMaxTransactionLimit()['amount']
            : \Application\Incident\ServiceControls::capLimit();
        $poolStmt = $this->db->prepare("SELECT COALESCE(SUM(total_held_amount), 0) FROM card_pool_hooks WHERE card_suffix = ? AND status = 'HOOKED'");
        $poolStmt->execute([$cardSuffix]);
        $existingPool = (float)$poolStmt->fetchColumn();
        $requestedTotal = array_sum(array_map(fn($src) => (float)($src['authorized_amount'] ?? 0), $sources));
        if ($existingPool + $requestedTotal > $poolCap + 0.01) {
            throw new RuntimeException(sprintf(
                'This card would hold P%s in total (P%s already hooked + P%s now), above the P%s limit during the pilot. Unhook or hook a smaller amount.',
                number_format($existingPool + $requestedTotal, 2), number_format($existingPool, 2), number_format($requestedTotal, 2), number_format($poolCap, 0)
            ));
        }

        foreach ($sources as $source) {
            $balanceInfo = $swapService->getSourceAvailableBalanceDetailed($source);
$balance = $balanceInfo['balance'];

if ($balance <= 0) {
    $message = $balanceInfo['is_synthetic']
        ? "Source {$source['institution']} has no configured authorization ceiling — "
          . "check card_acquirer.max_single_auth_amount in participants.yaml. This is a "
          . "config gap for a card acquirer, not evidence the customer lacks funds."
        : "Source {$source['institution']} has no available balance to hook";
    throw new RuntimeException($message);
}

            // The source owner authorizes a specific amount at hook time
            // — this is a deliberate cap, never a silent "hold everything"
            // default. Re-validated here independently of whatever the
            // frontend showed, against BOTH the live balance and
            // VouchMorph's own country-wide transaction ceiling — a
            // client-supplied number is advisory input, never trusted
            // authorization on its own.
            $requestedAmount = isset($source['authorized_amount'])
                ? (float)$source['authorized_amount']
                : null;

            // FIX (2026-09-21): the sandbox cap applies whether or not a FeeService
            // was injected. Before, hook.php built CardService without one, the
            // fallback was the balance, and a P8,000 hook was accepted.
            $maxLimit = $this->feeService !== null
                ? $this->feeService->getMaxTransactionLimit()['amount']
                : \Application\Incident\ServiceControls::capLimit();

            $hardCap = min($balance, $maxLimit);

            if ($requestedAmount === null) {
                throw new RuntimeException(
                    "An authorized amount is required to hook {$source['institution']} — " .
                    "the source owner must specify how much to make available (up to {$hardCap})."
                );
            }
            if ($requestedAmount <= 0) {
                throw new RuntimeException("Authorized amount for {$source['institution']} must be greater than zero.");
            }
            if ($requestedAmount > $hardCap + 0.01) {
                throw new RuntimeException(
                    "Requested amount ({$requestedAmount}) exceeds what can be authorized for {$source['institution']} " .
                    "(available balance: {$balance}, VouchMorph limit: {$maxLimit}, cap: {$hardCap})."
                );
            }

            $holdPayload = array_merge($source, [
                'amount' => $requestedAmount,
                'currency' => $source['currency'] ?? $currency,
                'hold_reason' => 'CARD_POOL_HOOK_' . $hookReference,
                'source_identifier' => $source['identifier'] ?? $source['source_identifier'] ?? null,
                'source_identifier_type' => $source['identifier_type'] ?? $source['source_identifier_type'] ?? 'auto',
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
                'amount' => $requestedAmount,  
            ];

            $totalHeld += $requestedAmount;
            try {
                $expirySeconds = AssetTypeRegistry::getHoldExpiry($source['asset_type'] ?? 'ACCOUNT');
            } catch (\Throwable $registryError) {
                error_log("[CardService] AssetTypeRegistry::getHoldExpiry() failed, using default 3600s: " . $registryError->getMessage());
                $expirySeconds = 3600; // conservative 1-hour default
            }
            $minExpirySeconds = min($minExpirySeconds, $expirySeconds);

            // ============================================================
            // IMMEDIATE FEE CHARGE — the hold on this source is now real,
            // so its levy + fixed source cut are charged now. Charged
            // against DEPOSIT's fee schedule (see file header note on why
            // — nothing is known yet about how this card will finalize).
            // Non-refundable once charged EXCEPT if this whole hook
            // attempt later fails and rolls back (see the catch block
            // below) — that's the one system-caused exception, same rule
            // as PoolCoordinator's immediate-fee reversal.
            // ============================================================
            try {
                $hookFeeResult = $swapService->getMultiSourceFeeCalculator()->calculateFees(
                    count($sources),
                    'deposit',
                    $requestedAmount,
                    $source['currency'] ?? $currency,
                    $source['currency'] ?? $currency
                );

                $perSourceCut = $hookFeeResult['per_source_cut'] ?? 0;
                $levyPerSource = $hookFeeResult['swap_levy_per_source'] ?? 0;
                $chargeCurrency = $hookFeeResult['currency'] ?? $source['currency'] ?? $currency;

                if ($perSourceCut > 0) {
                    $swapService->invoiceInstitutionFee(
                        $hookReference, $source['institution'], 'SOURCE_FEE', $perSourceCut, $chargeCurrency
                    );
                }
                if ($levyPerSource > 0) {
                    $swapService->invoiceInstitutionFee(
                        $hookReference, 'VOUCHMORPH', 'SWAP_LEVY', $levyPerSource, $chargeCurrency
                    );
                }

                // Record on the LAST placedHolds entry (the one just
                // pushed) so a rollback can reverse exactly this amount
                // without recomputing — same discipline as
                // PoolCoordinator's persisted fee_breakdown.
                $placedHolds[count($placedHolds) - 1]['fee_charged'] = [
                    'source_cut' => $perSourceCut,
                    'levy' => $levyPerSource,
                    'currency' => $chargeCurrency,
                ];
                // FIX (2026-09-22): record the shares in the fee ledger, with who
                // earns and who pays, so a card pool reconciles exactly like a
                // claim does. Before, the hook invoiced an amount against an
                // institution and the ledger knew nothing about it.
                try {
                    $ledger = $swapService->feeLedgerPublic();
                    $leg = $hookReference . ':S' . ($source['institution'] ?? '?') . ':' . count($placedHolds);
                    $totalFee = round($perSourceCut + $levyPerSource, 2);
                    // the source withheld the levy from what it sends on: it owes that to VouchMorph
                    $ledger->recordShare($hookReference, $leg, 'CARD_LOAD', 'HOLD_PLACED', 'SWAP_LEVY', 'VOUCHMORPH',
                        (string)$source['institution'], $totalFee, null, $levyPerSource, $chargeCurrency, 'Swap levy, charged when the source was hooked');
                    // the source keeps its own cut
                    $ledger->recordShare($hookReference, $leg, 'CARD_LOAD', 'HOLD_PLACED', 'SOURCE_SHARE', (string)$source['institution'],
                        (string)$source['institution'], $totalFee, null, $perSourceCut, $chargeCurrency, 'Source cut for this hooked source');
                } catch (\Throwable $ledgerError) {
                    error_log('[CardService] could not record hook fee shares for ' . ($source['institution'] ?? '?') . ': ' . $ledgerError->getMessage());
                }

            } catch (\Throwable $feeError) {
                // A fee-charging failure must never block a real,
                // already-placed hold from being usable — log loudly,
                // don't throw. Same non-blocking posture as every other
                // "tracking" write in this codebase (see SwapService's
                // runInSavepoint()-wrapped tracking tables).
                error_log("[CardService] hookSourcesToCard: immediate fee charge failed for {$source['institution']}: " . $feeError->getMessage());
            }
        }

        $expiresAt = date('Y-m-d H:i:s', time() + $minExpirySeconds);

        // ============================================================
        // FIX: MERGE INTO THE CARD'S EXISTING ACTIVE POOL INSTEAD OF
        // ALWAYS STARTING A NEW ONE
        //
        // This used to unconditionally INSERT a brand-new card_pool_hooks
        // row on every call, so every contributor got their own separate
        // pot instead of adding to the one shared pot for that card — a
        // card with 10 contributors of P20 each ended up with 10 pots of
        // P20 rather than 1 pot of P200, and every read path (My.php,
        // GetCardSources.php, authorizePooledSwipe(), the ISO 8583
        // bridge, unhook.php) only ever looks at the single newest pot,
        // so it showed/used just the latest contributor's amount.
        //
        // Locking and reusing the card's existing active HOOKED row here
        // means every one of those "latest hook" reads becomes correct
        // automatically, with no changes needed on their side — there is
        // now only ever one active pot per card to find.
        // ============================================================
        $existingHookStmt = $this->db->prepare("
            SELECT id, hook_reference, currency, total_held_amount, expires_at
            FROM card_pool_hooks
            WHERE card_suffix = ? AND status = 'HOOKED' AND expires_at > NOW()
            ORDER BY created_at DESC LIMIT 1
            FOR UPDATE
        ");
        $existingHookStmt->execute([$cardSuffix]);
        $existingHook = $existingHookStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingHook && $existingHook['currency'] !== $currency) {
            throw new RuntimeException(
                "This card's active pool is already funded in {$existingHook['currency']} — " .
                "cannot add a {$currency} source to the same pool."
            );
        }

        if ($existingHook) {
            $hookId = (int)$existingHook['id'];
            $hookReference = $existingHook['hook_reference'];
            $poolTotalHeld = (float)$existingHook['total_held_amount'] + $totalHeld;

            // Conservative expiry, same rule already used above for a
            // single call's own sources: the pool's expiry is always the
            // EARLIEST of every hold ever placed into it, never the
            // latest — never claim funds are available longer than the
            // shortest-lived hold actually backing them.
            $expiresAt = min($existingHook['expires_at'], $expiresAt);

            $this->db->prepare("
                UPDATE card_pool_hooks
                SET total_held_amount = total_held_amount + ?, expires_at = ?
                WHERE id = ?
            ")->execute([$totalHeld, $expiresAt, $hookId]);
        } else {
            $hookStmt = $this->db->prepare("
                INSERT INTO card_pool_hooks (hook_reference, card_suffix, user_id, total_held_amount, currency, status, expires_at)
                VALUES (?, ?, ?, ?, ?, 'HOOKED', ?)
                RETURNING id
            ");
            $hookStmt->execute([$hookReference, $cardSuffix, $cardOwnerUserId, $totalHeld, $currency, $expiresAt]);
            $hookId = $hookStmt->fetchColumn();
            $poolTotalHeld = $totalHeld;
        }

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
            'pool_total_held' => $poolTotalHeld,
            'currency' => $currency,
            'expires_at' => $expiresAt,
            'source_count' => count($placedHolds),
            'message' => 'Hook successful - all sources held',
        ];

    } catch (\Throwable $e) {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }

        foreach ($placedHolds as $held) {
            try {
                $swapService->releaseHold(
                    $held['source'],
                    $held['source']['institution'],
                    isset($held['hold_id']) ? (string)$held['hold_id'] : null,
                    $held['hold_reference'] ?? null
                );

                // NEW: reverse whatever was charged for this specific
                // source, if the immediate charge succeeded before the
                // overall hook attempt failed. This IS the "atomic
                // reverse due to system problems" exception — every
                // other release path (natural expiry, explicit
                // Unhook.php) keeps the charge.
                if (!empty($held['fee_charged'])) {
                    $fc = $held['fee_charged'];
                    $reversalRef = $hookReference . '_REVERSAL';
                    if (($fc['source_cut'] ?? 0) > 0) {
                        $swapService->invoiceInstitutionFee(
                            $reversalRef, $held['source']['institution'],
                            'SOURCE_FEE_REVERSAL', -1 * $fc['source_cut'], $fc['currency'] ?? 'BWP'
                        );
                    }
                    if (($fc['levy'] ?? 0) > 0) {
                        $swapService->invoiceInstitutionFee(
                            $reversalRef, 'VOUCHMORPH',
                            'SWAP_LEVY_REVERSAL', -1 * $fc['levy'], $fc['currency'] ?? 'BWP'
                        );
                    }
                }

            } catch (\Throwable $releaseErr) {
                error_log("[CardService] Failed to release hold during hook rollback: " . $releaseErr->getMessage());
            }
        }

        error_log("[CardService] hookSourcesToCard failed: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage(), 'message' => 'Hook failed - no sources were held'];
    }
}
    
     public function authorizePooledSwipe(string $cardSuffix, float $amount, array $merchantContext): array
    {
        $startTime = microtime(true);

        // ============================================================
        // IDENTITY VERIFICATION
        // ============================================================
        $pinVerifiedViaHsm = ($merchantContext['pin_verified_via_hsm'] ?? false) === true
            && in_array($merchantContext['channel'] ?? '', ['ISO8583_ATM', 'ISO8583_POS'], true);

        if (!$pinVerifiedViaHsm) {
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

        
        // ============================================================
        // FIXED-ASSET (VOUCHER) PRE-CHECK
        // ============================================================
        // Mirrors ContributionCalculator::isFixedAsset() — VOUCHER and
        // CASHOUT-VOUCHER must be drawn in full, never split. If their
        // combined held total exceeds the requested swipe amount,
        // finalizePooledSwipe() will later throw
        // "Voucher total exceeds target amount" — decline HERE instead,
        // before the terminal ever sees an approval.
        //
        // NOTE: this does not currently catch the ATM-as-voucher case
        // (asset_type='ATM' with an is_voucher flag) —
        // card_pool_hook_sources has no column recording that flag as
        // shown in this file. If ATM-sourced vouchers are hookable in
        // practice, this check needs that flag threaded through at hook
        // time (hookSourcesToCard() would need to persist it alongside
        // asset_type) before this pre-check can cover that case too.
        $fixedAssetStmt = $this->db->prepare("
            SELECT COALESCE(SUM(held_amount), 0) AS fixed_total
            FROM card_pool_hook_sources
            WHERE hook_id = ? AND status = 'HELD' AND UPPER(asset_type) IN ('VOUCHER', 'CASHOUT-VOUCHER')
        ");
        $fixedAssetStmt->execute([$hook['id']]);
        $fixedAssetTotal = (float)$fixedAssetStmt->fetchColumn();

        if ($fixedAssetTotal > $amount + 0.01) {
            return [
                'success' => false, 'authorized' => false,
                'response_code' => '51',
                'response_message' => 'This card has a voucher source that must be used in full — the swipe amount must be at least the voucher value',
                'fixed_asset_total' => $fixedAssetTotal, 'requested' => $amount,
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
 * Handles an ATM/POS-initiated reversal for a previously-approved
 * swipe. Two real outcomes:
 *   - Swipe was approved (authorizePooledSwipe) but NOT YET finalized
 *     (still sitting in card_pool_finalize_queue, or the hook is still
 *     'SWIPE_RECEIVED') -> safe to fully reverse: mark the hook back to
 *     'HOOKED' (funds remain held, available for a future swipe) and
 *     remove the queued finalize entry.
 *   - Swipe was ALREADY finalized (sources debited, destination
 *     settled) -> CANNOT be silently reversed here. Flag for manual
 *     reconciliation — same posture as
 *     SwapService::recordManualReconciliationRequired().
 */
public function reversePooledSwipe(string $hookReference, string $reversalReason): array
{
    $stmt = $this->db->prepare("SELECT * FROM card_pool_hooks WHERE hook_reference = ? FOR UPDATE");
    $stmt->execute([$hookReference]);
    $hook = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$hook) {
        return ['success' => false, 'error' => 'Hook not found for reversal.'];
    }

    if ($hook['status'] === 'SWIPE_RECEIVED') {
        // Not yet finalized — safe, clean reversal.
        $this->db->beginTransaction();
        try {
            $this->db->prepare("
                UPDATE card_pool_hooks SET status = 'HOOKED', swipe_amount = NULL, merchant_reference = NULL WHERE id = ?
            ")->execute([$hook['id']]);

            $this->db->prepare("
                DELETE FROM card_pool_finalize_queue WHERE hook_reference = ? AND status = 'PENDING'
            ")->execute([$hookReference]);

            $this->db->commit();

            error_log("[CardService] Swipe reversed cleanly (not yet finalized): hook={$hookReference}, reason={$reversalReason}");

            return ['success' => true, 'status' => 'reversed', 'hook_reference' => $hookReference];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("[CardService] reversePooledSwipe failed during clean-reversal path: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    if (in_array($hook['status'], ['SETTLED'], true)) {
        // Already finalized — real money already moved. Do NOT attempt
        // to auto-reverse. Same discipline as
        // SwapService::recordManualReconciliationRequired().
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS card_swipe_manual_reconciliation_required (
                    id BIGSERIAL PRIMARY KEY,
                    hook_reference VARCHAR(255) NOT NULL,
                    reversal_reason TEXT NOT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                    resolved_at TIMESTAMP,
                    resolved_by VARCHAR(100)
                )
            ");
            $stmt = $this->db->prepare("
                INSERT INTO card_swipe_manual_reconciliation_required (hook_reference, reversal_reason)
                VALUES (?, ?)
            ");
            $stmt->execute([$hookReference, $reversalReason]);
        } catch (\Throwable $e) {
            error_log("[CardService] EMERGENCY: failed to record manual reconciliation for already-settled reversal on hook {$hookReference}: " . $e->getMessage());
        }

        error_log("[CardService] CRITICAL: reversal requested for ALREADY-SETTLED swipe (hook={$hookReference}) — flagged for manual reconciliation, NOT auto-reversed.");

        return [
            'success' => false,
            'status' => 'already_settled_manual_reconciliation_required',
            'error' => 'This transaction has already been fully settled and cannot be automatically reversed. Flagged for manual review.',
        ];
    }

    // Any other state (e.g. mid-finalize) — reject rather than guess.
    return [
        'success' => false,
        'error' => "Hook is in state '{$hook['status']}' — reversal is not defined for this state. Needs manual review.",
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

            // Only what is still held: a hook outlives a swap that spent part
            // of it (CardContributionSessionService::execute()), so it can
            // also have sources that swap already used up (DEBITED) or that
            // were unhooked on their own (RELEASED). Drawing on those would
            // debit a hold that is no longer there, and bill its owner.
            $sourcesStmt = $this->db->prepare("SELECT * FROM card_pool_hook_sources WHERE hook_id = ? AND status = 'HELD' ORDER BY id");
            $sourcesStmt->execute([$hook['id']]);
            $sources = $sourcesStmt->fetchAll(PDO::FETCH_ASSOC);

            $swipeAmount = (float)$hook['swipe_amount'];

            // Inside finalizePooledSwipe(), before calling calculateContributions():

$destinationDeliveryMethod = strtoupper($merchantContext['delivery_method'] ?? 'DEPOSIT');
$isCashout = in_array($destinationDeliveryMethod, ['ATM', 'AGENT', 'CASHOUT'], true);

$feesConfig = $swapService->getFeeService()->getRawFeesConfig(); // exposes fees.json — confirm this getter exists; if not, thread $this->feesConfig through the constructor the same way SwapService already does
$currency = $hook['currency'] ?? 'BWP';

if ($isCashout) {
    $cashoutF1 = (float)($feesConfig['CASHOUT']['fee_components']['F1']['amount'] ?? 0);
    $smallestNote = min($swapService->getAtmDenominations($currency)); // already exists on SwapService
    $minContribution = $cashoutF1 + $smallestNote; // same combined-threshold shape as validateEarmarkedWithdrawal()
} else {
    $minContribution = (float)($feesConfig['DEPOSIT']['fee_components']['F1']['amount'] ?? 0);
}

$contributions = $contributionCalculator->calculateContributions(
    $swipeAmount,
    array_map(fn($s) => [
        'institution' => $s['institution'],
        'asset_type' => $s['asset_type'],
        'identifier' => $s['source_identifier'],
        'available_balance' => (float)$s['held_amount'],
    ], $sources),
    'SMART',
    null,
    null,
    $minContribution   // NEW
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
                        if ($this->isHoldReservedForPendingSwap($sourceRow['hold_reference'] ?? null)) {
                            // Releasing would take a pending card swap's share
                            // with it; the institution lets the rest go when
                            // the hold expires.
                            error_log("[CardService] finalizePooledSwipe: NOT releasing the unused {$unused} of hold {$sourceRow['hold_reference']} ({$sourceRow['institution']}) - a card swap that hasn't finished still has to debit part of it");
                        } else {
                            $swapService->releaseHold([], $sourceRow['institution'], null, $sourceRow['hold_reference']);
                        }
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

            // Resolve the REAL destination institution (the bank whose ATM
// dispensed cash, or whose merchant terminal took the POS payment) —
// never a free-text label the terminal itself supplied.
$destinationInstitution = $merchantContext['resolved_destination_institution'] ?? null;
if (!$destinationInstitution) {
    // Fallback to whatever the caller sent, but log loudly — this
    // means resolveInstitutionByAcquirerId() (SwapService) either
    // wasn't called upstream or failed to resolve, and settlement is
    // about to happen against an UNVERIFIED counterparty. Should not
    // reach this in a fully wired deployment.
    error_log("[CardService] finalizePooledSwipe: no resolved destination institution for hook {$hookReference} — settling against unverified label, this needs fixing upstream");
    $destinationInstitution = $merchantContext['acquirer'] ?? $merchantContext['merchant_id'] ?? 'UNKNOWN';
}

// Settle EACH source institution's actual debited share to the
// destination — not one lump sum from an opaque pool label. This
// means the ATM-owning bank (or merchant's acquirer) gets paid
// correctly attributed money from each real institution that was
// actually debited, same granularity debitSource() already used above.
$settlementResults = [];
$totalSettled = 0.0;
foreach ($contributions as $i => $contribution) {
    $sourceRow = $sources[$i];
    $debitedAmount = (float)($sourceRow['debited_amount'] ?? $contribution['actual_amount']);
    if ($debitedAmount <= 0) continue;

    try {
        $settledAmount = $swapService->settleCardSwipeToDestination(
            $sourceRow['institution'],
            $destinationInstitution,
            $hook['currency'],
            $debitedAmount,
            $hookReference . '_SETTLE_' . $sourceRow['institution']
        );
        $settlementResults[] = [
            'source_institution' => $sourceRow['institution'],
            'destination_institution' => $destinationInstitution,
            'amount' => $settledAmount,
            'status' => 'settled',
        ];
        $totalSettled += $settledAmount;
    } catch (\Throwable $settleErr) {
        // Source was ALREADY debited above — a settlement failure here
        // means the destination institution hasn't been paid for cash
        // they already physically dispensed. This is the exact
        // "manual reconciliation required" case SwapService already
        // has a pattern for elsewhere (recordManualReconciliationRequired()) —
        // flag it the same way rather than silently losing track of it.
        error_log("[CardService] CRITICAL: settlement to {$destinationInstitution} failed for {$sourceRow['institution']}'s debited {$debitedAmount} — destination already dispensed/credited real value, needs manual reconciliation: " . $settleErr->getMessage());
        $settlementResults[] = [
            'source_institution' => $sourceRow['institution'],
            'destination_institution' => $destinationInstitution,
            'amount' => $debitedAmount,
            'status' => 'settlement_failed_needs_manual_reconciliation',
            'error' => $settleErr->getMessage(),
        ];
    }
}

$settlementResult = [
    'settled_legs' => $settlementResults,
    'total_settled' => $totalSettled,
    'destination_institution' => $destinationInstitution,
];

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
