<?php
// src/Domain/Models/User.php

namespace Domain\Models;

use Core\Config\CountryRegistry;
use PDO;
use Exception;
use InvalidArgumentException;

class User
{
    public int $id;
    public string $phone;
    public string $name;
    public string $email;
    public string $password_hash;
    public int $role_id;
    public string $national_id;
    public string $country_code;
    public string $created_at;
    public string $updated_at;

    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Find user by phone number
     */
    public function findByPhone(string $phone): ?array {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE phone = ?");
        $stmt->execute([$phone]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Find user by national ID
     */
    public function findByNationalId(string $nationalId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE national_id = ?");
        $stmt->execute([$nationalId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Find user by email
     */
    public function findByEmail(string $email): ?array {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Find user by ID
     */
    public function findById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Find users by country code
     */
    public function findByCountry(string $countryCode): array {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE country_code = ? ORDER BY created_at DESC");
        $stmt->execute([$countryCode]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get country configuration from CountryRegistry
     */
    private function getCountryConfig(string $countryCode): ?array {
        try {
            // Load country config using your existing CountryRegistry
            $countryName = $this->getCountryNameFromCode($countryCode);
            if (!$countryName) {
                return null;
            }
            return CountryRegistry::get($countryName);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get country name from code using CountryRegistry
     */
    private function getCountryNameFromCode(string $countryCode): ?string {
        // Try to get from CountryRegistry
        try {
            $allCountries = CountryRegistry::getAll();
            foreach ($allCountries as $name => $config) {
                if (isset($config['country']['code']) && $config['country']['code'] === $countryCode) {
                    return $name;
                }
            }
        } catch (Exception $e) {
            // Fallback mapping
        }

        // Fallback mapping if CountryRegistry doesn't have it
        $map = [
            'BWA' => 'Botswana',
            'ZAF' => 'South Africa',
            'CIV' => 'Cote d\'Ivoire',
            'CMR' => 'Cameroon',
            'GHA' => 'Ghana',
            'NGA' => 'Nigeria',
            'LBR' => 'Liberia',
            'SWZ' => 'Eswatini',
            'ZMB' => 'Zambia',
            'BEN' => 'Benin',
            'COG' => 'Congo'
        ];
        
        return $map[$countryCode] ?? null;
    }

    /**
     * Create a new user with country config validation
     */
    public function create(array $data): int {
        // Auto-detect country from phone if not provided
        if (empty($data['country_code'])) {
            $data['country_code'] = $this->detectCountryFromPhone($data['phone']) ?? 'BWA';
        }

        // Get country config for validation
        $countryConfig = $this->getCountryConfig($data['country_code']);

        // Validate national ID against country config
        if (!empty($data['national_id']) && $countryConfig) {
            if (!$this->validateNationalId($data['national_id'], $data['country_code'])) {
                throw new InvalidArgumentException(
                    "Invalid national ID format for country: " . $data['country_code']
                );
            }
        }

        // Validate phone number format
        if ($countryConfig && isset($countryConfig['phone_format']['regex'])) {
            $phone = preg_replace('/[^0-9]/', '', $data['phone']);
            if (!preg_match($countryConfig['phone_format']['regex'], $phone)) {
                throw new InvalidArgumentException(
                    "Invalid phone number format for country: " . $data['country_code']
                );
            }
        }

        $stmt = $this->db->prepare(
            "INSERT INTO users (phone, name, email, password_hash, role_id, national_id, country_code, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
        );
        $stmt->execute([
            $data['phone'],
            $data['name'],
            $data['email'] ?? null,
            $data['password_hash'],
            $data['role_id'] ?? 1,
            $data['national_id'] ?? null,
            $data['country_code']
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Update user information with validation
     */
    public function update(int $id, array $data): bool {
        // If updating country or national ID, validate
        if (isset($data['country_code']) || isset($data['national_id'])) {
            $currentUser = $this->findById($id);
            if (!$currentUser) {
                return false;
            }

            $countryCode = $data['country_code'] ?? $currentUser['country_code'];
            $nationalId = $data['national_id'] ?? $currentUser['national_id'];

            if (!empty($nationalId)) {
                if (!$this->validateNationalId($nationalId, $countryCode)) {
                    throw new InvalidArgumentException(
                        "Invalid national ID format for country: " . $countryCode
                    );
                }
            }
        }

        $fields = [];
        $params = [];

        $allowedFields = ['phone', 'name', 'email', 'password_hash', 'role_id', 'national_id', 'country_code'];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = "updated_at = NOW()";
        $params[] = $id;

        $sql = "UPDATE users SET " . implode(", ", $fields) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Update user's phone number with validation
     */
    public function updatePhone(int $id, string $phone): bool {
        $user = $this->findById($id);
        if (!$user) {
            return false;
        }

        $countryConfig = $this->getCountryConfig($user['country_code']);
        if ($countryConfig && isset($countryConfig['phone_format']['regex'])) {
            $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
            if (!preg_match($countryConfig['phone_format']['regex'], $cleanPhone)) {
                throw new InvalidArgumentException(
                    "Invalid phone number format for country: " . $user['country_code']
                );
            }
        }

        $stmt = $this->db->prepare("UPDATE users SET phone = ?, updated_at = NOW() WHERE id = ?");
        return $stmt->execute([$phone, $id]);
    }

    /**
     * Update user's country code
     */
    public function updateCountryCode(int $id, string $countryCode): bool {
        // Validate that country exists
        $countryConfig = $this->getCountryConfig($countryCode);
        if (!$countryConfig) {
            throw new InvalidArgumentException("Country code not found: {$countryCode}");
        }

        $stmt = $this->db->prepare("UPDATE users SET country_code = ?, updated_at = NOW() WHERE id = ?");
        return $stmt->execute([$countryCode, $id]);
    }

    /**
     * Update user's national ID with validation
     */
    public function updateNationalId(int $id, string $nationalId): bool {
        $user = $this->findById($id);
        if (!$user) {
            return false;
        }

        if (!$this->validateNationalId($nationalId, $user['country_code'])) {
            throw new InvalidArgumentException(
                "Invalid national ID format for country: " . $user['country_code']
            );
        }

        $stmt = $this->db->prepare("UPDATE users SET national_id = ?, updated_at = NOW() WHERE id = ?");
        return $stmt->execute([$nationalId, $id]);
    }

    /**
     * Update user's password
     */
    public function updatePassword(int $id, string $passwordHash): bool {
        $stmt = $this->db->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
        return $stmt->execute([$passwordHash, $id]);
    }

    /**
     * Delete user
     */
    public function delete(int $id): bool {
        $stmt = $this->db->prepare("DELETE FROM users WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Get all users
     */
    public function getAll(int $limit = 100, int $offset = 0): array {
        $stmt = $this->db->prepare("SELECT * FROM users ORDER BY created_at DESC LIMIT ? OFFSET ?");
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get user's country name from config
     */
    public function getCountryName(int $id): ?string {
        $user = $this->findById($id);
        if (!$user) {
            return null;
        }

        return $this->getCountryNameFromCode($user['country_code']);
    }

    /**
     * Get user's display name with country
     */
    public function getDisplayName(int $id): ?string {
        $user = $this->findById($id);
        if (!$user) {
            return null;
        }

        $countryName = $this->getCountryName($id);
        return $user['name'] . ' (' . ($countryName ?? $user['country_code']) . ')';
    }

    /**
     * Get user by phone or national ID (for login)
     */
    public function findByPhoneOrNationalId(string $identifier): ?array {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE phone = ? OR national_id = ?");
        $stmt->execute([$identifier, $identifier]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Search users by name, phone, email, or national ID
     */
    public function search(string $query): array {
        $searchTerm = '%' . $query . '%';
        $stmt = $this->db->prepare(
            "SELECT * FROM users 
             WHERE name LIKE ? 
                OR phone LIKE ? 
                OR email LIKE ? 
                OR national_id LIKE ?
             ORDER BY created_at DESC"
        );
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get user statistics by country from config
     */
    public function getStatisticsByCountry(): array {
        $stmt = $this->db->query(
            "SELECT 
                country_code,
                COUNT(*) as total_users,
                COUNT(CASE WHEN role_id = 1 THEN 1 END) as regular_users,
                COUNT(CASE WHEN role_id = 2 THEN 1 END) as admin_users,
                COUNT(CASE WHEN role_id = 3 THEN 1 END) as super_admin_users,
                MIN(created_at) as first_user,
                MAX(created_at) as latest_user
             FROM users 
             GROUP BY country_code 
             ORDER BY total_users DESC"
        );
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add country names from config
        foreach ($results as &$row) {
            $countryName = $this->getCountryNameFromCode($row['country_code']);
            $countryConfig = $this->getCountryConfig($row['country_code']);
            $row['country_name'] = $countryName ?? $row['country_code'];
            $row['currency'] = $countryConfig['country']['currency'] ?? null;
            $row['calling_code'] = $countryConfig['country']['calling_code'] ?? null;
        }
        
        return $results;
    }

    /**
     * Get total user count
     */
    public function getTotalCount(): int {
        $stmt = $this->db->query("SELECT COUNT(*) as total FROM users");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($result['total'] ?? 0);
    }

    /**
     * Get user's mobile network based on phone prefix
     */
    public function getMobileNetwork(int $id): ?array {
        $user = $this->findById($id);
        if (!$user) {
            return null;
        }

        $countryConfig = $this->getCountryConfig($user['country_code']);
        if (!$countryConfig || !isset($countryConfig['mobile_networks'])) {
            return null;
        }

        $phone = preg_replace('/[^0-9]/', '', $user['phone']);
        $prefixConfig = $countryConfig['phone_format'] ?? null;
        
        if ($prefixConfig && isset($prefixConfig['prefix'])) {
            $phone = substr($phone, strlen($prefixConfig['prefix']));
        }

        $networks = $countryConfig['mobile_networks'];
        foreach ($networks as $network => $networkConfig) {
            if (!isset($networkConfig['enabled']) || $networkConfig['enabled'] !== true) {
                continue;
            }
            
            foreach ($networkConfig['network_prefixes'] as $prefix) {
                if (strpos($phone, $prefix) === 0) {
                    return [
                        'network' => $network,
                        'display_name' => $networkConfig['display_name'],
                        'config' => $networkConfig
                    ];
                }
            }
        }

        // Return default network if no match
        $defaultNetwork = $countryConfig['default_network'] ?? null;
        if ($defaultNetwork && isset($networks[$defaultNetwork]) && $networks[$defaultNetwork]['enabled'] === true) {
            return [
                'network' => $defaultNetwork,
                'display_name' => $networks[$defaultNetwork]['display_name'],
                'config' => $networks[$defaultNetwork]
            ];
        }

        return null;
    }

    /**
     * Validate national ID against country config
     */
    private function validateNationalId(string $nationalId, string $countryCode): bool {
        $countryConfig = $this->getCountryConfig($countryCode);
        if (!$countryConfig || !isset($countryConfig['id_validation'])) {
            // Default validation if not specified
            return !empty($nationalId) && strlen($nationalId) >= 6;
        }

        $validation = $countryConfig['id_validation'];
        $pattern = $validation['pattern'] ?? null;
        
        if ($pattern) {
            return (bool)preg_match($pattern, $nationalId);
        }

        // Check length requirements
        if (isset($validation['min_length']) && strlen($nationalId) < $validation['min_length']) {
            return false;
        }
        if (isset($validation['max_length']) && strlen($nationalId) > $validation['max_length']) {
            return false;
        }

        return true;
    }

    /**
     * Detect country from phone number using CountryRegistry
     */
    private function detectCountryFromPhone(string $phone): ?string {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        try {
            $allCountries = CountryRegistry::getAll();
            foreach ($allCountries as $name => $config) {
                if (isset($config['phone_format']['prefix'])) {
                    $prefix = $config['phone_format']['prefix'];
                    if (strpos($phone, $prefix) === 0) {
                        return $config['country']['code'] ?? null;
                    }
                }
            }
        } catch (Exception $e) {
            // Fallback to default
        }
        
        return null;
    }

    /**
     * Check if user exists by phone
     */
    public function existsByPhone(string $phone): bool {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE phone = ?");
        $stmt->execute([$phone]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Check if user exists by national ID
     */
    public function existsByNationalId(string $nationalId): bool {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE national_id = ?");
        $stmt->execute([$nationalId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Check if user exists by email
     */
    public function existsByEmail(string $email): bool {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $stmt->execute([$email]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Get users created in the last X days
     */
    public function getRecentUsers(int $days = 7): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM users 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY created_at DESC"
        );
        $stmt->execute([$days]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get users by role
     */
    public function getUsersByRole(int $roleId): array {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE role_id = ? ORDER BY created_at DESC");
        $stmt->execute([$roleId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Bulk create users
     */
    public function bulkCreate(array $users): array {
        $results = [];
        $this->db->beginTransaction();
        
        try {
            foreach ($users as $userData) {
                $results[] = $this->create($userData);
            }
            $this->db->commit();
            return $results;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Get user's full information with country details
     */
    public function getFullUserInfo(int $id): ?array {
        $user = $this->findById($id);
        if (!$user) {
            return null;
        }

        $countryConfig = $this->getCountryConfig($user['country_code']);
        $mobileNetwork = $this->getMobileNetwork($id);

        return array_merge($user, [
            'country_name' => $countryConfig['country']['name'] ?? $user['country_code'],
            'currency' => $countryConfig['country']['currency'] ?? null,
            'calling_code' => $countryConfig['country']['calling_code'] ?? null,
            'mobile_network' => $mobileNetwork['display_name'] ?? null,
            'network_code' => $mobileNetwork['network'] ?? null,
            'phone_formatted' => $this->formatPhoneNumber($user['phone'], $user['country_code'])
        ]);
    }

    /**
     * Format phone number based on country config
     */
    private function formatPhoneNumber(string $phone, string $countryCode): string {
        $countryConfig = $this->getCountryConfig($countryCode);
        if (!$countryConfig) {
            return $phone;
        }

        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        $format = $countryConfig['phone_format']['format'] ?? '+{prefix}{number}';
        $prefix = $countryConfig['phone_format']['prefix'] ?? '';
        $number = substr($cleanPhone, strlen($prefix));
        
        return str_replace(
            ['{prefix}', '{number}'],
            [$prefix, $number],
            $format
        );
    }
}
