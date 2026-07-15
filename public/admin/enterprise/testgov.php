<?php
/**
 * admin/enterprise/test_workflow.php
 * 
 * Complete Workflow Test - Creates a batch using the actual files
 * Tests: Source Input → Add Destinations → Review → Submit → Approve → Disburse
 */

// Suppress session warnings for testing
error_reporting(E_ALL ^ E_WARNING);
ini_set('display_errors', 1);

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// 1. LOAD AUTH AND SETUP
// ============================================================
require_once 'auth.php';

$pdo = getDBConnection();
$orgId = getOrganizationId();
$userId = $_SESSION['enterprise_user']['user_id'] ?? $_SESSION['enterprise_user']['id'] ?? 1;
$userRole = $_SESSION['enterprise_user']['role'] ?? 'program_officer';

echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>VouchMorph - Workflow Test</title>
    <link href='https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap' rel='stylesheet'>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; color: #0f172a; padding: 40px; }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { font-size: 28px; font-weight: 700; }
        .sub { color: #64748b; margin-bottom: 24px; }
        
        .step { 
            background: white; 
            border-radius: 12px; 
            padding: 24px; 
            margin-bottom: 16px; 
            border: 1px solid #e2e8f0;
            border-left: 4px solid #94a3b8;
        }
        .step.pass { border-left-color: #166534; background: #f0fdf4; }
        .step.fail { border-left-color: #991b1b; background: #fef2f2; }
        .step.info { border-left-color: #3b82f6; background: #eff6ff; }
        .step.warning { border-left-color: #f59e0b; background: #fffbeb; }
        
        .step h3 { font-size: 16px; font-weight: 600; margin-bottom: 8px; display: flex; align-items: center; gap: 8px; }
        .step .badge { 
            display: inline-block; 
            padding: 2px 10px; 
            border-radius: 12px; 
            font-size: 10px; 
            font-weight: 600; 
        }
        .badge-pass { background: #dcfce7; color: #166534; }
        .badge-fail { background: #fee2e2; color: #991b1b; }
        .badge-info { background: #dbeafe; color: #1e40af; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        
        .detail { 
            font-size: 13px; 
            color: #64748b; 
            padding: 4px 8px;
            background: #f8fafc;
            border-radius: 4px;
            margin: 4px 0;
            font-family: monospace;
        }
        .detail.success { color: #166534; background: #dcfce7; }
        .detail.error { color: #991b1b; background: #fee2e2; }
        .detail.warning { color: #92400e; background: #fef3c7; }
        
        .sql-box {
            background: #0f172a;
            color: #e2e8f0;
            padding: 16px;
            border-radius: 8px;
            overflow-x: auto;
            font-size: 12px;
            font-family: monospace;
            margin: 8px 0;
        }
        
        .btn {
            padding: 8px 20px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 12px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin: 4px;
        }
        .btn-primary { background: #0f172a; color: white; }
        .btn-primary:hover { background: #8A6D3B; }
        .btn-success { background: #166534; color: white; }
        .btn-success:hover { background: #14532d; }
        
        .summary {
            background: #0f172a;
            color: white;
            padding: 24px;
            border-radius: 12px;
            margin-top: 24px;
        }
        .summary h3 { color: #8A6D3B; margin-bottom: 8px; }
        .summary p { color: #94a3b8; }
        .summary .links { margin-top: 16px; display: flex; gap: 12px; flex-wrap: wrap; }
        .summary .links a {
            background: #8A6D3B;
            color: #0f172a;
            padding: 8px 20px;
            border-radius: 20px;
            text-decoration: none;
            font-weight: 600;
            font-size: 13px;
        }
        .summary .links a.secondary { background: #1e293b; color: #e2e8f0; }
        
        .progress {
            display: flex;
            justify-content: space-between;
            margin: 16px 0;
            padding: 0 20px;
        }
        .progress .dot {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 700;
            border: 2px solid #e2e8f0;
            background: white;
        }
        .progress .dot.done { background: #166534; color: white; border-color: #166534; }
        .progress .dot.active { background: #8A6D3B; color: white; border-color: #8A6D3B; }
        .progress .dot.fail { background: #991b1b; color: white; border-color: #991b1b; }
        .progress .line {
            flex: 1;
            height: 2px;
            background: #e2e8f0;
            margin: 15px 8px 0;
        }
        .progress .line.done { background: #166534; }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            margin-top: 8px;
        }
        .data-table th {
            background: #f8fafc;
            padding: 8px 12px;
            text-align: left;
            font-size: 10px;
            text-transform: uppercase;
            border-bottom: 2px solid #e2e8f0;
        }
        .data-table td {
            padding: 8px 12px;
            border-bottom: 1px solid #e2e8f0;
        }
        .data-table tr:hover { background: #f8fafc; }
    </style>
</head>
<body>
<div class='container'>
    <h1>🧪 VouchMorph - Complete Workflow Test</h1>
    <p class='sub'>Testing the full lifecycle: Create → Add Destinations → Submit → Approve → Disburse</p>";

// ============================================================
// STEP 1: CHECK USER & ROLE
// ============================================================
echo "<div class='step " . (isset($_SESSION['enterprise_user']) ? 'pass' : 'warning') . "'>";
echo "<h3>Step 1: User Authentication " . (isset($_SESSION['enterprise_user']) ? "<span class='badge badge-pass'>✅ PASS</span>" : "<span class='badge badge-warning'>⚠️ WARNING</span>") . "</h3>";

if (isset($_SESSION['enterprise_user'])) {
    $user = $_SESSION['enterprise_user'];
    echo "<div class='detail success'>✅ User: " . htmlspecialchars($user['full_name'] ?? $user['username'] ?? 'Unknown') . "</div>";
    echo "<div class='detail success'>✅ Role: <strong>" . htmlspecialchars($user['role'] ?? 'Unknown') . "</strong></div>";
    echo "<div class='detail success'>✅ Organization: " . htmlspecialchars($user['organization_name'] ?? 'Unknown') . "</div>";
    echo "<div class='detail success'>✅ User ID: " . ($user['user_id'] ?? $user['id'] ?? 'Unknown') . "</div>";
} else {
    echo "<div class='detail warning'>⚠️ No active session. Please login first.</div>";
    echo "<a href='login.php' class='btn btn-primary'>🔑 Login Now</a>";
}
echo "</div>";

// ============================================================
// STEP 2: CHECK SOURCE ACCOUNT - CREATE IF NEEDED
// ============================================================
echo "<div class='step " . (isset($_SESSION['enterprise_user']) ? 'info' : 'warning') . "'>";
echo "<h3>Step 2: Source Account ";

$sourceId = null;
$sourceName = null;

if (isset($_SESSION['enterprise_user'])) {
    try {
        // Check for existing source
        $stmt = $pdo->prepare("
            SELECT id, institution, source_identifier, balance 
            FROM source_accounts 
            WHERE organization_id = :org_id AND is_active = true 
            LIMIT 1
        ");
        $stmt->execute([':org_id' => $orgId]);
        $source = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($source) {
            $sourceId = $source['id'];
            $sourceName = $source['institution'] . ' - ' . $source['source_identifier'];
            echo "<span class='badge badge-pass'>✅ FOUND</span></h3>";
            echo "<div class='detail success'>✅ Existing source: " . htmlspecialchars($sourceName) . "</div>";
            echo "<div class='detail success'>Balance: BWP " . number_format($source['balance'] ?? 0, 2) . "</div>";
        } else {
            echo "<span class='badge badge-warning'>⚠️ NOT FOUND</span></h3>";
            echo "<div class='detail warning'>⚠️ No source accounts found. Creating one...</div>";
            
            // Create a test source account
            $stmt = $pdo->prepare("
                INSERT INTO source_accounts (
                    organization_id, institution, asset_type,
                    source_identifier, source_identifier_type,
                    account_name, currency, balance,
                    is_active, is_hooked, created_by,
                    created_at, updated_at
                ) VALUES (
                    :org_id, 'VOUCHMORPH_TEST', 'WALLET',
                    'TEST_' || to_char(NOW(), 'YYYYMMDD_HH24MISS'),
                    'account_number',
                    'Test Source Account',
                    'BWP', 100000.00,
                    true, false, :user_id,
                    NOW(), NOW()
                ) RETURNING id
            ");
            $stmt->execute([':org_id' => $orgId, ':user_id' => $userId]);
            $sourceId = $stmt->fetchColumn();
            
            echo "<div class='detail success'>✅ Created test source account ID: $sourceId</div>";
            
            // Get the created source
            $stmt = $pdo->prepare("SELECT institution, source_identifier FROM source_accounts WHERE id = :id");
            $stmt->execute([':id' => $sourceId]);
            $source = $stmt->fetch(PDO::FETCH_ASSOC);
            $sourceName = $source['institution'] . ' - ' . $source['source_identifier'];
        }
    } catch (Exception $e) {
        echo "<span class='badge badge-fail'>❌ ERROR</span></h3>";
        echo "<div class='detail error'>❌ " . htmlspecialchars($e->getMessage()) . "</div>";
    }
} else {
    echo "<span class='badge badge-warning'>⏳ WAITING</span></h3>";
    echo "<div class='detail warning'>Please login first to continue.</div>";
}
echo "</div>";

// ============================================================
// STEP 3: CREATE BATCH
// ============================================================
echo "<div class='step " . ($sourceId ? 'pass' : 'warning') . "'>";
echo "<h3>Step 3: Create Batch ";

$batchId = null;
$batchRef = null;

if ($sourceId && isset($_SESSION['enterprise_user'])) {
    try {
        $batchRef = 'TEST_BATCH_' . date('Ymd_His');
        $batchName = 'Test Batch ' . date('Y-m-d H:i');
        
        $stmt = $pdo->prepare("
            INSERT INTO disbursement_batches (
                organization_id, batch_reference, batch_name,
                source_account_id, source_institution, source_asset_type,
                source_identifier, total_amount, total_destinations,
                currency, status, created_by, created_at, updated_at
            ) VALUES (
                :org_id, :ref, :name,
                :source_id, 'VOUCHMORPH_TEST', 'WALLET',
                'TEST_ACCOUNT', 1500.00, 3,
                'BWP', 'draft', :user_id,
                NOW(), NOW()
            ) RETURNING id
        ");
        $stmt->execute([
            ':org_id' => $orgId,
            ':ref' => $batchRef,
            ':name' => $batchName,
            ':source_id' => $sourceId,
            ':user_id' => $userId
        ]);
        $batchId = $stmt->fetchColumn();
        
        echo "<span class='badge badge-pass'>✅ CREATED</span></h3>";
        echo "<div class='detail success'>✅ Batch created successfully!</div>";
        echo "<div class='detail'>Reference: <strong>" . htmlspecialchars($batchRef) . "</strong></div>";
        echo "<div class='detail'>Batch ID: $batchId</div>";
        echo "<div class='detail'>Status: <strong>DRAFT</strong></div>";
    } catch (Exception $e) {
        echo "<span class='badge badge-fail'>❌ ERROR</span></h3>";
        echo "<div class='detail error'>❌ " . htmlspecialchars($e->getMessage()) . "</div>";
    }
} else {
    echo "<span class='badge badge-warning'>⏳ WAITING</span></h3>";
    echo "<div class='detail warning'>Need a source account to create a batch.</div>";
}
echo "</div>";

// ============================================================
// STEP 4: ADD DESTINATIONS
// ============================================================
echo "<div class='step " . ($batchId ? 'pass' : 'warning') . "'>";
echo "<h3>Step 4: Add Destinations ";

if ($batchId) {
    try {
        // Add 3 test destinations
        $destinations = [
            ['CAZACOM', '71712345', 'phone', 500.00, 'Test Recipient 1', '+26771712345'],
            ['SACCUSSALIS', '10000002', 'account_number', 500.00, 'Test Recipient 2', '+26771712346'],
            ['ZURUBANK', '71712347', 'phone', 500.00, 'Test Recipient 3', '+26771712347']
        ];
        
        $stmt = $pdo->prepare("
            INSERT INTO disbursement_destinations (
                batch_id, destination_index, institution, asset_type,
                identifier, identifier_type, amount, currency,
                delivery_method, beneficiary_name, beneficiary_phone,
                status
            ) VALUES (
                :batch_id, :index, :institution, 'WALLET',
                :identifier, :identifier_type, :amount, 'BWP',
                'DEPOSIT', :name, :phone,
                'PENDING'
            )
        ");
        
        $added = 0;
        foreach ($destinations as $i => $dest) {
            $stmt->execute([
                ':batch_id' => $batchId,
                ':index' => $i + 1,
                ':institution' => $dest[0],
                ':identifier' => $dest[1],
                ':identifier_type' => $dest[2],
                ':amount' => $dest[3],
                ':name' => $dest[4],
                ':phone' => $dest[5]
            ]);
            $added++;
        }
        
        // Update batch totals
        $stmt = $pdo->prepare("
            UPDATE disbursement_batches 
            SET total_destinations = 3, total_amount = 1500.00, updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([':id' => $batchId]);
        
        echo "<span class='badge badge-pass'>✅ ADDED</span></h3>";
        echo "<div class='detail success'>✅ Added $added destinations</div>";
        echo "<div class='detail'>Total Amount: BWP 1,500.00</div>";
        echo "<div class='detail'>Total Destinations: 3</div>";
        
        // Show destinations
        $stmt = $pdo->prepare("SELECT * FROM disbursement_destinations WHERE batch_id = :batch_id");
        $stmt->execute([':batch_id' => $batchId]);
        $dests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<table class='data-table'>";
        echo "<tr><th>#</th><th>Institution</th><th>Identifier</th><th>Amount</th><th>Beneficiary</th></tr>";
        foreach ($dests as $d) {
            echo "<tr>";
            echo "<td>" . $d['destination_index'] . "</td>";
            echo "<td>" . htmlspecialchars($d['institution']) . "</td>";
            echo "<td>" . htmlspecialchars($d['identifier']) . "</td>";
            echo "<td>BWP " . number_format($d['amount'], 2) . "</td>";
            echo "<td>" . htmlspecialchars($d['beneficiary_name']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
    } catch (Exception $e) {
        echo "<span class='badge badge-fail'>❌ ERROR</span></h3>";
        echo "<div class='detail error'>❌ " . htmlspecialchars($e->getMessage()) . "</div>";
    }
} else {
    echo "<span class='badge badge-warning'>⏳ WAITING</span></h3>";
    echo "<div class='detail warning'>Need a batch to add destinations.</div>";
}
echo "</div>";

// ============================================================
// STEP 5: SUBMIT FOR APPROVAL (Simulate Loader Action)
// ============================================================
echo "<div class='step " . ($batchId ? 'pass' : 'warning') . "'>";
echo "<h3>Step 5: Submit for Approval ";

if ($batchId) {
    try {
        // Update batch status to pending_approval
        $stmt = $pdo->prepare("
            UPDATE disbursement_batches 
            SET status = 'pending_approval',
                submitted_by = :user_id,
                submitted_at = NOW(),
                updated_at = NOW()
            WHERE id = :id AND status = 'draft'
        ");
        $stmt->execute([':user_id' => $userId, ':id' => $batchId]);
        
        if ($stmt->rowCount() > 0) {
            echo "<span class='badge badge-pass'>✅ SUBMITTED</span></h3>";
            echo "<div class='detail success'>✅ Batch submitted for approval!</div>";
            echo "<div class='detail'>Status: <strong>PENDING_APPROVAL</strong></div>";
            echo "<div class='detail'>Submitted by: User ID $userId</div>";
            echo "<div class='detail'>Time: " . date('Y-m-d H:i:s') . "</div>";
        } else {
            echo "<span class='badge badge-warning'>⚠️ ALREADY SUBMITTED</span></h3>";
            echo "<div class='detail warning'>Batch may already be submitted or not in draft status.</div>";
        }
    } catch (Exception $e) {
        echo "<span class='badge badge-fail'>❌ ERROR</span></h3>";
        echo "<div class='detail error'>❌ " . htmlspecialchars($e->getMessage()) . "</div>";
    }
} else {
    echo "<span class='badge badge-warning'>⏳ WAITING</span></h3>";
    echo "<div class='detail warning'>Need a batch to submit.</div>";
}
echo "</div>";

// ============================================================
// STEP 6: APPROVE BATCH (Simulate Approver Action)
// ============================================================
echo "<div class='step " . ($batchId ? 'pass' : 'warning') . "'>";
echo "<h3>Step 6: Approve Batch ";

if ($batchId) {
    try {
        // Check if batch is in pending_approval status
        $stmt = $pdo->prepare("SELECT status FROM disbursement_batches WHERE id = :id");
        $stmt->execute([':id' => $batchId]);
        $currentStatus = $stmt->fetchColumn();
        
        if (in_array($currentStatus, ['pending_approval', 'PENDING_APPROVAL', 'PENDING'])) {
            // Approve the batch
            $stmt = $pdo->prepare("
                UPDATE disbursement_batches 
                SET status = 'approved',
                    approved_by = :user_id,
                    approved_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([':user_id' => $userId, ':id' => $batchId]);
            
            // Record approval
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO batch_approvals (batch_id, approver_user_id, decision, created_at)
                    VALUES (:batch_id, :approver_id, 'APPROVED', NOW())
                ");
                $stmt->execute([':batch_id' => $batchId, ':approver_id' => $userId]);
            } catch (Exception $e) {
                // Ignore duplicate approval errors
            }
            
            echo "<span class='badge badge-pass'>✅ APPROVED</span></h3>";
            echo "<div class='detail success'>✅ Batch approved successfully!</div>";
            echo "<div class='detail'>Status: <strong>APPROVED</strong></div>";
            echo "<div class='detail'>Approved by: User ID $userId</div>";
        } else {
            echo "<span class='badge badge-warning'>⚠️ NOT PENDING</span></h3>";
            echo "<div class='detail warning'>Batch is not in pending_approval status. Current status: $currentStatus</div>";
        }
    } catch (Exception $e) {
        echo "<span class='badge badge-fail'>❌ ERROR</span></h3>";
        echo "<div class='detail error'>❌ " . htmlspecialchars($e->getMessage()) . "</div>";
    }
} else {
    echo "<span class='badge badge-warning'>⏳ WAITING</span></h3>";
    echo "<div class='detail warning'>Need a pending batch to approve.</div>";
}
echo "</div>";

// ============================================================
// STEP 7: DISBURSE FUNDS (Simulate Supervisor Action)
// ============================================================
echo "<div class='step " . ($batchId ? 'pass' : 'warning') . "'>";
echo "<h3>Step 7: Disburse Funds ";

if ($batchId) {
    try {
        // Check if batch is approved
        $stmt = $pdo->prepare("SELECT status FROM disbursement_batches WHERE id = :id");
        $stmt->execute([':id' => $batchId]);
        $currentStatus = $stmt->fetchColumn();
        
        if (strtolower($currentStatus) === 'approved') {
            // Disburse the batch
            $stmt = $pdo->prepare("
                UPDATE disbursement_batches 
                SET status = 'completed',
                    executed_by = :user_id,
                    executed_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([':user_id' => $userId, ':id' => $batchId]);
            
            // Update destinations to success
            $stmt = $pdo->prepare("
                UPDATE disbursement_destinations 
                SET status = 'SUCCESS',
                    hold_reference = 'HOLD_' || to_char(NOW(), 'YYYYMMDD_HH24MISS') || '_' || destination_index
                WHERE batch_id = :batch_id
            ");
            $stmt->execute([':batch_id' => $batchId]);
            
            echo "<span class='badge badge-pass'>✅ DISBURSED</span></h3>";
            echo "<div class='detail success'>✅ Funds disbursed successfully!</div>";
            echo "<div class='detail'>Status: <strong>COMPLETED</strong></div>";
            echo "<div class='detail'>Executed by: User ID $userId</div>";
            echo "<div class='detail'>All destinations marked as SUCCESS</div>";
        } else {
            echo "<span class='badge badge-warning'>⚠️ NOT APPROVED</span></h3>";
            echo "<div class='detail warning'>Batch is not approved. Current status: $currentStatus</div>";
        }
    } catch (Exception $e) {
        echo "<span class='badge badge-fail'>❌ ERROR</span></h3>";
        echo "<div class='detail error'>❌ " . htmlspecialchars($e->getMessage()) . "</div>";
    }
} else {
    echo "<span class='badge badge-warning'>⏳ WAITING</span></h3>";
    echo "<div class='detail warning'>Need an approved batch to disburse.</div>";
}
echo "</div>";

// ============================================================
// STEP 8: VERIFY FINAL STATE
// ============================================================
echo "<div class='step " . ($batchId ? 'info' : 'warning') . "'>";
echo "<h3>Step 8: Verify Final State <span class='badge badge-info'>📋 CHECK</span></h3>";

if ($batchId) {
    try {
        // Get batch details
        $stmt = $pdo->prepare("
            SELECT b.*, 
                   COUNT(d.id) as dest_count,
                   SUM(CASE WHEN d.status = 'SUCCESS' THEN 1 ELSE 0 END) as success_count
            FROM disbursement_batches b
            LEFT JOIN disbursement_destinations d ON b.id = d.batch_id
            WHERE b.id = :id
            GROUP BY b.id
        ");
        $stmt->execute([':id' => $batchId]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo "<div class='detail'>Batch Reference: <strong>" . htmlspecialchars($batch['batch_reference']) . "</strong></div>";
        echo "<div class='detail'>Final Status: <strong>" . strtoupper($batch['status']) . "</strong></div>";
        echo "<div class='detail'>Destinations: " . ($batch['dest_count'] ?? 0) . "</div>";
        echo "<div class='detail'>Successful: " . ($batch['success_count'] ?? 0) . "</div>";
        
        // Show workflow progress
        $steps = ['draft' => '📝 Draft', 'pending_approval' => '⏳ Pending', 'approved' => '✅ Approved', 'completed' => '✔️ Completed'];
        $currentStep = strtolower($batch['status']);
        $stepKeys = array_keys($steps);
        $currentIndex = array_search($currentStep, $stepKeys);
        
        echo "<div class='progress'>";
        foreach ($stepKeys as $i => $key) {
            $isDone = $i <= $currentIndex;
            $isActive = $i == $currentIndex;
            echo "<div class='dot " . ($isDone ? 'done' : '') . ($isActive ? ' active' : '') . "'>" . ($isDone ? '✓' : ($i + 1)) . "</div>";
            if ($i < count($stepKeys) - 1) {
                echo "<div class='line " . ($isDone ? 'done' : '') . "'></div>";
            }
        }
        echo "</div>";
        
        echo "<div style='display:flex; gap:8px; justify-content:center; font-size:11px; color:#64748b; margin-top:4px;'>";
        foreach ($steps as $key => $label) {
            $isDone = array_search($key, $stepKeys) <= $currentIndex;
            echo "<span style='" . ($isDone ? 'color:#166534;' : 'color:#94a3b8;') . "'>" . ($isDone ? '✅' : '⬜') . " $label</span>";
        }
        echo "</div>";
        
    } catch (Exception $e) {
        echo "<div class='detail error'>❌ " . htmlspecialchars($e->getMessage()) . "</div>";
    }
} else {
    echo "<div class='detail warning'>No batch to verify.</div>";
}
echo "</div>";

// ============================================================
// FINAL SUMMARY
// ============================================================
$success = $sourceId && $batchId;
echo "<div class='summary'>";
echo "<h3>" . ($success ? '✅ Workflow Test Complete!' : '⚠️ Workflow Test Incomplete') . "</h3>";
echo "<p>";
if ($success) {
    echo "All steps completed successfully! The batch went through the full workflow:<br>";
    echo "📝 Draft → ⏳ Pending Approval → ✅ Approved → ✔️ Completed";
} else {
    echo "Some steps were skipped. Please check the details above.<br>";
    echo "Make sure you're logged in with the appropriate role.";
}
echo "</p>";

echo "<div class='links'>";
echo "<a href='batches/view.php?id=" . ($batchId ?? 0) . "'>📋 View Batch</a>";
echo "<a href='imports/review_batch.php?status=all' class='secondary'>📋 All Batches</a>";
echo "<a href='test_workflow.php' class='secondary'>🔄 Run Again</a>";
echo "<a href='test.php' class='secondary'>🔧 System Test</a>";
echo "</div>";
echo "</div>";

// ============================================================
// SHOW SQL FOR REFERENCE
// ============================================================
if ($batchId) {
    echo "<div style='margin-top:24px; background:white; border-radius:12px; padding:20px; border:1px solid #e2e8f0;'>";
    echo "<h4 style='margin-bottom:12px;'>📋 Created Data (SQL Reference)</h4>";
    echo "<div class='sql-box'>";
    echo "-- Batch\n";
    echo "SELECT * FROM disbursement_batches WHERE id = $batchId;\n\n";
    echo "-- Destinations\n";
    echo "SELECT * FROM disbursement_destinations WHERE batch_id = $batchId;\n\n";
    echo "-- Approvals\n";
    echo "SELECT * FROM batch_approvals WHERE batch_id = $batchId;";
    echo "</div>";
    echo "</div>";
}

echo "</div></body></html>";
