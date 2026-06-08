<?php
// src/Domain/Services/IdentityResolver.php

namespace Domain\Services;

use Core\Database\DBConnection;
use PDO;

class IdentityResolver
{
    private PDO $db;
    
    // Global resolution priority order
    private array $resolutionPriority = [
        'WALLET_ID',
        'MSISDN', 
        'NATIONAL_ID',
        'BANK_ACCOUNT',
        'EMAIL',
        'WALLET_PHONE'
    ];
    
    public function __construct()
    {
        $this->db = DBConnection::getInstance();
        $this->ensureTablesExist();
    }
    
    private function ensureTablesExist(): void
    {
        // Create identity_aliases if not exists
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS identity_aliases (
                id BIGSERIAL PRIMARY KEY,
                wallet_uuid UUID NOT NULL,
                participant_code VARCHAR(50) NOT NULL,
                type VARCHAR(30) NOT NULL,
                value VARCHAR(100) NOT NULL,
                verified BOOLEAN DEFAULT false,
                verified_at TIMESTAMP NULL,
                is_primary BOOLEAN DEFAULT false,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(participant_code, type, value)
            )
        ");
        
        // Create index for fast lookups
        $this->db->exec("
            CREATE INDEX IF NOT EXISTS idx_identity_aliases_lookup 
            ON identity_aliases(type, value)
        ");
        
        $this->db->exec("
            CREATE INDEX IF NOT EXISTS idx_identity_aliases_wallet 
            ON identity_aliases(wallet_uuid)
        ");
    }
    
    /**
     * Resolve identity to wallet_uuid (global search)
     */
    public function resolve(string $type, string $value): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT wallet_uuid, participant_code, type, value, verified 
             FROM identity_aliases 
             WHERE type = :type AND value = :value
             LIMIT 1"
        );
        $stmt->execute(['type' => $type, 'value' => $value]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            return $result;
        }
        
        // Try resolution by priority
        return $this->resolveByPriority($value);
    }
    
    /**
     * Resolve by checking all possible identity types
     */
    private function resolveByPriority(string $value): ?array
    {
        foreach ($this->resolutionPriority as $type) {
            $stmt = $this->db->prepare(
                "SELECT wallet_uuid, participant_code, type, value, verified 
                 FROM identity_aliases 
                 WHERE type = :type AND value = :value AND verified = true
                 LIMIT 1"
            );
            $stmt->execute(['type' => $type, 'value' => $value]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                return $result;
            }
        }
        
        return null;
    }
    
    /**
     * Resolve within a specific participant (for source selection)
     */
    public function resolveForParticipant(string $participantCode, string $type, string $value): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT wallet_uuid, participant_code, type, value, verified 
             FROM identity_aliases 
             WHERE participant_code = :participant_code 
               AND type = :type 
               AND value = :value
             LIMIT 1"
        );
        $stmt->execute([
            'participant_code' => $participantCode,
            'type' => $type,
            'value' => $value
        ]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Get all linked sources for a user (by session phone)
     */
    public function getUserLinkedSources(string $sessionPhone): array
    {
        // First resolve session phone to wallet_uuid
        $wallet = $this->resolve('MSISDN', $sessionPhone);
        
        if (!$wallet) {
            return [];
        }
        
        // Get all identities for this wallet across participants
        $stmt = $this->db->prepare(
            "SELECT participant_code, type, value, verified, is_primary 
             FROM identity_aliases 
             WHERE wallet_uuid = :wallet_uuid
             ORDER BY is_primary DESC, participant_code"
        );
        $stmt->execute(['wallet_uuid' => $wallet['wallet_uuid']]);
        
        $sources = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $identity) {
            $sources[] = [
                'participant_code' => $identity['participant_code'],
                'type' => $identity['type'],
                'identifier' => $identity['value'],
                'is_primary' => (bool)$identity['is_primary'],
                'verified' => (bool)$identity['verified']
            ];
        }
        
        return $sources;
    }
    
    /**
     * Add a new identity alias
     */
    public function addAlias(
        string $walletUuid, 
        string $participantCode, 
        string $type, 
        string $value, 
        bool $verified = false,
        bool $isPrimary = false
    ): bool {
        // Check if already exists
        $stmt = $this->db->prepare(
            "SELECT id FROM identity_aliases 
             WHERE participant_code = :participant_code AND type = :type AND value = :value"
        );
        $stmt->execute([
            'participant_code' => $participantCode,
            'type' => $type,
            'value' => $value
        ]);
        
        if ($stmt->fetch()) {
            // Update existing
            $stmt = $this->db->prepare(
                "UPDATE identity_aliases 
                 SET wallet_uuid = :wallet_uuid, verified = :verified, 
                     is_primary = :is_primary, updated_at = NOW()
                 WHERE participant_code = :participant_code AND type = :type AND value = :value"
            );
            return $stmt->execute([
                'wallet_uuid' => $walletUuid,
                'participant_code' => $participantCode,
                'type' => $type,
                'value' => $value,
                'verified' => $verified ? 1 : 0,
                'is_primary' => $isPrimary ? 1 : 0
            ]);
        }
        
        // Insert new
        $stmt = $this->db->prepare(
            "INSERT INTO identity_aliases 
             (wallet_uuid, participant_code, type, value, verified, is_primary, created_at)
             VALUES (:wallet_uuid, :participant_code, :type, :value, :verified, :is_primary, NOW())"
        );
        
        return $stmt->execute([
            'wallet_uuid' => $walletUuid,
            'participant_code' => $participantCode,
            'type' => $type,
            'value' => $value,
            'verified' => $verified ? 1 : 0,
            'is_primary' => $isPrimary ? 1 : 0
        ]);
    }
    
    /**
     * Get wallet details including display info
     */
    public function getWalletDetails(string $walletUuid): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT ia.wallet_uuid, ia.participant_code, u.full_name, u.phone
             FROM identity_aliases ia
             LEFT JOIN users u ON u.wallet_uuid = ia.wallet_uuid
             WHERE ia.wallet_uuid = :wallet_uuid
             LIMIT 1"
        );
        $stmt->execute(['wallet_uuid' => $walletUuid]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
