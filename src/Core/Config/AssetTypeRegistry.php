<?php
// src/Core/Config/AssetTypeRegistry.php

namespace Core\Config;

class AssetTypeRegistry
{
    private static array $types = [];
    private static string $configPath;
    private static bool $loaded = false;

    public static function initialize(?string $country = null): void
    {
        // The file is ALWAYS at src/Core/Config/assets.yaml - NOT country-specific
        self::$configPath = __DIR__ . '/assets.yaml';
        self::$loaded = false;
    }

    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }

        if (!file_exists(self::$configPath)) {
            self::$types = self::getDefaultTypes();
            self::$loaded = true;
            return;
        }

        $content = file_get_contents(self::$configPath);
        
        // Check if yaml extension is available
        if (function_exists('yaml_parse')) {
            $parsed = yaml_parse($content);
            if ($parsed !== false && isset($parsed['version'])) {
                unset($parsed['version']);
                self::$types = $parsed;
                self::$loaded = true;
                return;
            }
        }

        // Fallback: parse YAML manually
        self::$types = self::parseYamlManually($content);
        self::$loaded = true;
    }

    private static function parseYamlManually(string $content): array
    {
        $types = [];
        $lines = explode("\n", $content);
        $currentType = null;
        $currentSection = null;
        $isInFields = false;
        $currentField = null;

        foreach ($lines as $line) {
            $line = rtrim($line);
            if (empty($line) || $line[0] === '#') continue;

            // Detect type header (e.g., "ACCOUNT:")
            if (preg_match('/^([A-Z-]+):$/', trim($line), $matches)) {
                $currentType = $matches[1];
                $types[$currentType] = [];
                $currentSection = null;
                $isInFields = false;
                $currentField = null;
                continue;
            }

            if (!$currentType) continue;

            $trimmed = trim($line);

            // Check for section headers
            if (preg_match('/^(category|delivery_modes|hold_required|hold_expiry_seconds|reversal_window_seconds|partial_debit_allowed):/', $trimmed, $sectionMatch)) {
                $key = $sectionMatch[1];
                $value = trim(substr($trimmed, strlen($key) + 1));
                
                if ($key === 'delivery_modes') {
                    $types[$currentType][$key] = [];
                    $currentSection = $key;
                } elseif ($key === 'hold_required' || $key === 'partial_debit_allowed') {
                    $types[$currentType][$key] = $value === 'true';
                } elseif (is_numeric($value)) {
                    $types[$currentType][$key] = (int)$value;
                } else {
                    $types[$currentType][$key] = trim($value, '"\'');
                }
                continue;
            }

            // Check for UI section
            if (preg_match('/^ui:/', $trimmed)) {
                $types[$currentType]['ui'] = [];
                $currentSection = 'ui';
                continue;
            }

            // Check for fields section
            if (preg_match('/^fields:/', $trimmed)) {
                $types[$currentType]['fields'] = [];
                $currentSection = 'fields';
                $isInFields = true;
                continue;
            }

            // Parse UI properties
            if ($currentSection === 'ui') {
                if (preg_match('/^(icon|display_name|color):\s*(.+)$/', $trimmed, $matches)) {
                    $types[$currentType]['ui'][$matches[1]] = trim($matches[2], '"\'');
                }
            }

            // Parse fields
            if ($isInFields) {
                if (preg_match('/^- name:\s*(.+)$/', $trimmed, $matches)) {
                    $currentField = ['name' => trim($matches[1])];
                    $types[$currentType]['fields'][] = $currentField;
                } elseif ($currentField !== null && preg_match('/^(label|type|required|pattern|min_length|max_length|readonly|source|vault_field|min|max):\s*(.+)$/', $trimmed, $matches)) {
                    $key = $matches[1];
                    $value = trim($matches[2], '"\'');
                    
                    if ($key === 'required' || $key === 'readonly') {
                        $value = $value === 'true';
                    } elseif (in_array($key, ['min_length', 'max_length', 'min', 'max']) && is_numeric($value)) {
                        $value = (int)$value;
                    }
                    
                    $lastIndex = count($types[$currentType]['fields']) - 1;
                    if ($lastIndex >= 0) {
                        $types[$currentType]['fields'][$lastIndex][$key] = $value;
                    }
                }
            }

            // Parse delivery_modes array items
            if ($currentSection === 'delivery_modes') {
                if (preg_match('/^-\s*(.+)$/', $trimmed, $matches)) {
                    $types[$currentType]['delivery_modes'][] = trim($matches[1], '"\'');
                }
            }
        }

        return $types;
    }

    public static function get(string $type): array
    {
        self::load();
        return self::$types[$type] ?? [];
    }

    public static function all(): array
    {
        self::load();
        return self::$types;
    }

    public static function getFields(string $type): array
    {
        $config = self::get($type);
        return $config['fields'] ?? [];
    }

    public static function getUI(string $type): array
    {
        $config = self::get($type);
        return $config['ui'] ?? [];
    }

    public static function getCategory(string $type): string
    {
        $config = self::get($type);
        return $config['category'] ?? 'UNKNOWN';
    }

    public static function getDeliveryModes(string $type): array
    {
        $config = self::get($type);
        return $config['delivery_modes'] ?? [];
    }

    public static function requiresHold(string $type): bool
    {
        $config = self::get($type);
        return $config['hold_required'] ?? false;
    }

    public static function getHoldExpiry(string $type): int
    {
        $config = self::get($type);
        return $config['hold_expiry_seconds'] ?? 300;
    }

    public static function getReversalWindow(string $type): int
    {
        $config = self::get($type);
        return $config['reversal_window_seconds'] ?? 86400;
    }

    public static function allowsPartialDebit(string $type): bool
    {
        $config = self::get($type);
        return $config['partial_debit_allowed'] ?? false;
    }

    private static function getDefaultTypes(): array
    {
        return [
            'ACCOUNT' => [
                'category' => 'BANK_ACCOUNT',
                'delivery_modes' => ['deposit', 'cashout'],
                'hold_required' => true,
                'hold_expiry_seconds' => 900,
                'reversal_window_seconds' => 86400,
                'partial_debit_allowed' => false,
                'ui' => [
                    'icon' => '🏦',
                    'display_name' => 'Bank Account',
                    'color' => '#4CAF50'
                ],
                'fields' => [
                    ['name' => 'account_number', 'label' => 'Account Number', 'type' => 'text', 'required' => true],
                    ['name' => 'wallet_pin', 'label' => 'PIN', 'type' => 'password', 'required' => true]
                ]
            ],
            'ATM' => [
                'category' => 'ATM',
                'delivery_modes' => ['cashout'],
                'hold_required' => true,
                'hold_expiry_seconds' => 300,
                'reversal_window_seconds' => 1800,
                'partial_debit_allowed' => false,
                'ui' => [
                    'icon' => '🏧',
                    'display_name' => 'ATM Cashout',
                    'color' => '#2196F3'
                ],
                'fields' => [
                    ['name' => 'atm_code', 'label' => 'ATM Code', 'type' => 'text', 'required' => true],
                    ['name' => 'atm_pin', 'label' => 'ATM PIN', 'type' => 'password', 'required' => true]
                ]
            ],
            'MNO-WALLET' => [
                'category' => 'MOBILE_MONEY',
                'delivery_modes' => ['deposit', 'cashout'],
                'hold_required' => true,
                'hold_expiry_seconds' => 900,
                'reversal_window_seconds' => 86400,
                'partial_debit_allowed' => true,
                'ui' => [
                    'icon' => '📱',
                    'display_name' => 'Mobile Wallet',
                    'color' => '#E91E63'
                ],
                'fields' => [
                    ['name' => 'phone_number', 'label' => 'Phone Number', 'type' => 'tel', 'required' => true],
                    ['name' => 'wallet_pin', 'label' => 'Wallet PIN', 'type' => 'password', 'required' => true]
                ]
            ]
        ];
    }
}
