<?php
// check_swap_tables_direct.php
// Direct check of all tables for the swap

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';

use Core\Database\DBConnection;

$pdo = DBConnection::getConnection();

if (!$pdo) {
    die("❌ Failed to connect to database\n");
}

// Get the latest swap reference from the test output
$swapRef = $argv[1] ?? 'SWAP_TEST_1784868028_e47749cb';

echo "========================================\n";
echo "DIRECT TABLE CHECK\n";
echo "========================================\n\n";

echo "Checking for swap reference: {$swapRef}\n\n";

// Check all tables
$tables = [
    'swap_requests' => 'swap_uuid',
    'hold_transactions' => 'swap_reference',
    'cashout_authorizations' => 'swap_reference',
    'swap_transactions' => 'swap_id (needs join)',
    'message_outbox' => 'payload',
];

foreach ($tables as $table => $column) {
    echo "📋 {$table}:\n";
    try {
        if ($table === 'swap_transactions') {
            // Need to get swap_id first
            $stmt = $pdo->prepare("SELECT swap_id FROM swap_requests WHERE swap_uuid = :ref");
            $stmt->execute([':ref' => $swapRef]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $stmt = $pdo->prepare("SELECT * FROM swap_transactions WHERE swap_id = :swap_id");
                $stmt->execute([':swap_id' => $row['swap_id']]);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if ($results) {
                    echo "   ✅ FOUND " . count($results) . " record(s)\n";
                    foreach ($results as $r) {
                        echo "      transaction_id: {$r['transaction_id']}\n";
                        echo "      amount: {$r['amount']}\n";
                        echo "      status: {$r['status']}\n";
                    }
                } else {
                    echo "   ❌ NOT FOUND\n";
                }
            } else {
                echo "   ⏭️  SKIPPED (no swap_id)\n";
            }
        } elseif ($table === 'message_outbox') {
            $stmt = $pdo->prepare("SELECT * FROM message_outbox WHERE payload LIKE :pattern ORDER BY created_at DESC LIMIT 10");
            $stmt->execute([':pattern' => '%' . $swapRef . '%']);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($results) {
                echo "   ✅ FOUND " . count($results) . " record(s)\n";
                foreach ($results as $r) {
                    echo "      message_id: {$r['message_id']}\n";
                    echo "      destination: {$r['destination']}\n";
                    echo "      status: {$r['status']}\n";
                }
            } else {
                echo "   ❌ NOT FOUND\n";
            }
        } else {
            $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE {$column} = :ref");
            $stmt->execute([':ref' => $swapRef]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($results) {
                echo "   ✅ FOUND " . count($results) . " record(s)\n";
                foreach ($results as $r) {
                    echo "      " . json_encode($r) . "\n";
                }
            } else {
                echo "   ❌ NOT FOUND\n";
            }
        }
    } catch (PDOException $e) {
        echo "   ❌ ERROR: " . $e->getMessage() . "\n";
    }
    echo "\n";
}

// Also check if there's any data at all in these tables
echo "📊 Checking if tables have ANY data:\n\n";

$tablesWithData = [];
foreach (['swap_requests', 'hold_transactions', 'cashout_authorizations'] as $table) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM {$table}");
        $stmt->execute();
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        echo "   {$table}: {$count} record(s)\n";
        if ($count > 0) {
            $tablesWithData[] = $table;
        }
    } catch (PDOException $e) {
        echo "   {$table}: ERROR - " . $e->getMessage() . "\n";
    }
}

echo "\n";

if (empty($tablesWithData)) {
    echo "❌ All tables are EMPTY! The transaction is being rolled back.\n";
    echo "   Check if the atomic transaction is being committed properly.\n";
} else {
    echo "✅ Tables have data, but the specific swap wasn't found.\n";
    echo "   Check if populateTrackingTables() is being called with the correct reference.\n";
}

echo "\n========================================\n";
echo "CHECK COMPLETE\n";
echo "========================================\n";
