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
    }
    
    /**
     * Process uploaded KYC document
     */
    public function processUpload(string $applicationId, string $documentType, array $file): array
    {
        // Validate document type
        $allowedTypes = ['passport', 'national_id', 'drivers_license', 'proof_of_address'];
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
            $stmt = $this->db->prepare("
                INSERT INTO kyc_documents (
                    user_id, document_type, document_number, document_path,
                    document_hash, status, submitted_at
                ) VALUES (
                    :user_id, :doc_type, :doc_number, :path,
                    :hash, 'PENDING', NOW()
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
