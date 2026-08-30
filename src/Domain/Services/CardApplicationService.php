<?php

declare(strict_types=1);

namespace Domain\Services;

require_once __DIR__ . '/KYCDocumentService.php';
require_once __DIR__ . '/../../Infrastructure/Cards/CardNumberGenerator.php';
require_once __DIR__ . '/../Helpers/CardHelper.php';

use PDO;
use Exception;
use RuntimeException;
use Domain\Services\KYCDocumentService;
use Infrastructure\Cards\CardNumberGenerator;
use Domain\Helpers\CardHelper;

/**
 * CardApplicationService - Handles card applications for general public
 */
class CardApplicationService
{
    private PDO $db;
    private string $countryCode;
    private array $config;
    private CardNumberGenerator $cardGenerator;
    private KYCDocumentService $kycService;
    
    public function __construct(PDO $db, string $countryCode, array $config)
    {
        $this->db = $db;
        $this->countryCode = $countryCode;
        $this->config = $config;
        $this->cardGenerator = new CardNumberGenerator($config);
        $this->kycService = new KYCDocumentService($db);
    }
    
    /**
     * Process a new card application
     */
    public function processApplication(array $data): array
    {
        $this->db->beginTransaction();
        
        try {
            // 1. Create or get user
            $user = $this->getOrCreateUser($data);
            
            // 2. Create application record
            $applicationId = $this->createApplication($user['user_id'], $data);
            
            // 3. For physical cards, assign from inventory
            if ($data['card_type'] === 'PHYSICAL') {
                $card = $this->assignPhysicalCard($user['user_id'], $data);
            } else {
                // Virtual card - generate new
                $card = $this->generateVirtualCard($user['user_id'], $data);
            }
            
            // 4. Update application with card ID
            $this->linkCardToApplication($applicationId, $card['card_id']);
            
            $this->db->commit();
            
            // 5. Queue KYC document upload notification
            $this->kycService->queueDocumentRequest($user['user_id'], $applicationId);
            
            return [
                'success' => true,
                'application_id' => $applicationId,
                'card_suffix' => $card['card_suffix'],
                'card_type' => $data['card_type'],
                'status' => $card['lifecycle_status'],
                'next_step' => 'Please upload KYC documents',
                'kyc_required' => true,
                'message' => $this->getStatusMessage($card['lifecycle_status'])
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Application processing failed: " . $e->getMessage());
            throw new RuntimeException("Application failed: " . $e->getMessage());
        }
    }
    
    /**
     * Get or create user from application data - FIXED for your schema
     */
    private function getOrCreateUser(array $data): array
    {
        // Check if user exists by phone (phone is UNIQUE in your schema)
        $stmt = $this->db->prepare("
            SELECT * FROM users WHERE phone = :phone
        ");
        $stmt->execute([':phone' => $data['phone']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            return $user;
        }
        
        // Check by email as well
        $stmt = $this->db->prepare("
            SELECT * FROM users WHERE email = :email
        ");
        $stmt->execute([':email' => $data['email']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            return $user;
        }
        
        // Create new user with your actual schema (no full_name column)
        $username = $this->generateUsername($data['email']);
        $tempPassword = bin2hex(random_bytes(8)); // Generate temporary password
        
        $stmt = $this->db->prepare("
            INSERT INTO users (
                username, email, phone, password_hash, role_id, 
                verified, kyc_verified, created_at
            ) VALUES (
                :username, :email, :phone, :password, 1, 
                false, false, NOW()
            ) RETURNING user_id, username, email, phone
        ");
        
        $stmt->execute([
            ':username' => $username,
            ':email' => $data['email'],
            ':phone' => $data['phone'],
            ':password' => password_hash($tempPassword, PASSWORD_DEFAULT)
        ]);
        
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Store personal information in kyc_documents table
        $this->storeUserProfile($user['user_id'], $data);
        
        return $user;
    }
    
    /**
     * Store user profile information in kyc_documents
     */
    private function storeUserProfile(int $userId, array $data): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO kyc_documents (
                user_id, document_type, document_number, status, metadata, submitted_at
            ) VALUES (
                :user_id, 'identity', :id_number, 'pending', :metadata, NOW()
            )
        ");
        
        $stmt->execute([
            ':user_id' => $userId,
            ':id_number' => $data['id_number'],
            ':metadata' => json_encode([
                'full_name' => $data['full_name'],
                'id_type' => $data['id_type'],
                'date_of_birth' => $data['date_of_birth'],
                'address' => $data['address'] ?? null,
                'occupation' => $data['occupation'] ?? null,
                'employer' => $data['employer'] ?? null,
                'income_range' => $data['income_range'] ?? null,
                'source_of_funds' => $data['source_of_funds'] ?? null
            ])
        ]);
    }
    
    /**
     * Generate username from email
     */
    private function generateUsername(string $email): string
    {
        $base = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', explode('@', $email)[0]));
        $base = substr($base, 0, 15); // Limit length
        return $base . '_' . rand(100, 999);
    }
    
    /**
     * Create application record
     */
    private function createApplication(int $userId, array $data): string
    {
        $applicationId = 'APP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        
        $stmt = $this->db->prepare("
            INSERT INTO card_applications (
                application_id, user_id, full_name, id_number, id_type,
                date_of_birth, phone, email, occupation, income_range,
                source_of_funds, card_type, delivery_address, delivery_method, branch_location,
                status, consent, created_at, submitted_at
            ) VALUES (
                :app_id, :user_id, :full_name, :id_number, :id_type,
                :dob, :phone, :email, :occupation, :income_range,
                :source_of_funds, :card_type, :address, :delivery_method, :branch_location,
                'PENDING_KYC', :consent, NOW(), NOW()
            )
        ");
        
        $stmt->execute([
            ':app_id' => $applicationId,
            ':user_id' => $userId,
            ':full_name' => $data['full_name'],
            ':id_number' => $data['id_number'],
            ':id_type' => $data['id_type'],
            ':dob' => $data['date_of_birth'],
            ':phone' => $data['phone'],
            ':email' => $data['email'],
            ':occupation' => $data['occupation'] ?? null,
            ':income_range' => $data['income_range'] ?? null,
            ':source_of_funds' => $data['source_of_funds'] ?? null,
            ':card_type' => $data['card_type'],
            ':address' => json_encode($data['delivery_address'] ?? null),
            ':delivery_method' => $data['delivery_method'] ?? 'BRANCH_PICKUP',
            ':branch_location' => $data['branch_location'] ?? null,
            ':consent' => json_encode($data['consent'] ?? [])
        ]);
        
        return $applicationId;
    }
    
    /**
     * Assign physical card from inventory
     */
    private function assignPhysicalCard(int $userId, array $data): array
    {
        // Find available card in inventory
        $cardStmt = $this->db->prepare("
            SELECT * FROM message_cards 
            WHERE card_category = 'PHYSICAL'
            AND lifecycle_status = 'IN_BATCH'
            AND user_id IS NULL
            LIMIT 1
            FOR UPDATE
        ");
        $cardStmt->execute();
        $card = $cardStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$card) {
            throw new RuntimeException("No physical cards available. Please try virtual card.");
        }
        
        // Assign card to user
        $updateStmt = $this->db->prepare("
            UPDATE message_cards 
            SET user_id = :user_id,
                cardholder_name = :name,
                lifecycle_status = 'ASSIGNED',
                batch_assigned_at = NOW(),
                delivery_method = 'BRANCH_PICKUP',
                updated_at = NOW()
            WHERE card_id = :card_id
            RETURNING *
        ");
        
        $updateStmt->execute([
            ':user_id' => $userId,
            ':name' => $data['full_name'],
            ':card_id' => $card['card_id']
        ]);
        
        $assignedCard = $updateStmt->fetch(PDO::FETCH_ASSOC);
        
        // Update batch remaining count
        $this->db->prepare("
            UPDATE card_batches 
            SET quantity_remaining = quantity_remaining - 1
            WHERE batch_id = ?
        ")->execute([$card['batch_id']]);
        
        return $assignedCard;
    }
    
    /**
     * Generate virtual card
     */
    private function generateVirtualCard(int $userId, array $data): array
    {
        // card_suffix is only 4 digits and CardNumberGenerator picks it at
        // random with no DB awareness -- it can and does collide with
        // other cards. Retry generation until the suffix is confirmed
        // unused, rather than assuming card_suffix alone identifies a
        // single card everywhere it's later looked up by.
        $cardDetails = null;
        $suffixCheckStmt = $this->db->prepare("SELECT 1 FROM message_cards WHERE card_suffix = :suffix LIMIT 1");
        for ($attempt = 0; $attempt < 25; $attempt++) {
            $candidate = $this->cardGenerator->generateForPurpose('general');
            $suffixCheckStmt->execute([':suffix' => $candidate['pan_suffix']]);
            if (!$suffixCheckStmt->fetchColumn()) {
                $cardDetails = $candidate;
                break;
            }
        }
        if ($cardDetails === null) {
            throw new RuntimeException("generateVirtualCard: could not generate a unique card_suffix after 25 attempts for user_id={$userId}");
        }

        $stmt = $this->db->prepare("
            INSERT INTO message_cards (
                card_number_hash,
                card_suffix,
                cvv_hash,
                card_category,
                card_scheme,
                user_id,
                cardholder_name,
                initial_amount,
                remaining_amount,
                currency,
                lifecycle_status,
                financial_status,
                issued_at,
                expiry_year,
                expiry_month,
                metadata
            ) VALUES (
                :hash,
                :suffix,
                :cvv_hash,
                'VIRTUAL',
                :scheme,
                :user_id,
                :name,
                0,
                0,
                'BWP',
                'ISSUED',
                'UNFUNDED',
                NOW(),
                :expiry_year,
                :expiry_month,
                :metadata
            ) RETURNING *
        ");
        
        $stmt->execute([
            ':hash' => $cardDetails['pan_hash'],
            ':suffix' => $cardDetails['pan_suffix'],
            ':cvv_hash' => $cardDetails['cvv_hash'],
            ':scheme' => $cardDetails['brand'],
            ':user_id' => $userId,
            ':name' => $data['full_name'],
            ':expiry_year' => $cardDetails['expiry_year'],
            ':expiry_month' => $cardDetails['expiry_month'],
            ':metadata' => json_encode([
                'source' => 'public_application',
                'id_type' => $data['id_type']
            ])
        ]);
        
        $card = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Send virtual card details via secure channel
        $this->sendVirtualCardDetails(
            $data['phone'],
            $cardDetails['pan_formatted'],
            $cardDetails['cvv'],
            $cardDetails['expiry_formatted']
        );
        
        return $card;
    }
    
    /**
     * Link card to application
     */
    private function linkCardToApplication(string $applicationId, int $cardId): void
    {
        $stmt = $this->db->prepare("
            UPDATE card_applications 
            SET card_id = :card_id
            WHERE application_id = :app_id
        ");
        $stmt->execute([
            ':card_id' => $cardId,
            ':app_id' => $applicationId
        ]);
    }
    
    /**
     * Send virtual card details via SMS
     */
    private function sendVirtualCardDetails(string $phone, string $cardNumber, string $cvv, string $expiry): void
    {
        $message = "Your VouchMorph Virtual Card\n"
                 . "Card: {$cardNumber}\n"
                 . "Expiry: {$expiry}\n"
                 . "CVV: {$cvv}\n"
                 . "Keep this secure!";
        
        $stmt = $this->db->prepare("
            INSERT INTO message_outbox 
            (message_id, channel, destination, payload, status, created_at)
            VALUES (?, 'SMS', ?, ?, 'PENDING', NOW())
        ");
        
        $stmt->execute([
            'CARD-' . uniqid(),
            $phone,
            json_encode(['message' => $message])
        ]);
    }
    
    /**
     * Get user-friendly status message
     */
    private function getStatusMessage(string $status): string
    {
        $messages = [
            'PENDING_KYC' => 'Your application is pending KYC verification',
            'IN_BATCH' => 'Card is being prepared',
            'ASSIGNED' => 'Card assigned, awaiting pickup',
            'DELIVERED' => 'Card delivered, ready for activation',
            'ISSUED' => 'Virtual card issued, check your SMS',
            'ACTIVE' => 'Card is active and ready to use',
            'BLOCKED' => 'Card has been blocked'
        ];
        
        return $messages[$status] ?? 'Application received';
    }
}
