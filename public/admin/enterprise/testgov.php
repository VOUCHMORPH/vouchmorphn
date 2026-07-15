<?php
// test_batch_system.php - Comprehensive Batch System Diagnostic
// Run this to test your entire batch workflow

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html>
<html>
<head>
    <title>Batch System Diagnostic</title>
    <style>
        body { font-family: 'Courier New', monospace; padding: 20px; background: #0f172a; color: #e2e8f0; }
        .container { max-width: 1200px; margin: 0 auto; }
        .test-section { background: #1e293b; padding: 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #fbbf24; }
        .pass { color: #10b981; font-weight: bold; }
        .fail { color: #ef4444; font-weight: bold; }
        .warning { color: #f59e0b; font-weight: bold; }
        .info { color: #60a5fa; }
        pre { background: #0f172a; padding: 15px; border-radius: 4px; overflow-x: auto; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { padding: 8px 12px; text-align: left; border-bottom: 1px solid #334155; }
        th { background: #0f172a; font-weight: bold; color: #fbbf24; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; }
        .badge-success { background: #10b981; color: white; }
        .badge-danger { background: #ef4444; color: white; }
        .badge-warning { background: #f59e0b; color: white; }
        .badge-info { background: #3b82f6; color: white; }
        h1, h2, h3 { color: #fbbf24; }
        hr { border-color: #334155; margin: 20px 0; }
        .summary-box { background: #0f172a; padding: 15px; border-radius: 4px; margin: 10px 0; }
    </style>
</head>
<body>
<div class='container'>
    <h1>🔍 VouchMorph Batch System Diagnostic</h1>
    <p>Running: " . date('Y-m-d H:i:s') . "</p>
    <hr>";

// ============================================
// TEST 1: Database Connection
// ============================================
echo "<div class='test-section'>";
echo "<h2>TEST 1: Database Connection</h2>";

try {
    require_once '../../../../src/Core/Database/DBConnection.php';
    use Core\Database\DBConnection;
    
    $db = DBConnection::getConnection();
    if ($db) {
        echo "<p class='pass'>✅ Database connected successfully</p>";
        echo "<p class='info'>Connection info: " . get_class($db) . "</p>";
    } else {
        echo "<p class='fail'>❌ Database connection failed</p>";
    }
} catch (Exception $e) {
    echo "<p class='fail'>❌ Database connection error: " . $e->getMessage() . "</p>";
    echo "</div></body></html>";
    exit;
}
echo "</div>";

// ============================================
// TEST 2: Check Tables
// ============================================
echo "<div class='test-section'>";
echo "<h2>TEST 2: Required Tables</h2>";

$requiredTables = [
    'import_batches',
    'import_rows',
    'departments',
    'programs',
    'users',
    'organization_users'
];

$tablesExist = [];
foreach ($requiredTables as $table) {
    try {
        $stmt = $db->query("SELECT 1 FROM $table LIMIT 1");
        $tablesExist[$table] = true;
        echo "<p class='pass'>✅ Table '$table' exists and is accessible</p>";
    } catch (PDOException $e) {
        $tablesExist[$table] = false;
        echo "<p class='fail'>❌ Table '$table' missing or inaccessible: " . $e->getMessage() . "</p>";
    }
}
echo "</div>";

// ============================================
// TEST 3: Organization Context
// ============================================
echo "<div class='test-section'>";
echo "<h2>TEST 3: Organization Context</h2>";

try {
    require_once '../auth.php';
    $user = requireEnterpriseAuth();
    
    if ($user) {
        echo "<p class='pass'>✅ User authenticated</p>";
        echo "<div class='summary-box'>";
        echo "<pre>" . json_encode([
            'id' => $user['id'] ?? $user['user_id'] ?? 'N/A',
            'email' => $user['email'] ?? 'N/A',
            'role' => $user['role'] ?? 'N/A',
            'organization_name' => $user['organization_name'] ?? 'N/A',
            'organization_id' => getOrganizationId() ?? 'N/A',
        ], JSON_PRETTY_PRINT) . "</pre>";
        echo "</div>";
    } else {
        echo "<p class='fail'>❌ Authentication failed</p>";
    }
} catch (Exception $e) {
    echo "<p class='fail'>❌ Auth error: " . $e->getMessage() . "</p>";
}
echo "</div>";

// ============================================
// TEST 4: Check Batch Tables Structure
// ============================================
echo "<div class='test-section'>";
echo "<h2>TEST 4: Table Structure</h2>";

try {
    $stmt = $db->query("
        SELECT column_name, data_type, is_nullable 
        FROM information_schema.columns 
        WHERE table_name = 'import_batches' 
        ORDER BY ordinal_position
    ");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>import_batches columns:</h3>";
    echo "<table>";
    echo "<tr><th>Column</th><th>Type</th><th>Nullable</th></tr>";
    foreach ($columns as $col) {
        echo "<tr>
            <td><strong>" . $col['column_name'] . "</strong></td>
            <td>" . $col['data_type'] . "</td>
            <td>" . $col['is_nullable'] . "</td>
        </tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<p class='fail'>❌ Error checking structure: " . $e->getMessage() . "</p>";
}
echo "</div>";

// ============================================
// TEST 5: Batch Data Existence
// ============================================
echo "<div class='test-section'>";
echo "<h2>TEST 5: Existing Batch Data</h2>";

$orgId = getOrganizationId();

try {
    // Count total batches
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM import_batches WHERE organization_id = :org_id");
    $stmt->execute([':org_id' => $orgId]);
    $totalBatches = $stmt->fetchColumn();
    echo "<p>📊 Total batches in system: <strong>$totalBatches</strong></p>";
    
    if ($totalBatches > 0) {
        // Get recent batches
        $stmt = $db->prepare("
            SELECT 
                b.*,
                u.full_name as uploaded_by_name,
                d.name as department_name
            FROM import_batches b
            LEFT JOIN users u ON b.uploaded_by = u.id
            LEFT JOIN departments d ON b.department_id = d.id
            WHERE b.organization_id = :org_id
            ORDER BY b.created_at DESC
            LIMIT 10
        ");
        $stmt->execute([':org_id' => $orgId]);
        $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h3>Recent Batches:</h3>";
        echo "<table>";
        echo "<tr>
            <th>ID</th>
            <th>Reference</th>
            <th>Name</th>
            <th>Status</th>
            <th>Rows</th>
            <th>Valid</th>
            <th>Invalid</th>
            <th>Amount</th>
            <th>Created</th>
        </tr>";
        foreach ($batches as $batch) {
            // Get row counts
            $rowStmt = $db->prepare("
                SELECT 
                    COUNT(*) as total,
                    COUNT(CASE WHEN validation_status = 'VALID' THEN 1 END) as valid,
                    COUNT(CASE WHEN validation_status = 'INVALID' THEN 1 END) as invalid,
                    SUM(amount) as total_amount
                FROM import_rows 
                WHERE batch_id = :batch_id
            ");
            $rowStmt->execute([':batch_id' => $batch['id']]);
            $stats = $rowStmt->fetch(PDO::FETCH_ASSOC);
            
            echo "<tr>
                <td>" . $batch['id'] . "</td>
                <td><strong>" . htmlspecialchars($batch['batch_reference']) . "</strong></td>
                <td>" . htmlspecialchars($batch['batch_name']) . "</td>
                <td><span class='badge badge-" . 
                    ($batch['status'] === 'READY_FOR_APPROVAL' ? 'success' : 
                     ($batch['status'] === 'VALIDATED_WITH_ERRORS' ? 'warning' : 'info')) . 
                    "'>" . $batch['status'] . "</span></td>
                <td>" . ($stats['total'] ?? 0) . "</td>
                <td>" . ($stats['valid'] ?? 0) . "</td>
                <td>" . ($stats['invalid'] ?? 0) . "</td>
                <td>P" . number_format($stats['total_amount'] ?? 0, 2) . "</td>
                <td>" . date('Y-m-d H:i', strtotime($batch['created_at'])) . "</td>
            </tr>";
            
            // Check for errors
            if ($batch['status'] === 'VALIDATED_WITH_ERRORS') {
                echo "<tr><td colspan='9' style='color: #f59e0b;'>⚠️ This batch has invalid entries</td></tr>";
            }
            
            // Check if rows exist
            if (($stats['total'] ?? 0) == 0 && $batch['status'] !== 'UPLOADED') {
                echo "<tr><td colspan='9' style='color: #ef4444;'>❌ Batch has no rows!</td></tr>";
            }
        }
        echo "</table>";
    } else {
        echo "<p class='warning'>⚠️ No batches found for this organization</p>";
    }
} catch (Exception $e) {
    echo "<p class='fail'>❌ Error checking batches: " . $e->getMessage() . "</p>";
}
echo "</div>";

// ============================================
// TEST 6: Check Specific Batch (if provided)
// ============================================
$testBatchId = $_GET['batch_id'] ?? null;
if ($testBatchId) {
    echo "<div class='test-section'>";
    echo "<h2>TEST 6: Specific Batch #$testBatchId</h2>";
    
    try {
        $stmt = $db->prepare("
            SELECT * FROM import_batches 
            WHERE id = :id AND organization_id = :org_id
        ");
        $stmt->execute([':id' => $testBatchId, ':org_id' => $orgId]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($batch) {
            echo "<p class='pass'>✅ Batch found!</p>";
            echo "<div class='summary-box'>";
            echo "<pre>" . json_encode($batch, JSON_PRETTY_PRINT) . "</pre>";
            echo "</div>";
            
            // Check rows
            $stmt = $db->prepare("SELECT * FROM import_rows WHERE batch_id = :batch_id");
            $stmt->execute([':batch_id' => $testBatchId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo "<p>📊 Total rows: <strong>" . count($rows) . "</strong></p>";
            if (count($rows) > 0) {
                echo "<table>";
                echo "<tr>
                    <th>Row #</th>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Errors</th>
                </tr>";
                foreach ($rows as $row) {
                    $errors = json_decode($row['validation_errors'] ?? '[]', true);
                    echo "<tr>
                        <td>" . $row['row_number'] . "</td>
                        <td>" . htmlspecialchars($row['recipient_name']) . "</td>
                        <td>" . htmlspecialchars($row['recipient_phone']) . "</td>
                        <td>P" . number_format($row['amount'], 2) . "</td>
                        <td><span class='badge badge-" . 
                            ($row['validation_status'] === 'VALID' ? 'success' : 'danger') . 
                            "'>" . $row['validation_status'] . "</span></td>
                        <td>" . (count($errors) > 0 ? implode(', ', $errors) : '—') . "</td>
                    </tr>";
                }
                echo "</table>";
            } else {
                echo "<p class='warning'>⚠️ This batch has no rows!</p>";
            }
        } else {
            echo "<p class='fail'>❌ Batch not found or not in your organization</p>";
        }
    } catch (Exception $e) {
        echo "<p class='fail'>❌ Error: " . $e->getMessage() . "</p>";
    }
    echo "</div>";
}

// ============================================
// TEST 7: File Upload Directory
// ============================================
echo "<div class='test-section'>";
echo "<h2>TEST 7: Upload Directory</h2>";

$uploadDirs = [
    '/tmp/vouchmorph_uploads/',
    '/var/www/html/uploads/kyc/',
    dirname(__DIR__, 3) . '/uploads/'
];

foreach ($uploadDirs as $dir) {
    echo "<p>Checking: <code>$dir</code></p>";
    if (is_dir($dir)) {
        echo "<p class='pass'>✅ Directory exists</p>";
        
        // Check permissions
        if (is_writable($dir)) {
            echo "<p class='pass'>✅ Directory is writable</p>";
        } else {
            echo "<p class='fail'>❌ Directory is NOT writable</p>";
        }
        
        // Count files
        $files = glob($dir . '*');
        if ($files) {
            echo "<p>📁 Files: " . count($files) . "</p>";
            if (count($files) > 0) {
                echo "<ul>";
                foreach (array_slice($files, 0, 5) as $file) {
                    echo "<li>" . basename($file) . " (" . round(filesize($file)/1024, 2) . " KB)</li>";
                }
                if (count($files) > 5) echo "<li>... and " . (count($files) - 5) . " more</li>";
                echo "</ul>";
            }
        } else {
            echo "<p class='warning'>⚠️ Directory is empty</p>";
        }
    } else {
        echo "<p class='fail'>❌ Directory does not exist</p>";
        
        // Try to create it
        if (mkdir($dir, 0755, true)) {
            echo "<p class='pass'>✅ Directory created successfully</p>";
        } else {
            echo "<p class='fail'>❌ Could not create directory</p>";
        }
    }
    echo "<br>";
}
echo "</div>";

// ============================================
// TEST 8: Manual Entry Data Test
// ============================================
echo "<div class='test-section'>";
echo "<h2>TEST 8: Manual Entry Data Test</h2>";

try {
    // Check if we can insert and retrieve a test batch
    $testRef = 'TEST_' . date('Ymd_His');
    
    $stmt = $db->prepare("
        INSERT INTO import_batches (
            organization_id, batch_reference, batch_name, source_format,
            payment_mode, status, execution_mode, total_rows, 
            total_amount, currency, created_at, updated_at
        ) VALUES (
            :org_id, :ref, 'Test Batch', 'MANUAL',
            'BULK', 'UPLOADED', 'MANUAL', 0,
            0, 'BWP', NOW(), NOW()
        ) RETURNING id
    ");
    $stmt->execute([':org_id' => $orgId, ':ref' => $testRef]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $testId = $result['id'] ?? null;
    
    if ($testId) {
        echo "<p class='pass'>✅ Successfully inserted test batch with ID: $testId</p>";
        
        // Now insert a test row
        $stmt = $db->prepare("
            INSERT INTO import_rows (
                batch_id, row_number, recipient_name, recipient_phone,
                amount, validation_status, raw_data, created_at
            ) VALUES (
                :batch_id, 1, 'Test User', '+26771234567',
                100.00, 'VALID', '{\"test\": true}', NOW()
            )
        ");
        $stmt->execute([':batch_id' => $testId]);
        
        echo "<p class='pass'>✅ Successfully inserted test row</p>";
        
        // Verify it exists
        $stmt = $db->prepare("
            SELECT COUNT(*) as count FROM import_rows WHERE batch_id = :batch_id
        ");
        $stmt->execute([':batch_id' => $testId]);
        $count = $stmt->fetchColumn();
        
        if ($count > 0) {
            echo "<p class='pass'>✅ Row verified in database ($count rows found)</p>";
        } else {
            echo "<p class='fail'>❌ Row not found in database</p>";
        }
        
        // Clean up test data
        $db->prepare("DELETE FROM import_rows WHERE batch_id = :batch_id")->execute([':batch_id' => $testId]);
        $db->prepare("DELETE FROM import_batches WHERE id = :id")->execute([':id' => $testId]);
        echo "<p class='info'>🧹 Test data cleaned up</p>";
        
    } else {
        echo "<p class='fail'>❌ Failed to insert test batch</p>";
    }
} catch (Exception $e) {
    echo "<p class='fail'>❌ Error during test: " . $e->getMessage() . "</p>";
}
echo "</div>";

// ============================================
// SUMMARY
// ============================================
echo "<div class='test-section' style='border-left-color: #10b981;'>";
echo "<h2>📊 Diagnostic Summary</h2>";

// Check for common issues
$issues = [];

if (!$tablesExist['import_batches'] ?? false) {
    $issues[] = "❌ import_batches table missing";
}
if (!$tablesExist['import_rows'] ?? false) {
    $issues[] = "❌ import_rows table missing";
}

try {
    $stmt = $db->query("SELECT COUNT(*) FROM import_batches");
    if ($stmt->fetchColumn() == 0) {
        $issues[] = "⚠️ No batches found in system";
    }
} catch (Exception $e) {
    $issues[] = "❌ Cannot query import_batches";
}

if (empty($issues)) {
    echo "<p class='pass'>✅ All systems appear to be working correctly!</p>";
    echo "<p class='info'>💡 If batches still aren't showing, check:</p>";
    echo "<ul>
        <li>The batch status (should be 'READY_FOR_APPROVAL' or 'UPLOADED')</li>
        <li>That rows exist in import_rows for your batches</li>
        <li>The organization_id matches your current context</li>
        <li>Any filtering in your dashboard (status, date, etc.)</li>
    </ul>";
} else {
    echo "<p class='fail'>❌ Issues found:</p>";
    echo "<ul>";
    foreach ($issues as $issue) {
        echo "<li>$issue</li>";
    }
    echo "</ul>";
}

echo "</div>";

// ============================================
// RECOMMENDATIONS
// ============================================
echo "<div class='test-section' style='border-left-color: #60a5fa;'>";
echo "<h2>🔧 Quick Fix Recommendations</h2>";

if (!is_dir('/tmp/vouchmorph_uploads/')) {
    echo "<p class='warning'>💡 Create upload directory: <code>mkdir -p /tmp/vouchmorph_uploads/ && chmod 755 /tmp/vouchmorph_uploads/</code></p>";
}

if (isset($issues) && in_array("❌ No batches found in system", $issues)) {
    echo "<p class='warning'>💡 Add a test batch to verify the system works: <a href='manual.php'>Go to Manual Entry</a></p>";
}

echo "<p class='info'>📌 You can test a specific batch by adding: <code>?batch_id=123</code> to this URL</p>";

echo "</div>";

// ============================================
// Footer
// ============================================
echo "
<hr>
<p style='color: #64748b;'>Diagnostic completed at " . date('Y-m-d H:i:s') . "</p>
<p style='color: #64748b; font-size: 12px;'>VouchMorph Enterprise - Batch System Diagnostic Tool</p>
</div>
</body>
</html>";

// Helper function if not defined
if (!function_exists('getOrganizationId')) {
    function getOrganizationId() {
        global $user;
        return $user['organization_id'] ?? null;
    }
}
?>
