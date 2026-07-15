<?php
/**
 * test_schema.php - Check if all required tables and columns exist
 * Run: php test_schema.php
 */

require_once __DIR__ . '/src/Core/Database/DBConnection.php';

use Core\Database\DBConnection;

$db = DBConnection::getConnection();

if (!$db) {
    die("Database connection failed.\n");
}

echo "================================================================" . PHP_EOL;
echo "VOUCHMORPH DATABASE SCHEMA VERIFICATION" . PHP_EOL;
echo "================================================================" . PHP_EOL . PHP_EOL;

$tables = [
    // Core tables
    'users',
    'admins',
    'accounts',
    'wallets',
    'ewallet_pins',
    
    // Swap tables
    'swap_requests',
    'swap_ledgers',
    'hold_transactions',
    'settlement_queue',
    'settlement_outbox',
    'swap_fee_collections',
    'fee_invoices',
    'net_positions',
    
    // Transaction tables
    'cashout_authorizations',
    'deposit_transactions',
    'swap_vouchers',
    'identity_swap_holds',
    'cross_border_messages',
    
    // Audit tables
    'audit_logs',
    'admin_actions',
    'organization_audit_logs',
    'regulatory_reports',
    
    // Schema tables
    'participants',
    'departments',
    'organization_users',
    'user_authorized_sources',
    'financial_holds',
];

$columns = [
    'users' => [
        'user_id', 'username', 'email', 'phone', 'phone2', 'phone3',
        'password_hash', 'full_name', 'role_id', 'verified', 'kyc_verified',
        'aml_score', 'mfa_enabled', 'national_id', 'drivers_license', 'passport',
        'date_of_birth', 'has_transaction_pin', 'transaction_pin_hash',
        'pin_attempts', 'pin_locked_until', 'failed_login_attempts',
        'locked_until', 'wallet_uuid', 'created_at', 'updated_at', 'deleted_at'
    ],
    'admins' => [
        'admin_id', 'username', 'email', 'password_hash', 'role_id',
        'full_name', 'phone', 'country_code', 'mfa_enabled',
        'created_at', 'updated_at', 'deleted_at'
    ],
    'accounts' => [
        'account_id', 'user_id', 'account_number', 'account_type',
        'currency', 'balance', 'held_balance', 'is_frozen',
        'created_at', 'updated_at'
    ],
    'wallets' => [
        'wallet_id', 'user_id', 'phone', 'balance', 'held_balance',
        'status', 'wallet_type', 'currency', 'is_frozen',
        'created_at', 'updated_at'
    ],
    'ewallet_pins' => [
        'id', 'pin', 'amount', 'is_redeemed', 'hold_status',
        'hold_reference', 'held_at', 'held_by', 'expires_at',
        'created_at', 'sender_phone', 'recipient_phone'
    ],
    'swap_requests' => [
        'swap_id', 'swap_uuid', 'user_id', 'from_currency', 'to_currency',
        'amount', 'status', 'created_at', 'updated_at', 'source_country',
        'destination_country', 'forex_rate', 'fee_breakdown', 'metadata',
        'retry_count', 'original_swap_ref', 'forex_fee_percent',
        'forex_fee_amount', 'total_forex_fee'
    ],
    'hold_transactions' => [
        'hold_id', 'hold_reference', 'swap_reference', 'participant_name',
        'asset_type', 'amount', 'currency', 'status', 'source_details',
        'destination_institution', 'metadata', 'placed_at', 'released_at',
        'debited_at', 'created_at', 'updated_at'
    ],
    'settlement_queue' => [
        'id', 'reference', 'debtor', 'creditor', 'amount', 'currency',
        'status', 'created_at', 'updated_at', 'processed_at', 'error_message'
    ],
    'settlement_outbox' => [
        'id', 'swap_reference', 'status', 'message_type', 'message_payload',
        'retry_count', 'sent_at', 'acknowledged_at', 'error_message',
        'composite_signature', 'is_multi_source', 'created_at', 'updated_at'
    ],
    'cashout_authorizations' => [
        'auth_id', 'swap_reference', 'client_phone', 'source_institution',
        'amount', 'currency', 'fee_amount', 'swap_code', 'pin_code',
        'code_expiry', 'cashout_point', 'cashout_provider', 'status',
        'created_at', 'updated_at', 'completed_at'
    ],
    'identity_swap_holds' => [
        'hold_id', 'swap_reference', 'source_institution', 'source_identifier',
        'source_asset_type', 'amount', 'currency', 'identity_type',
        'identity_value', 'hold_reference', 'hold_expires_at', 'status',
        'source_payload', 'metadata', 'created_at', 'confirmed_at',
        'completed_at', 'expired_at', 'confirmed_by_type', 'confirmed_by_id',
        'confirmation_method', 'final_destination_type',
        'final_destination_payload', 'final_transaction_reference'
    ],
    'financial_holds' => [
        'id', 'wallet_id', 'account_id', 'amount', 'hold_reference',
        'foreign_bank', 'session_id', 'status', 'requester',
        'signature_verified', 'asset_type', 'expires_at', 'created_at',
        'released_at', 'debited_at', 'debited_by'
    ],
];

echo "CHECKING TABLES..." . PHP_EOL . PHP_EOL;

$missingTables = [];
$missingColumns = [];

foreach ($tables as $table) {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM information_schema.tables 
        WHERE table_schema = 'public' AND table_name = :table
    ");
    $stmt->execute([':table' => $table]);
    $exists = (int)$stmt->fetchColumn() > 0;
    
    if ($exists) {
        echo "✅ TABLE $table EXISTS" . PHP_EOL;
    } else {
        echo "❌ TABLE $table MISSING" . PHP_EOL;
        $missingTables[] = $table;
    }
}

echo PHP_EOL . "CHECKING COLUMNS..." . PHP_EOL . PHP_EOL;

foreach ($columns as $table => $columnList) {
    echo "--- $table TABLE ---" . PHP_EOL;
    
    // Check if table exists first
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM information_schema.tables 
        WHERE table_schema = 'public' AND table_name = :table
    ");
    $stmt->execute([':table' => $table]);
    $tableExists = (int)$stmt->fetchColumn() > 0;
    
    if (!$tableExists) {
        echo "  ⚠️ Table $table does not exist - skipping column check" . PHP_EOL . PHP_EOL;
        continue;
    }
    
    foreach ($columnList as $column) {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM information_schema.columns 
            WHERE table_schema = 'public' AND table_name = :table AND column_name = :column
        ");
        $stmt->execute([':table' => $table, ':column' => $column]);
        $exists = (int)$stmt->fetchColumn() > 0;
        
        if ($exists) {
            echo "  ✅ COLUMN $column EXISTS" . PHP_EOL;
        } else {
            echo "  ❌ COLUMN $column MISSING" . PHP_EOL;
            $missingColumns[] = "$table.$column";
        }
    }
    echo PHP_EOL;
}

echo "================================================================" . PHP_EOL;
echo "VERIFICATION COMPLETE" . PHP_EOL;
echo "================================================================" . PHP_EOL . PHP_EOL;

echo "SUMMARY:" . PHP_EOL;
echo "--------" . PHP_EOL;
echo "Total Tables Checked: " . count($tables) . PHP_EOL;
echo "✅ Tables Found: " . (count($tables) - count($missingTables)) . PHP_EOL;
echo "❌ Tables Missing: " . count($missingTables) . PHP_EOL;

if (!empty($missingTables)) {
    echo PHP_EOL . "MISSING TABLES:" . PHP_EOL;
    foreach ($missingTables as $table) {
        echo "  - $table" . PHP_EOL;
    }
}

echo PHP_EOL . "❌ Columns Missing: " . count($missingColumns) . PHP_EOL;

if (!empty($missingColumns)) {
    echo PHP_EOL . "MISSING COLUMNS (first 20):" . PHP_EOL;
    $count = 0;
    foreach ($missingColumns as $col) {
        echo "  - $col" . PHP_EOL;
        $count++;
        if ($count >= 20) break;
    }
    if (count($missingColumns) > 20) {
        echo "  ... and " . (count($missingColumns) - 20) . " more" . PHP_EOL;
    }
}
