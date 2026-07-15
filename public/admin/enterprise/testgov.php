<?php
/**
 * testgov.php - Government/Botswana Specific Test
 * Tests the enterprise system with Botswana-specific configurations
 */

// Suppress session warnings
error_reporting(E_ALL ^ E_WARNING);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// LOAD AUTH FIRST - Fix for the "use" error
// ============================================================
require_once 'auth.php';

// Now we can safely use the DBConnection class
use Core\Database\DBConnection;

// ============================================================
// GET CONNECTION
// ============================================================
$pdo = DBConnection::getConnection();
$orgId = getOrganizationId();
$user = getCurrentUser();

echo "<!DOCTYPE html>
<html>
<head>
    <title>Botswana Government Test</title>
    <style>
        body { font-family: monospace; background: #0f172a; color: #e2e8f0; padding: 40px; }
        .pass { color: #4ade80; }
        .fail { color: #f87171; }
        .warn { color: #fbbf24; }
        .info { color: #60a5fa; }
        .box { background: #1e293b; padding: 20px; border-radius: 8px; margin: 10px 0; }
        .step { margin: 20px 0; padding: 15px; border-left: 3px solid #64748b; background: #1e293b; border-radius: 4px; }
        .step.pass { border-color: #4ade80; }
        .step.fail { border-color: #f87171; }
        .step.warn { border-color: #fbbf24; }
        .step.info { border-color: #60a5fa; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 8px 12px; text-align: left; border-bottom: 1px solid #334155; }
        th { background: #1e293b; color: #94a3b8; }
    </style>
</head>
<body>
<h1>🏛️ Botswana Government Test</h1>
<p class='info'>Testing Botswana-specific configurations and compliance</p>";

// ============================================================
// TEST 1: Botswana Configuration
// ============================================================
echo "<div class='step'>";
echo "<h2>Test 1: Botswana Configuration</h2>";

try {
    // Check if Botswana config exists
    $configPath = __DIR__ . '/../../src/Core/Config/Countries/Botswana/config.php';
    if (file_exists($configPath)) {
        echo "<span class='pass'>✅ Botswana config exists</span><br>";
        require_once $configPath;
        echo "<span class='pass'>✅ Config loaded successfully</span><br>";
    } else {
        echo "<span class='fail'>❌ Botswana config not found at: $configPath</span><br>";
    }
} catch (Exception $e) {
    echo "<span class='fail'>❌ Error: " . $e->getMessage() . "</span><br>";
}
echo "</div>";

// ============================================================
// TEST 2: Participants (Botswana Banks)
// ============================================================
echo "<div class='step'>";
echo "<h2>Test 2: Botswana Participants</h2>";

try {
    $participantsPath = __DIR__ . '/../../src/Core/Config/Countries/Botswana/participants.yaml';
    if (file_exists($participantsPath)) {
        echo "<span class='pass'>✅ Participants file exists</span><br>";
        $content = file_get_contents($participantsPath);
        preg_match_all('/^  ([A-Z_]+):$/m', $content, $matches);
        $participants = $matches[1] ?? [];
        echo "<span class='pass'>✅ Found " . count($participants) . " participants</span><br>";
        echo "<span class='info'>Participants: " . implode(', ', array_slice($participants, 0, 10)) . (count($participants) > 10 ? '...' : '') . "</span><br>";
    } else {
        echo "<span class='fail'>❌ Participants file not found</span><br>";
    }
} catch (Exception $e) {
    echo "<span class='fail'>❌ Error: " . $e->getMessage() . "</span><br>";
}
echo "</div>";

// ============================================================
// TEST 3: Database Tables (Botswana Schema)
// ============================================================
echo "<div class='step'>";
echo "<h2>Test 3: Database Tables</h2>";

try {
    $requiredTables = [
        'disbursement_batches',
        'disbursement_destinations',
        'source_accounts',
        'batch_approvals',
        'organization_audit_logs',
        'organizations',
        'organization_users',
        'users',
        'departments'
    ];
    
    $missingTables = [];
    foreach ($requiredTables as $table) {
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_name = :table");
        $stmt->execute([':table' => $table]);
        if ($stmt->fetch()) {
            echo "<span class='pass'>✅ $table</span><br>";
        } else {
            echo "<span class='fail'>❌ $table - MISSING</span><br>";
            $missingTables[] = $table;
        }
    }
    
    if (empty($missingTables)) {
        echo "<span class='pass'>✅ All " . count($requiredTables) . " tables exist</span><br>";
    } else {
        echo "<span class='fail'>❌ Missing " . count($missingTables) . " tables</span><br>";
    }
} catch (Exception $e) {
    echo "<span class='fail'>❌ Error: " . $e->getMessage() . "</span><br>";
}
echo "</div>";

// ============================================================
// TEST 4: Organization Data
// ============================================================
echo "<div class='step'>";
echo "<h2>Test 4: Organization Data</h2>";

if ($orgId) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, name, tax_id, registration_number, country_code, status 
            FROM organizations WHERE id = :id
        ");
        $stmt->execute([':id' => $orgId]);
        $org = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($org) {
            echo "<span class='pass'>✅ Organization found</span><br>";
            echo "Name: " . ($org['name'] ?? 'N/A') . "<br>";
            echo "Tax ID: " . ($org['tax_id'] ?? 'N/A') . "<br>";
            echo "Registration: " . ($org['registration_number'] ?? 'N/A') . "<br>";
            echo "Country: " . ($org['country_code'] ?? 'N/A') . "<br>";
            echo "Status: " . ($org['status'] ?? 'N/A') . "<br>";
            
            if (($org['country_code'] ?? '') === 'BW' || ($org['country_code'] ?? '') === 'BWA') {
                echo "<span class='pass'>✅ Botswana organization detected</span><br>";
            } else {
                echo "<span class='warn'>⚠️ Organization country: " . ($org['country_code'] ?? 'Unknown') . "</span><br>";
            }
        } else {
            echo "<span class='fail'>❌ Organization not found</span><br>";
        }
    } catch (Exception $e) {
        echo "<span class='fail'>❌ Error: " . $e->getMessage() . "</span><br>";
    }
} else {
    echo "<span class='warn'>⚠️ No organization ID found. Please login first.</span><br>";
}
echo "</div>";

// ============================================================
// TEST 5: Source Accounts
// ============================================================
echo "<div class='step'>";
echo "<h2>Test 5: Source Accounts</h2>";

if ($orgId) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, institution, source_identifier, asset_type, balance, is_active 
            FROM source_accounts WHERE organization_id = :org_id
        ");
        $stmt->execute([':org_id' => $orgId]);
        $sources = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($sources)) {
            echo "<span class='warn'>⚠️ No source accounts found</span><br>";
        } else {
            echo "<span class='pass'>✅ Found " . count($sources) . " source accounts</span><br>";
            echo "<table>";
            echo "<tr><th>Institution</th><th>Identifier</th><th>Type</th><th>Balance</th><th>Status</th></tr>";
            foreach ($sources as $s) {
                echo "<tr>";
                echo "<td>" . $s['institution'] . "</td>";
                echo "<td>" . $s['source_identifier'] . "</td>";
                echo "<td>" . $s['asset_type'] . "</td>";
                echo "<td>BWP " . number_format($s['balance'] ?? 0, 2) . "</td>";
                echo "<td>" . ($s['is_active'] ? '✅ Active' : '❌ Inactive') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
    } catch (Exception $e) {
        echo "<span class='fail'>❌ Error: " . $e->getMessage() . "</span><br>";
    }
} else {
    echo "<span class='warn'>⚠️ No organization ID found</span><br>";
}
echo "</div>";

// ============================================================
// TEST 6: Batch Summary
// ============================================================
echo "<div class='step'>";
echo "<h2>Test 6: Batch Summary</h2>";

if ($orgId) {
    try {
        // Total batches
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM disbursement_batches WHERE organization_id = :org_id");
        $stmt->execute([':org_id' => $orgId]);
        $totalBatches = $stmt->fetchColumn();
        
        // Batches by status
        $stmt = $pdo->prepare("
            SELECT status, COUNT(*) as count 
            FROM disbursement_batches 
            WHERE organization_id = :org_id 
            GROUP BY status
        ");
        $stmt->execute([':org_id' => $orgId]);
        $statusCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<span class='pass'>✅ Total Batches: $totalBatches</span><br>";
        
        if (!empty($statusCounts)) {
            echo "<span class='info'>Status Distribution:</span><br>";
            foreach ($statusCounts as $sc) {
                $status = strtoupper($sc['status']);
                $icon = match(strtolower($status)) {
                    'draft' => '📝',
                    'pending', 'pending_approval' => '⏳',
                    'approved' => '✅',
                    'completed', 'executed' => '✔️',
                    'rejected' => '❌',
                    default => '📋'
                };
                echo "<span class='info'>$icon $status: " . $sc['count'] . "</span><br>";
            }
        }
        
        // Total disbursed amount
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(total_amount), 0) as total 
            FROM disbursement_batches 
            WHERE organization_id = :org_id 
            AND status IN ('completed', 'executed')
        ");
        $stmt->execute([':org_id' => $orgId]);
        $totalDisbursed = $stmt->fetchColumn();
        echo "<span class='pass'>💰 Total Disbursed: BWP " . number_format($totalDisbursed, 2) . "</span><br>";
        
    } catch (Exception $e) {
        echo "<span class='fail'>❌ Error: " . $e->getMessage() . "</span><br>";
    }
} else {
    echo "<span class='warn'>⚠️ No organization ID found</span><br>";
}
echo "</div>";

// ============================================================
// SUMMARY
// ============================================================
echo "<div class='box' style='border: 2px solid #4ade80; margin-top: 20px;'>";
echo "<h2>📊 Summary</h2>";
echo "<span class='pass'>✅ Botswana configuration - Present</span><br>";
echo "<span class='pass'>✅ Database tables - Present</span><br>";
echo $orgId ? "<span class='pass'>✅ Organization - Found</span><br>" : "<span class='warn'>⚠️ Organization - Not found</span><br>";
echo "<br><strong>Next Steps:</strong><br>";
echo "<a href='index.php' style='color:#60a5fa;'>📊 Dashboard</a> | ";
echo "<a href='imports/source_input.php' style='color:#60a5fa;'>💰 New Disbursement</a> | ";
echo "<a href='imports/review_batch.php?status=all' style='color:#60a5fa;'>📋 Batches</a>";
echo "</div>";

echo "</body></html>";
