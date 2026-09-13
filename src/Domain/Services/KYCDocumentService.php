<?php

declare(strict_types=1);

namespace Domain\Services;

use PDO;
use Exception;
use RuntimeException;

/**
 * KYCDocumentService - Handles KYC document upload and verification
 */
class KYCDocumentService
{
    private PDO $db;
    private string $uploadDir = '/var/www/html/uploads/kyc/';
    
    public function __construct(PDO $db)
    {
        $this->db = $db;
        if (!file_exists($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
        $this->ensureKycVerifiedAtColumn();
    }

    /**
     * users.kyc_verified already exists but had nothing to record WHEN
     * it was set. Self-provisioning (idempotent, matches the pattern
     * already used elsewhere in this codebase, e.g. ApiRateLimiter's
     * ensureTable()) rather than a separate manual migration step, since
     * this is a single additive nullable column with no data to migrate.
     */
    private function ensureKycVerifiedAtColumn(): void
    {
        $this->db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS kyc_verified_at TIMESTAMPTZ");
    }
    
    /**
     * Process uploaded KYC document
     */
    public function processUpload(string $applicationId, string $documentType, array $file): array
    {
        // Validate document type — must match kyc_documents' own CHECK
        // constraint exactly, or the INSERT below fails at the database.
        $allowedTypes = ['passport', 'national_id', 'drivers_license', 'utility_bill', 'bank_statement'];
        if (!in_array($documentType, $allowedTypes)) {
            throw new RuntimeException("Invalid document type");
        }
        
        // Validate file
        $allowedMime = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $allowedMime)) {
            throw new RuntimeException("File type not allowed. Please upload JPG, PNG, or PDF");
        }
        
        if ($file['size'] > 5 * 1024 * 1024) { // 5MB
            throw new RuntimeException("File too large. Maximum 5MB");
        }
        
        // Get application details
        $appStmt = $this->db->prepare("
            SELECT user_id FROM card_applications WHERE application_id = ?
        ");
        $appStmt->execute([$applicationId]);
        $application = $appStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$application) {
            throw new RuntimeException("Application not found");
        }
        
        // Generate filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = $applicationId . '_' . $documentType . '_' . time() . '.' . $extension;
        $filepath = $this->uploadDir . $filename;
        
        // Move file
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            throw new RuntimeException("Failed to save file");
        }
        
        // Calculate hash
        $fileHash = hash_file('sha256', $filepath);
        
        // Save to database
        $this->db->beginTransaction();
        
        try {
            // status is a real Postgres ENUM (kyc_status) accepting only
            // lowercase pending/approved/rejected/expired - 'PENDING' would
            // fail at the database with "invalid input value for enum".
            // submitted_at is not a real column on this table (only
            // created_at/updated_at exist) - omitted, created_at's own
            // default covers it.
            $stmt = $this->db->prepare("
                INSERT INTO kyc_documents (
                    user_id, document_type, document_number, document_path,
                    document_hash, status
                ) VALUES (
                    :user_id, :doc_type, :doc_number, :path,
                    :hash, 'pending'
                )
            ");
            
            $stmt->execute([
                ':user_id' => $application['user_id'],
                ':doc_type' => $documentType,
                ':doc_number' => $applicationId,
                ':path' => $filepath,
                ':hash' => $fileHash
            ]);
            
            // Update application status
            $this->db->prepare("
                UPDATE card_applications 
                SET status = 'KYC_SUBMITTED',
                    kyc_submitted_at = NOW(),
                    updated_at = NOW()
                WHERE application_id = ?
            ")->execute([$applicationId]);
            
            $this->db->commit();
            
        } catch (Exception $e) {
            $this->db->rollBack();
            unlink($filepath); // Delete file if DB insert fails
            throw $e;
        }
        
        return [
            'success' => true,
            'message' => 'Document uploaded successfully',
            'status' => 'PENDING_REVIEW'
        ];
    }
    
    /**
     * Admin approve/reject action for an uploaded KYC document. This is
     * the step that was previously missing entirely: kyc_documents rows
     * sat at 'pending' forever, and users.kyc_verified never got set by
     * anything. On approval, both the document's own status and the
     * user's verified flag are updated together in one transaction, so
     * they can never drift out of sync with each other.
     */
    public function reviewDocument(int $kycId, string $decision, ?string $notes, int $adminId): array
    {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new RuntimeException("Invalid decision '{$decision}' — must be 'approved' or 'rejected'");
        }

        $stmt = $this->db->prepare("SELECT user_id, status FROM kyc_documents WHERE kyc_id = :id");
        $stmt->execute([':id' => $kycId]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$doc) {
            throw new RuntimeException("KYC document not found: {$kycId}");
        }

        $this->db->beginTransaction();

        try {
            $this->db->prepare("
                UPDATE kyc_documents
                SET status = :status, admin_reviewer_id = :admin_id,
                    review_date = NOW(), review_notes = :notes, updated_at = NOW()
                WHERE kyc_id = :id
            ")->execute([
                ':status' => $decision,
                ':admin_id' => $adminId,
                ':notes' => $notes,
                ':id' => $kycId,
            ]);

            if ($decision === 'approved') {
                $this->db->prepare("
                    UPDATE users SET kyc_verified = true, kyc_verified_at = NOW()
                    WHERE user_id = :user_id
                ")->execute([':user_id' => $doc['user_id']]);
            }

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }

        $this->queueReviewNotification((int)$doc['user_id'], $decision, $notes);

        return ['success' => true, 'kyc_id' => $kycId, 'decision' => $decision];
    }

    /**
     * Notify the customer of an approval/rejection decision, using the
     * same SMS-outbox pattern already used by queueDocumentRequest() —
     * no new notification plumbing needed.
     */
    private function queueReviewNotification(int $userId, string $decision, ?string $notes): void
    {
        $message = $decision === 'approved'
            ? "Your identity verification has been approved. You can now transact without limits."
            : "Your identity verification could not be approved." . ($notes ? " Reason: {$notes}" : "") . " Please contact support or re-submit your documents.";

        $userStmt = $this->db->prepare("SELECT phone FROM users WHERE user_id = ?");
        $userStmt->execute([$userId]);
        $phone = $userStmt->fetchColumn();

        if ($phone) {
            $stmt = $this->db->prepare("
                INSERT INTO message_outbox
                (message_id, channel, destination, payload, status, created_at)
                VALUES (?, 'SMS', ?, ?, 'PENDING', NOW())
            ");
            $stmt->execute([
                'KYC-REVIEW-' . uniqid(),
                $phone,
                json_encode(['message' => $message]),
            ]);
        }
    }

    /**
     * Queue document request notification
     */
    public function queueDocumentRequest(int $userId, string $applicationId): void
    {
        $message = "Please upload your KYC documents to complete your card application. "
                 . "Application ID: {$applicationId}";
        
        $stmt = $this->db->prepare("
            INSERT INTO message_outbox 
            (message_id, channel, destination, payload, status, created_at)
            VALUES (?, 'SMS', ?, ?, 'PENDING', NOW())
        ");
        
        // Get user phone
        $userStmt = $this->db->prepare("SELECT phone FROM users WHERE user_id = ?");
        $userStmt->execute([$userId]);
        $phone = $userStmt->fetchColumn();
        
        if ($phone) {
            $stmt->execute([
                'KYC-' . uniqid(),
                $phone,
                json_encode(['message' => $message])
            ]);
        }
    }
}
