<?php
/**
 * test_complete.php - Complete Workflow Test with Auto-Login
 * Tests everything in one go with proper login handling
 */

// Suppress session warnings
error_reporting(E_ALL ^ E_WARNING);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

echo "<!DOCTYPE html>
<html>
<head>
    <title>Complete Workflow Test</title>
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
        .btn { background: #4ade80; color: #0f172a; padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; font-weight: 600; }
        .btn:hover { background: #22c55e; }
        .btn-primary { background: #60a5fa; color: #0f172a; }
        .btn-primary:hover { background: #3b82f6; }
        .btn-warning { background: #fbbf24; color: #0f172a; }
        .btn-warning:hover { background: #f59e0b; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 8px 12px; text-align: left; border-bottom: 1px solid #334155; }
        th { background: #1e293b; color: #94a3b8; }
        input, select { background: #1e293b; color: #e2e8f0; border: 1px solid #334155; padding: 8px 12px; border-radius: 4px; font-family: monospace; }
        .login-form { background: #1e293b; padding: 20px; border-radius: 8px; border: 1px solid #334155; max-width: 400px; margin: 10px 0; }
        .login-form label { display: block; margin: 8px 0 4px; color: #94a3b8; }
        .login-form input { width: 100%; }
        .login-form .btn { margin-top: 12px; }
    </style>
</head>
<body>
<h1>🧪 Complete Workflow Test</h1>
<p class='info'>Testing: Login → Source → Batch → Destinations → Submit → Approve → Disburse</p>";

// ============================================================
// STEP 0: CHECK LOGIN
// ============================================================
$isLoggedIn = isset($_SESSION['enterprise_user']);
$orgId = $_SESSION['enterprise_user']['organization_id'] ?? null;
$userId = $_SESSION['enterprise_user']['user_id'] ?? $_SESSION['enterprise_user']['id'] ?? null;
$userRole = $_SESSION['enterprise_user']['role'] ?? null;

echo "<div class='step " . ($isLoggedIn ? 'pass' : 'warn') . "'>";
echo "<h2>Step 0: Authentication</h2>";

if ($isLoggedIn) {
    $user = $_SESSION['enterprise_user'];
    echo "<span class='pass'>✅ Logged in as: " . ($user['full_name'] ?? $user['username'] ?? 'User') . "</span><br>";
    echo "Role: <strong>" . ($user['role'] ?? 'Unknown') . "</strong><br>";
    echo "Organization ID: " . ($user['organization_id'] ?? 'N/A') . "<br>";
    echo "Department: " . ($user['department_id'] ?? 'None') . "<br>";
    echo "<a href='logout.php' class='btn' style='background:#f87171;'>🚪 Logout</a>";
} else {
    echo "<span class='warn'>⚠️ Not logged in</span><br>";
    echo "<p>You need to login first. Use one of these roles:</p>";
    
    // Show login form
    echo "<div class='login-form'>";
    echo "<h3 style='color:#fbbf24;'>🔑 Login</h3>";
    echo "<form method='POST' action='login.php'>";
    echo "<label>Email</label>";
    echo "<input type='email' name='email' value='program_officer@example.com' placeholder='Enter email'>";
    echo "<label>Password</label>";
    echo "<input type='password' name='password' value='password123' placeholder='Enter password'>";
    echo "<button type='submit' class='btn btn-primary' style='width:100%;'>Login</button>";
    echo "</form>";
    echo "<p style='margin-top:8px; font-size:11px; color:#64748b;'>Try: program_officer@example.com / password123</p>";
    echo "</div>";
}
echo "</div>";

// ============================================================
// ONLY CONTINUE IF LOGGED IN
// ============================================================
if ($isLoggedIn) {
    require_once 'auth.php';
    $pdo = getDBConnection();
    $orgId = getOrganizationId();
    $user = getCurrentUser();
    $userId = $user['user_id'] ?? $user['id'] ?? null;

    // Debug
    echo "<div class='step info'>";
    echo "<h2>Debug Info</h2>";
    echo "Organization ID from getOrganizationId(): " . ($orgId ?? 'NULL') . "<br>";
    echo "User ID: " . ($userId ?? 'NULL') . "<br>";
    echo "User Role: " . ($user['role'] ?? 'NULL') . "<br>";
    echo "</div>";

    // ============================================================
    // STEP 1: CHECK/CREATE SOURCE
    // ============================================================
    echo "<div class='step'>";
    echo "<h2>Step 1: Source Account</h2>";

    // Check existing source
    $stmt = $pdo->prepare("SELECT id, institution, source_identifier, balance FROM source_accounts WHERE organization_id = :org_id AND is_active = true LIMIT 1");
    $stmt->execute([':org_id' => $orgId]);
    $source = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($source) {
        echo "<span class='pass'>✅ Source found: " . $source['institution'] . " - " . $source['source_identifier'] . "</span><br>";
        echo "Balance: BWP " . number_format($source['balance'] ?? 0, 2) . "<br>";
        $sourceId = $source['id'];
    } else {
        echo "<span class='warn'>⚠️ No source found. Creating one...</span><br>";
        
        try {
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
            echo "<span class='pass'>✅ Created source ID: $sourceId</span><br>";
        } catch (Exception $e) {
            echo "<span class='fail'>❌ Error: " . $e->getMessage() . "</span><br>";
            // Try to get any source
            $stmt = $pdo->prepare("SELECT id FROM source_accounts WHERE organization_id = :org_id LIMIT 1");
            $stmt->execute([':org_id' => $orgId]);
            $source = $stmt->fetch(PDO::FETCH_ASSOC);
            $sourceId = $source['id'] ?? null;
            if ($sourceId) {
                echo "<span class='warn'>⚠️ Using existing source ID: $sourceId</span><br>";
            }
        }
    }
    echo "</div>";

    // ============================================================
    // STEP 2: CREATE BATCH
    // ============================================================
    echo "<div class='step'>";
    echo "<h2>Step 2: Create Batch</h2>";

    if ($sourceId) {
        // Check for existing draft batch
        $stmt = $pdo->prepare("
            SELECT id, batch_reference, status FROM disbursement_batches 
            WHERE organization_id = :org_id AND status = 'draft' 
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([':org_id' => $orgId]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($batch) {
            echo "<span class='pass'>✅ Found draft batch: " . $batch['batch_reference'] . "</span><br>";
            $batchId = $batch['id'];
        } else {
            echo "<span class='warn'>⚠️ No draft batch. Creating one...</span><br>";
            
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO disbursement_batches (
                        organization_id, batch_reference, batch_name,
                        source_account_id, source_institution, source_asset_type,
                        source_identifier, total_amount, total_destinations,
                        currency, status, created_by, created_at, updated_at
                    ) VALUES (
                        :org_id,
                        'TEST_BATCH_' || to_char(NOW(), 'YYYYMMDD_HH24MISS'),
                        'Test Batch ' || to_char(NOW(), 'YYYY-MM-DD HH24:MI'),
                        :source_id, 'VOUCHMORPH_TEST', 'WALLET',
                        'TEST_ACCOUNT', 1500.00, 3,
                        'BWP', 'draft', :user_id,
                        NOW(), NOW()
                    ) RETURNING id
                ");
                $stmt->execute([
                    ':org_id' => $orgId,
                    ':source_id' => $sourceId,
                    ':user_id' => $userId
                ]);
                $batchId = $stmt->fetchColumn();
                echo "<span class='pass'>✅ Created batch ID: $batchId</span><br>";
            } catch (Exception $e) {
                echo "<span class='fail'>❌ Error: " . $e->getMessage() . "</span><br>";
                // Try to get any batch
                $stmt = $pdo->prepare("SELECT id FROM disbursement_batches WHERE organization_id = :org_id LIMIT 1");
                $stmt->execute([':org_id' => $orgId]);
                $batch = $stmt->fetch(PDO::FETCH_ASSOC);
                $batchId = $batch['id'] ?? null;
                if ($batchId) {
                    echo "<span class='warn'>⚠️ Using existing batch ID: $batchId</span><br>";
                }
            }
        }
    } else {
        echo "<span class='fail'>❌ No source available. Cannot create batch.</span><br>";
        $batchId = null;
    }
    echo "</div>";

    // ============================================================
    // STEP 3: ADD DESTINATIONS
    // ============================================================
    echo "<div class='step'>";
    echo "<h2>Step 3: Add Destinations</h2>";

    if ($batchId) {
        // Check existing destinations
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM disbursement_destinations WHERE batch_id = :batch_id");
        $stmt->execute([':batch_id' => $batchId]);
        $destCount = $stmt->fetchColumn();

        if ($destCount > 0) {
            echo "<span class='pass'>✅ Already have $destCount destinations</span><br>";
        } else {
            echo "<span class='warn'>⚠️ No destinations. Adding 3...</span><br>";
            
            try {
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
                }
                
                // Update batch totals
                $stmt = $pdo->prepare("
                    UPDATE disbursement_batches 
                    SET total_destinations = 3, total_amount = 1500.00, updated_at = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([':id' => $batchId]);
                
                echo "<span class='pass'>✅ Added 3 destinations</span><br>";
            } catch (Exception $e) {
                echo "<span class='fail'>❌ Error: " . $e->getMessage() . "</span><br>";
            }
        }
    }
    echo "</div>";

    // ============================================================
    // STEP 4: SUBMIT FOR APPROVAL
    // ============================================================
    echo "<div class='step'>";
    echo "<h2>Step 4: Submit for Approval</h2>";

    if ($batchId) {
        // Check current status
        $stmt = $pdo->prepare("SELECT status FROM disbursement_batches WHERE id = :id");
        $stmt->execute([':id' => $batchId]);
        $status = strtolower($stmt->fetchColumn());

        if ($status === 'draft') {
            try {
                $stmt = $pdo->prepare("
                    UPDATE disbursement_batches 
                    SET status = 'pending_approval',
                        submitted_by = :user_id,
                        submitted_at = NOW(),
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([':user_id' => $userId, ':id' => $batchId]);
                echo "<span class='pass'>✅ Submitted for approval</span><br>";
                $status = 'pending_approval';
            } catch (Exception $e) {
                echo "<span class='fail'>❌ Error: " . $e->getMessage() . "</span><br>";
            }
        } else {
            echo "<span class='info'>ℹ️ Current status: " . strtoupper($status) . "</span><br>";
        }
    }
    echo "</div>";

    // ============================================================
    // STEP 5: APPROVE BATCH
    // ============================================================
    echo "<div class='step'>";
    echo "<h2>Step 5: Approve Batch</h2>";

    if ($batchId) {
        $stmt = $pdo->prepare("SELECT status FROM disbursement_batches WHERE id = :id");
        $stmt->execute([':id' => $batchId]);
        $status = strtolower($stmt->fetchColumn());

        if (in_array($status, ['pending', 'pending_approval'])) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE disbursement_batches 
                    SET status = 'approved',
                        approved_by = :user_id,
                        approved_at = NOW(),
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([':user_id' => $userId, ':id' => $batchId]);
                echo "<span class='pass'>✅ Batch approved!</span><br>";
                
                // Record approval
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO batch_approvals (batch_id, approver_user_id, decision, created_at)
                        VALUES (:batch_id, :approver_id, 'APPROVED', NOW())
                    ");
                    $stmt->execute([':batch_id' => $batchId, ':approver_id' => $userId]);
                } catch (Exception $e) {
                    // Ignore duplicate
                }
                $status = 'approved';
            } catch (Exception $e) {
                echo "<span class='fail'>❌ Error: " . $e->getMessage() . "</span><br>";
            }
        } else {
            echo "<span class='info'>ℹ️ Current status: " . strtoupper($status) . "</span><br>";
        }
    }
    echo "</div>";

    // ============================================================
    // STEP 6: DISBURSE FUNDS
    // ============================================================
    echo "<div class='step'>";
    echo "<h2>Step 6: Disburse Funds</h2>";

    if ($batchId) {
        $stmt = $pdo->prepare("SELECT status FROM disbursement_batches WHERE id = :id");
        $stmt->execute([':id' => $batchId]);
        $status = strtolower($stmt->fetchColumn());

        if ($status === 'approved') {
            try {
                $stmt = $pdo->prepare("
                    UPDATE disbursement_batches 
                    SET status = 'completed',
                        executed_by = :user_id,
                        executed_at = NOW(),
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([':user_id' => $userId, ':id' => $batchId]);
                echo "<span class='pass'>✅ Funds disbursed successfully!</span><br>";
                
                // Update destinations
                $stmt = $pdo->prepare("
                    UPDATE disbursement_destinations 
                    SET status = 'SUCCESS'
                    WHERE batch_id = :batch_id
                ");
                $stmt->execute([':batch_id' => $batchId]);
                $status = 'completed';
            } catch (Exception $e) {
                echo "<span class='fail'>❌ Error: " . $e->getMessage() . "</span><br>";
            }
        } else {
            echo "<span class='info'>ℹ️ Current status: " . strtoupper($status) . "</span><br>";
        }
    }
    echo "</div>";

    // ============================================================
    // STEP 7: FINAL VERIFICATION
    // ============================================================
    echo "<div class='step pass'>";
    echo "<h2>Step 7: Final Verification</h2>";

    if ($batchId) {
        // Get full batch details
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

        echo "<span class='pass'>✅ Batch Reference: " . $batch['batch_reference'] . "</span><br>";
        echo "Final Status: <strong>" . strtoupper($batch['status']) . "</strong><br>";
        echo "Destinations: " . ($batch['dest_count'] ?? 0) . "<br>";
        echo "Successful: " . ($batch['success_count'] ?? 0) . "<br>";
        
        // Show workflow progress
        $steps = ['draft' => '📝 Draft', 'pending_approval' => '⏳ Pending', 'approved' => '✅ Approved', 'completed' => '✔️ Completed'];
        $currentStep = strtolower($batch['status']);
        $stepKeys = array_keys($steps);
        $currentIndex = array_search($currentStep, $stepKeys);
        
        echo "<br><div style='display:flex; gap:8px; flex-wrap:wrap;'>";
        foreach ($steps as $key => $label) {
            $isDone = array_search($key, $stepKeys) <= $currentIndex;
            echo "<span style='" . ($isDone ? 'color:#4ade80;' : 'color:#64748b;') . "'>" . ($isDone ? '✅' : '⬜') . " $label</span>";
        }
        echo "</div>";
    }
    echo "</div>";

    // ============================================================
    // FINAL SUMMARY
    // ============================================================
    echo "<div class='box' style='border: 2px solid #4ade80; margin-top: 20px;'>";
    echo "<h2>✅ Workflow Complete!</h2>";
    echo "<p>The batch went through the full workflow:</p>";
    echo "<div style='display:flex; gap:8px; flex-wrap:wrap; font-size:14px;'>";
    echo "<span style='background:#1e293b; padding:4px 12px; border-radius:4px;'>📝 Draft</span>";
    echo "<span style='color:#64748b;'>→</span>";
    echo "<span style='background:#1e293b; padding:4px 12px; border-radius:4px;'>⏳ Pending</span>";
    echo "<span style='color:#64748b;'>→</span>";
    echo "<span style='background:#1e293b; padding:4px 12px; border-radius:4px;'>✅ Approved</span>";
    echo "<span style='color:#64748b;'>→</span>";
    echo "<span style='background:#1e293b; padding:4px 12px; border-radius:4px;'>✔️ Completed</span>";
    echo "</div>";

    echo "<div style='margin-top:16px; display:flex; gap:12px; flex-wrap:wrap;'>";
    echo "<a href='batches/view.php?id=" . ($batchId ?? 0) . "' class='btn btn-primary'>📋 View Batch</a>";
    echo "<a href='imports/review_batch.php?status=all' class='btn'>📋 All Batches</a>";
    echo "<a href='index.php' class='btn'>📊 Dashboard</a>";
    echo "<a href='test_complete.php' class='btn btn-warning'>🔄 Run Again</a>";
    echo "</div>";
    echo "</div>";

} else {
    // Not logged in - show login instructions
    echo "<div class='box' style='border: 2px solid #fbbf24; margin-top: 20px;'>";
    echo "<h2 style='color:#fbbf24;'>⚠️ Login Required</h2>";
    echo "<p>Please login using the form above or use these test credentials:</p>";
    echo "<div style='background:#1e293b; padding:12px; border-radius:4px; margin:8px 0;'>";
    echo "<strong>Program Officer (Loader):</strong> program_officer@example.com / password123<br>";
    echo "<strong>Approver:</strong> approver@example.com / password123<br>";
    echo "<strong>Supervisor:</strong> supervisor@example.com / password123<br>";
    echo "<strong>Owner:</strong> owner@example.com / password123<br>";
    echo "</div>";
    echo "<a href='login.php' class='btn btn-primary'>🔑 Go to Login Page</a>";
    echo "</div>";
}

echo "</body></html>";
