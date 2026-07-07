<?php
// src/Security/Encryption/KeyVault.php

namespace Security\Encryption;

use RuntimeException;

/**
 * Banking-Grade Key Vault for VouchMorph - GLOBAL VERSION
 * 
 * Zero hardcoded values. Works for ANY country, ANY participant.
 * 
 * FIXED: Added PAN_HMAC_KEY support for CardService
 * 
 * Compliant with:
 * - PSD2 / EBA RTS (Strong Customer Authentication)
 * - ISO 27001:2022 (Asset & Access Control)
 * - PCI DSS v4.0 (Protect stored account data)
 * - NIST SP 800-57 (Key management lifecycle)
 * - SWIFT CSP (Customer Security Programme)
 */
class KeyVault
{
    private static ?KeyVault $instance = null;
    private array $keys = [];
    private array $participantConfigs = [];
    private string $encryptionKey;
    private string $activeCountry;

    /**
     * Private constructor (singleton pattern)
     * All keys are read from Railway Vault Box environment variables
     */
    private function __construct()
    {
        // Load active country from SystemCountry (dynamic)
        $this->activeCountry = $this->detectActiveCountry();
        
        // Load encryption master key (min 32 bytes as per NIST SP 800-57)
        $this->encryptionKey = getenv('APP_ENCRYPTION_KEY') ?: getenv('ENCRYPTION_KEY');
        
        if (!$this->encryptionKey || strlen($this->encryptionKey) < 32) {
            throw new RuntimeException(
                'Missing or weak ENCRYPTION_KEY (min 32 bytes required for banking compliance)'
            );
        }
        
        $this->keys['encryption_master'] = $this->encryptionKey;
        
        // ============================================================
        // PAN HMAC KEY - for CardService PAN hashing
        // FIXED: Load as first-class key with length validation
        // ============================================================
        $panHmacKey = getenv('PAN_HMAC_KEY');
        if ($panHmacKey && strlen($panHmacKey) >= 32) {
            $this->keys['pan_hmac_key'] = $panHmacKey;
        } else {
            // Log warning but don't fail - CardService will validate on use
            error_log("[KeyVault] WARNING: PAN_HMAC_KEY not set or too short (min 32 bytes)");
        }
        
        // Dynamically load ALL participant keys from environment
        $this->loadAllParticipantKeys();
        
        // Load participant configurations
        $this->loadParticipantConfigs();
    }

    /**
     * Get singleton instance
     */
    public static function getInstance(): KeyVault
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Detect active country from SystemCountry configuration
     */
    private function detectActiveCountry(): string
    {
        $possiblePaths = [
            __DIR__ . '/../../Core/Config/SystemCountry.php',
            __DIR__ . '/../../../src/Core/Config/SystemCountry.php',
            getenv('SYSTEM_COUNTRY_PATH') ?: ''
        ];
        
        foreach ($possiblePaths as $path) {
            if ($path && file_exists($path)) {
                $country = require $path;
                if (is_array($country)) {
                    return $country['country'] ?? $country[0] ?? 'Unknown';
                }
                return $country ?: 'Unknown';
            }
        }
        
        return getenv('ACTIVE_COUNTRY') ?: 'Unknown';
    }

    /**
     * Dynamically load ALL participant keys from environment
     * NO HARDCODING - reads any PARTICIPANT_NAME_API_KEY pattern
     */
    private function loadAllParticipantKeys(): void
    {
        // Scan all environment variables for pattern: *_API_KEY, *_BASE_URL, etc.
        foreach ($_ENV as $key => $value) {
            // Capture any *_API_KEY pattern
            if (str_ends_with($key, '_API_KEY')) {
                $participant = str_replace('_API_KEY', '', $key);
                $this->keys[strtolower($participant) . '_api_key'] = $value;
                $this->keys['participant_' . strtolower($participant)] = $value;
            }
            
            // Capture any *_BASE_URL pattern
            if (str_ends_with($key, '_BASE_URL')) {
                $participant = str_replace('_BASE_URL', '', $key);
                $this->keys[strtolower($participant) . '_base_url'] = $value;
            }
            
            // Capture any UPSTREAM_*_KEY pattern
            if (str_starts_with($key, 'UPSTREAM_') && str_ends_with($key, '_KEY')) {
                $participant = str_replace(['UPSTREAM_', '_KEY'], '', $key);
                $this->keys['upstream_' . strtolower($participant) . '_key'] = $value;
            }
            
            // Capture any *_CLIENT_ID / *_CLIENT_SECRET for OAuth2
            if (str_ends_with($key, '_CLIENT_ID')) {
                $participant = str_replace('_CLIENT_ID', '', $key);
                $this->keys[strtolower($participant) . '_client_id'] = $value;
            }
            if (str_ends_with($key, '_CLIENT_SECRET')) {
                $participant = str_replace('_CLIENT_SECRET', '', $key);
                $this->keys[strtolower($participant) . '_client_secret'] = $value;
            }
        }
        
        // Also check getenv() for any missed variables
        $this->scanGetenvForKeys();
    }

    /**
     * Scan getenv() for additional keys
     */
    private function scanGetenvForKeys(): void
    {
        // Common key patterns to check
        $patterns = ['_API_KEY', '_BASE_URL', 'UPSTREAM_', '_CLIENT_ID', '_CLIENT_SECRET'];
        
        foreach ($patterns as $pattern) {
            // This is simplified - in production you'd use a more sophisticated approach
            // or rely on $_ENV which Railway populates
        }
    }

    /**
     * Load participant configurations from dynamic path based on country
     */
    private function loadParticipantConfigs(): void
    {
        $possiblePaths = [
            __DIR__ . "/../../Core/Config/Countries/{$this->activeCountry}/participants.json",
            __DIR__ . "/../../../src/Core/Config/Countries/{$this->activeCountry}/participants.json",
            getenv('PARTICIPANTS_CONFIG_PATH') ?: ''
        ];
        
        $config = null;
        foreach ($possiblePaths as $path) {
            if ($path && file_exists($path)) {
                $content = file_get_contents($path);
                $config = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    break;
                }
            }
        }
        
        if ($config && isset($config['participants'])) {
            foreach ($config['participants'] as $name => $participant) {
                $this->participantConfigs[strtolower($name)] = $participant;
            }
        }
    }

    /**
     * Get primary encryption key (for TokenEncryptor)
     */
    public function getEncryptionKey(): string
    {
        return $this->keys['encryption_master'];
    }

    /**
     * Get any key by name
     */
    public function getKey(string $name): ?string
    {
        return $this->keys[$name] ?? null;
    }

    /**
     * Alias for getKey - for compatibility with SmsGatewayClient
     */
    public function get(string $name): ?string
    {
        return $this->getKey($name);
    }

    /**
     * Get configuration for a specific participant (bank, MNO, PSP)
     * Works dynamically for ANY participant
     */
    public function getParticipantConfig(string $participantName): array
    {
        $participant = strtolower($participantName);
        
        // Check participant config first
        if (isset($this->participantConfigs[$participant])) {
            $pConfig = $this->participantConfigs[$participant];
            return [
                'api_key' => $this->keys[$participant . '_api_key'] ?? null,
                'base_url' => $pConfig['base_url'] ?? $this->keys[$participant . '_base_url'] ?? null,
                'auth_type' => $pConfig['auth_type'] ?? 'API_KEY',
                'oauth2' => $pConfig['security']['oauth2'] ?? null,
                'capabilities' => $pConfig['capabilities'] ?? [],
                'endpoints' => $pConfig['resource_endpoints'] ?? [],
                'message_profile' => $pConfig['message_profile'] ?? [],
                'settlement' => $pConfig['settlement'] ?? []
            ];
        }
        
        // Fallback to environment-derived config
        return [
            'api_key' => $this->keys[$participant . '_api_key'] ?? null,
            'base_url' => $this->keys[$participant . '_base_url'] ?? null,
            'upstream_key' => $this->keys['up
