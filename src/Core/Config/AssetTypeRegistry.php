<?php
// src/Core/Config/AssetTypeRegistry.php

namespace Core\Config;

class AssetTypeRegistry
{
    private static array $types = [];
    private static string $configPath;
    private static bool $loaded = false;
    private static array $parsingErrors = [];

    /**
     * Initialize the registry - ALWAYS uses global assets.yaml
     * NOT country-specific
     */
    public static function initialize(?string $country = null): void
    {
        // The file is ALWAYS at src/Core/Config/assets.yaml - NOT country-specific
        self::$configPath = __DIR__ . '/assets.yaml';
        self::$loaded = false;
        self::$parsingErrors = [];
    }

    /**
     * Load asset types from YAML file
     */
    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }

        if (!file_exists(self::$configPath)) {
            error_log("[AssetTypeRegistry] Config file not found: " . self::$configPath);
            self::$types = self::getDefaultTypes();
            self::$loaded = true;
            return;
        }

        $content = file_get_contents(self::$configPath);
        
        // Try yaml_parse first (if extension is available)
        if (function_exists('yaml_parse')) {
            $parsed = yaml_parse($content);
            if ($parsed !== false && is_array($parsed)) {
                // Remove version key if present
                if (isset($parsed['version'])) {
                    unset($parsed['version']);
                }
                self::$types = $parsed;
                self::$loaded = true;
                error_log("[AssetTypeRegistry] Loaded " . count(self::$types) . " asset types via yaml_parse");
                return;
            }
        }

        // Fallback: parse YAML manually
        error_log("[AssetTypeRegistry] yaml_parse not available or failed, using manual parser");
        self::$types = self::parseYamlManually($content);
        self::$loaded = true;
        error_log("[AssetTypeRegistry] Loaded " . count(self::$types) . " asset types via manual parser");
        
        if (!empty(self::$parsingErrors)) {
            error_log("[AssetTypeRegistry] Parsing errors: " . implode('; ', self::$parsingErrors));
        }
    }

    /**
     * Parse YAML manually when yaml_parse is not available
     */
    private static function parseYamlManually(string $content): array
    {
        $types = [];
        $lines = explode("\n", $content);
        $currentType = null;
        $currentSection = null;
        $currentSubSection = null;
        $isInFields = false;
        $currentField = null;
        $fieldIndex = 0;
        $indentLevel = 0;
        $inArray = false;
        $arrayKey = null;

        foreach ($lines as $lineNum => $line) {
            $originalLine = $line;
            $line = rtrim($line);
            if (empty($line) || $line[0] === '#') continue;

            // Calculate indentation level
            $indent = strlen($line) - strlen(ltrim($line));
            $trimmed = trim($line);

            // Detect type header (e.g., "ACCOUNT:")
            if (preg_match('/^([A-Z-]+):$/', $trimmed, $matches)) {
                $currentType = $matches[1];
                $types[$currentType] = [];
                $currentSection = null;
                $currentSubSection = null;
                $isInFields = false;
                $currentField = null;
                $fieldIndex = 0;
                $inArray = false;
                continue;
            }

            if (!$currentType) continue;

            // Check for section headers
            if (preg_match('/^(category|delivery_modes|hold_required|hold_expiry_seconds|reversal_window_seconds|partial_debit_allowed):/', $trimmed, $sectionMatch)) {
                $key = $sectionMatch[1];
                $value = trim(substr($trimmed, strlen($key) + 1));
                
                if ($key === 'delivery_modes') {
                    $types[$currentType][$key] = [];
                    $currentSection = $key;
                    $inArray = true;
                    continue;
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
                $fieldIndex = 0;
                continue;
            }

            // Parse UI properties
            if ($currentSection === 'ui') {
                if (preg_match('/^(icon|display_name|color|description):\s*(.+)$/', $trimmed, $matches)) {
                    $types[$currentType]['ui'][$matches[1]] = trim($matches[2], '"\'');
                }
            }

            // Parse fields
            if ($isInFields) {
                // New field item
                if (preg_match('/^- name:\s*(.+)$/', $trimmed, $matches)) {
                    $currentField = ['name' => trim($matches[1])];
                    $types[$currentType]['fields'][] = $currentField;
                    $fieldIndex = count($types[$currentType]['fields']) - 1;
                    continue;
                }
                
                // Field properties (indented under a field)
                if ($currentField !== null && preg_match('/^(label|type|required|pattern|min_length|max_length|readonly|source|vault_field|min|max|placeholder|help_text):\s*(.+)$/', $trimmed, $matches)) {
                    $key = $matches[1];
                    $value = trim($matches[2], '"\'');
                    
                    if ($key === 'required' || $key === 'readonly') {
                        $value = $value === 'true';
                    } elseif (in_array($key, ['min_length', 'max_length', 'min', 'max']) && is_numeric($value)) {
                        $value = (int)$value;
                    }
                    
                    if (isset($types[$currentType]['fields'][$fieldIndex])) {
                        $types[$currentType]['fields'][$fieldIndex][$key] = $value;
                    }
                }
            }

            // Parse delivery_modes array items
            if ($currentSection === 'delivery_modes' && preg_match('/^-\s*(.+)$/', $trimmed, $matches)) {
                $types[$currentType]['delivery_modes'][] = trim($matches[1], '"\'');
            }

            // Parse options array for select fields
            if ($currentField !== null && preg_match('/^options:/', $trimmed)) {
                $types[$currentType]['fields'][$fieldIndex]['options'] = [];
                continue;
            }
            
            if ($currentField !== null && isset($types[$currentType]['fields'][$fieldIndex]['options']) && preg_match('/^-\s*(.+)$/', $trimmed, $matches)) {
                $types[$currentType]['fields'][$fieldIndex]['options'][] = trim($matches[1], '"\'');
            }
        }

        // Validate parsed types
        foreach ($types as $typeName => $config) {
            if (empty($config['fields'])) {
                $types[$typeName]['fields'] = [];
            }
            if (empty($config['ui'])) {
                $types[$typeName]['ui'] = [
                    'icon' => '📦',
                    'display_name' => $typeName,
                    'color' => '#888'
                ];
            }
            if (empty($config['delivery_modes'])) {
                $types[$typeName]['delivery_modes'] = ['deposit'];
            }
        }

        return $types;
    }

    /**
     * Get a specific asset type configuration
     */
    public static function get(string $type): array
    {
        self::load();
        return self::$types[$type] ?? [];
    }

    /**
     * Get all asset types
     */
    public static function all(): array
    {
        self::load();
        return self::$types;
    }

    /**
     * Get fields for a specific asset type
     */
    public static function getFields(string $type): array
    {
        $config = self::get($type);
        return $config['fields'] ?? [];
    }

    /**
     * Get UI configuration for a specific asset type
     */
    public static function getUI(string $type): array
    {
        $config = self::get($type);
        return $config['ui'] ?? [];
    }

    /**
     * Get category for a specific asset type
     */
    public static function getCategory(string $type): string
    {
        $config = self::get($type);
        return $config['category'] ?? 'UNKNOWN';
    }

    /**
     * Get delivery modes for a specific asset type
     */
    public static function getDeliveryModes(string $type): array
    {
        $config = self::get($type);
        return $config['delivery_modes'] ?? ['deposit'];
    }

    /**
     * Check if a hold is required for this asset type
     */
    public static function requiresHold(string $type): bool
    {
        $config = self::get($type);
        return $config['hold_required'] ?? false;
    }

    /**
     * Get hold expiry seconds for this asset type
     */
    public static function getHoldExpiry(string $type): int
    {
        $config = self::get($type);
        return $config['hold_expiry_seconds'] ?? 300;
    }

    /**
     * Get reversal window seconds for this asset type
     */
    public static function getReversalWindow(string $type): int
    {
        $config = self::get($type);
        return $config['reversal_window_seconds'] ?? 86400;
    }

    /**
     * Check if partial debit is allowed for this asset type
     */
    public static function allowsPartialDebit(string $type): bool
    {
        $config = self::get($type);
        return $config['partial_debit_allowed'] ?? false;
    }

    /**
     * Get the icon for a specific asset type
     */
    public static function getIcon(string $type): string
    {
        $ui = self::getUI($type);
        return $ui['icon'] ?? '📦';
    }

    /**
     * Get the display name for a specific asset type
     */
    public static function getDisplayName(string $type): string
    {
        $ui = self::getUI($type);
        return $ui['display_name'] ?? $type;
    }

    /**
     * Get the color for a specific asset type
     */
    public static function getColor(string $type): string
    {
        $ui = self::getUI($type);
        return $ui['color'] ?? '#888888';
    }

    /**
     * Check if an asset type exists
     */
    public static function exists(string $type): bool
    {
        self::load();
        return isset(self::$types[$type]);
    }

    /**
     * Get all asset type names
     */
    public static function getTypeNames(): array
    {
        self::load();
        return array_keys(self::$types);
    }

    /**
     * Get asset types that support a specific delivery mode
     */
    public static function getByDeliveryMode(string $mode): array
    {
        self::load();
        $result = [];
        foreach (self::$types as $name => $config) {
            $modes = $config['delivery_modes'] ?? [];
            if (in_array($mode, $modes)) {
                $result[$name] = $config;
            }
        }
        return $result;
    }

    /**
     * Get asset types by category
     */
    public static function getByCategory(string $category): array
    {
        self::load();
        $result = [];
        foreach (self::$types as $name => $config) {
            if (($config['category'] ?? '') === $category) {
                $result[$name] = $config;
            }
        }
        return $result;
    }

    /**
     * Default asset types (fallback)
     */
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
                    'color' => '#4CAF50',
                    'description' => 'Standard bank account'
                ],
                'fields' => [
                    [
                        'name' => 'account_number',
                        'label' => 'Account Number',
                        'type' => 'text',
                        'required' => true,
                        'pattern' => '^[0-9]{8,16}$',
                        'min_length' => 8,
                        'max_length' => 16,
                        'placeholder' => 'Enter account number'
                    ]
                ]
            ],
            'VOUCHER' => [
                'category' => 'VOUCHER',
                'delivery_modes' => ['cashout', 'deposit'],
                'hold_required' => true,
                'hold_expiry_seconds' => 600,
                'reversal_window_seconds' => 3600,
                'partial_debit_allowed' => false,
                'ui' => [
                    'icon' => '🎫',
                    'display_name' => 'Voucher',
                    'color' => '#FF9800',
                    'description' => 'Prepaid voucher'
                ],
                'fields' => [
                    [
                        'name' => 'voucher_number',
                        'label' => 'Voucher Number',
                        'type' => 'text',
                        'required' => true,
                        'pattern' => '^[A-Z0-9]{8,16}$',
                        'placeholder' => 'Enter voucher number'
                    ],
                    [
                        'name' => 'voucher_pin',
                        'label' => 'Voucher PIN',
                        'type' => 'password',
                        'required' => true,
                        'vault_field' => 'pin',
                        'min_length' => 4,
                        'max_length' => 6,
                        'placeholder' => 'Enter voucher PIN'
                    ],
                    [
                        'name' => 'amount',
                        'label' => 'Voucher Amount',
                        'type' => 'number',
                        'required' => true,
                        'min' => 1,
                        'placeholder' => '0.00'
                    ],
                    [
                        'name' => 'phone',
                        'label' => 'Phone Number',
                        'type' => 'tel',
                        'required' => false,
                        'pattern' => '^\\+?[0-9]{10,15}$',
                        'placeholder' => '+267XXXXXXXX'
                    ]
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
                    'color' => '#2196F3',
                    'description' => 'ATM withdrawal'
                ],
                'fields' => [
                    [
                        'name' => 'atm_code',
                        'label' => 'ATM Code',
                        'type' => 'text',
                        'required' => true,
                        'pattern' => '^[0-9]{6}$',
                        'placeholder' => 'Enter ATM code'
                    ],
                    [
                        'name' => 'atm_pin',
                        'label' => 'ATM PIN',
                        'type' => 'password',
                        'required' => true,
                        'vault_field' => 'pin',
                        'min_length' => 4,
                        'max_length' => 6,
                        'placeholder' => 'Enter ATM PIN'
                    ]
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
                    'color' => '#E91E63',
                    'description' => 'Mobile money wallet'
                ],
                'fields' => [
                    [
                        'name' => 'phone_number',
                        'label' => 'Phone Number',
                        'type' => 'tel',
                        'required' => true,
                        'pattern' => '^\\+?[0-9]{10,15}$',
                        'placeholder' => '+267XXXXXXXX'
                    ],
                    [
                        'name' => 'wallet_pin',
                        'label' => 'Wallet PIN',
                        'type' => 'password',
                        'required' => true,
                        'vault_field' => 'pin',
                        'min_length' => 4,
                        'max_length' => 6,
                        'placeholder' => 'Enter wallet PIN'
                    ]
                ]
            ],
            'CARD' => [
                'category' => 'PAYMENT_CARD',
                'delivery_modes' => ['deposit', 'cashout'],
                'hold_required' => true,
                'hold_expiry_seconds' => 1800,
                'reversal_window_seconds' => 259200,
                'partial_debit_allowed' => true,
                'ui' => [
                    'icon' => '💳',
                    'display_name' => 'Payment Card',
                    'color' => '#673AB7',
                    'description' => 'Payment card'
                ],
                'fields' => [
                    [
                        'name' => 'card_number',
                        'label' => 'Card Number',
                        'type' => 'text',
                        'required' => true,
                        'pattern' => '^[0-9]{16}$',
                        'vault_field' => 'pan',
                        'placeholder' => '1234-5678-9012-3456'
                    ],
                    [
                        'name' => 'card_pin',
                        'label' => 'Card PIN',
                        'type' => 'password',
                        'required' => true,
                        'vault_field' => 'pin',
                        'min_length' => 4,
                        'max_length' => 6,
                        'placeholder' => 'Enter card PIN'
                    ],
                    [
                        'name' => 'cvv',
                        'label' => 'CVV',
                        'type' => 'password',
                        'required' => true,
                        'vault_field' => 'cvv',
                        'min_length' => 3,
                        'max_length' => 4,
                        'placeholder' => '123'
                    ],
                    [
                        'name' => 'expiry_month',
                        'label' => 'Expiry Month',
                        'type' => 'number',
                        'required' => true,
                        'min' => 1,
                        'max' => 12,
                        'placeholder' => 'MM'
                    ],
                    [
                        'name' => 'expiry_year',
                        'label' => 'Expiry Year',
                        'type' => 'number',
                        'required' => true,
                        'min' => 2024,
                        'max' => 2034,
                        'placeholder' => 'YYYY'
                    ]
                ]
            ]
        ];
    }

    /**
     * Get any parsing errors that occurred
     */
    public static function getParsingErrors(): array
    {
        return self::$parsingErrors;
    }

    /**
     * Clear the cache (useful for testing)
     */
    public static function clearCache(): void
    {
        self::$loaded = false;
        self::$types = [];
        self::$parsingErrors = [];
    }

    /**
     * Export all asset types as JSON (useful for JavaScript)
     */
    public static function exportJSON(): string
    {
        self::load();
        return json_encode(self::$types, JSON_PRETTY_PRINT);
    }

    /**
     * Validate an asset type configuration
     */
    public static function validate(string $type): array
    {
        $config = self::get($type);
        $errors = [];

        if (empty($config)) {
            $errors[] = "Asset type '{$type}' not found";
            return $errors;
        }

        if (empty($config['category'])) {
            $errors[] = "Missing 'category' for asset type '{$type}'";
        }

        if (!isset($config['delivery_modes']) || !is_array($config['delivery_modes']) || empty($config['delivery_modes'])) {
            $errors[] = "Missing or invalid 'delivery_modes' for asset type '{$type}'";
        }

        if (empty($config['ui']['display_name'])) {
            $errors[] = "Missing 'ui.display_name' for asset type '{$type}'";
        }

        if (!isset($config['fields']) || !is_array($config['fields'])) {
            $errors[] = "Missing 'fields' array for asset type '{$type}'";
        }

        // Validate fields
        foreach ($config['fields'] ?? [] as $index => $field) {
            if (empty($field['name'])) {
                $errors[] = "Field at index {$index} is missing 'name'";
            }
            if (empty($field['label'])) {
                $errors[] = "Field '{$field['name']}' is missing 'label'";
            }
        }

        return $errors;
    }
}
