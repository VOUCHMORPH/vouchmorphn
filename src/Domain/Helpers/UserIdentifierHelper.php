<?php
// src/Helpers/UserIdentifierHelper.php

namespace Helpers;

class UserIdentifierHelper
{
    /**
     * Get all identifiers for a user
     */
    public static function getAllIdentifiers($userId, $db): array
    {
        $stmt = $db->prepare("
            SELECT 
                phone, phone2, phone3, email, 
                national_id, drivers_license, passport,
                full_name, date_of_birth
            FROM users 
            WHERE user_id = :user_id
        ");
        $stmt->execute([':user_id' => $userId]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$user) {
            return [];
        }
        
        $identifiers = [];
        
        // Add phone numbers (max 3)
        if (!empty($user['phone'])) {
            $identifiers[] = ['type' => 'phone', 'value' => $user['phone']];
        }
        if (!empty($user['phone2'])) {
            $identifiers[] = ['type' => 'phone', 'value' => $user['phone2']];
        }
        if (!empty($user['phone3'])) {
            $identifiers[] = ['type' => 'phone', 'value' => $user['phone3']];
        }
        
        // Add email
        if (!empty($user['email'])) {
            $identifiers[] = ['type' => 'email', 'value' => $user['email']];
        }
        
        // Add ID documents
        if (!empty($user['national_id'])) {
            $identifiers[] = ['type' => 'national_id', 'value' => $user['national_id']];
        }
        if (!empty($user['drivers_license'])) {
            $identifiers[] = ['type' => 'drivers_license', 'value' => $user['drivers_license']];
        }
        if (!empty($user['passport'])) {
            $identifiers[] = ['type' => 'passport', 'value' => $user['passport']];
        }
        
        return $identifiers;
    }
    
    /**
     * Check if a value matches any user identifier
     */
    public static function matchIdentifier($userId, $value, $db): bool
    {
        $identifiers = self::getAllIdentifiers($userId, $db);
        
        foreach ($identifiers as $id) {
            if (strtolower($id['value']) === strtolower($value)) {
                return true;
            }
        }
        
        return false;
    }
}
