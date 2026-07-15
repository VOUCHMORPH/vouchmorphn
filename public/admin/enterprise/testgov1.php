<?php
/**
 * check_user_org.php - Debug user organization and batch visibility
 */

require_once 'auth.php';
$user = requireEnterpriseAuth();
$pdo = getDBConnection();

echo "<!DOCTYPE html>
<html>
<head>
    <title>Debug User Organization</title>
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
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { padding: 6px 10px; text-align: left; border-bottom: 1px solid #334155; }
        th { background: #1e293b; color: #94a3b8; }
    </style>
</head>
<body>
<h1>🔍 Debug: User Organization & Batches</h1>";

// ============================================================
// 1. Current User Info
// ============================================================
echo "<div class='step'>";
echo "<h2>1. Current User</h2>";

$userId = $user['user_id'] ?? $user['id'] ?? null;
$orgId = getOrganizationId();
$userRole = $user['role'] ?? 'viewer';

echo "User ID: <strong>" . ($userId ?? 'NULL') . "</strong><br>";
echo "Role: <strong>" . ($userRole ?? 'NULL') . "</strong><br>";
echo "Organization ID from session: <strong>" . ($orgId ?? 'NULL') . "</strong><br>";
echo "Full Name: " . ($user['full_name'] ?? 'NULL') . "<br>";
echo "Email: " . ($user['email'] ?? 'NULL') . "<br>";

// Check if user exists in organization_users
$stmt = $pdo->prepare("
    SELECT ou.*, o.name as org_name
    FROM organization_users ou
    JOIN organizations o ON ou.organization_id = o.id
    WHERE ou.user_id = :user_id
");
$stmt->execute([':user_id' => $userId]);
$orgUser = $stmt->fetch(PDO::FETCH_ASSOC);

if ($orgUser) {
    echo "<span class='pass'>✅ User found in organization_users</span><br>";
    echo "Organization ID: " . $orgUser['organization_id'] . "<br>";
    echo "Organization Name: " . $orgUser['org_name'] . "<br>";
    echo "Role in DB: " . $orgUser['role'] . "<br>";
    echo "Is Active: " . ($orgUser['is_active'] ? '✅ Yes' : '❌ No') . "<br>";
} else {
    echo "<span class='fail'>❌ User NOT found in organization_users!</span><br>";
    echo "This is why you're not seeing batches - your user isn't properly linked to an organization.";
}
echo "</div>";

// ============================================================
// 2. All Batches in Database
// ============================================================
echo "<div class='step'>";
echo "<h2>2. All Batches in Database</h2>";

$stmt = $pdo->query("
    SELECT id, batch_reference, status, created_by, organization_id
    FROM disbursement_batches
    ORDER BY id
");
$allBatches = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($allBatches)) {
    echo "<span class='warn'>⚠️ No batches found in database</span><br>";
} else {
    echo "<span class='pass'>✅ Found " . count($allBatches) . " batches</span><br>";
    echo "<table>";
    echo "<tr><th>ID</th><th>Reference</th><th>Status</th><th>Created By</th><th>Org ID</th></tr>";
    foreach ($allBatches as $batch) {
        echo "<tr>";
        echo "<td>" . $batch['id'] . "</td>";
        echo "<td>" . $batch['batch_reference'] . "</td>";
        echo "<td>" . $batch['status'] . "</td>";
        echo "<td>" . ($batch['created_by'] ?? 'NULL') . "</td>";
        echo "<td>" . ($batch['organization_id'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}
echo "</div>";

// ============================================================
// 3. Batches Visible to This User (Using Dashboard Query)
// ============================================================
echo "<div class='step'>";
echo "<h2>3. Batches Visible to This User (Dashboard Query)</h2>";

$orgId = getOrganizationId();
$userId = $user['user_id'] ?? $user['id'] ?? null;
$userRole = $user['role'] ?? 'viewer';

echo "Using: org_id=$orgId, user_id=$userId, role=$userRole<br><br>";

// Simulate the dashboard query
$statusFilter = "";
$statusParams = [':org_id' => $orgId];

if ($userRole === 'owner') {
    // Owners should see ALL batches
    $statusFilter = "AND 1=1";
    echo "<span class='info'>Role is OWNER - should see ALL batches</span><br>";
} elseif (in_array($userRole, ['auditor', 'viewer', 'it_support'])) {
    $statusFilter = "AND status IN ('completed', 'executed', 'COMPLETED', 'EXECUTED')";
} elseif (in_array($userRole, ['approver', 'senior_approver'])) {
    $statusFilter = "AND status IN ('pending', 'pending_approval', 'approved', 'draft', 'PENDING', 'PENDING_APPROVAL', 'APPROVED')";
} elseif ($userRole === 'supervisor') {
    $statusFilter = "AND status IN ('approved', 'completed', 'executed', 'APPROVED', 'COMPLETED', 'EXECUTED')";
} elseif (in_array($userRole, ['program_officer', 'department_head'])) {
    $statusFilter = "AND (created_by = :user_id OR status IN ('pending', 'pending_approval', 'approved', 'draft'))";
    $statusParams[':user_id'] = $userId;
}

// Build the full query
$sql = "
    SELECT id, batch_reference, status, created_by, organization_id
    FROM disbursement_batches 
    WHERE organization_id = :org_id $statusFilter
    ORDER BY created_at DESC 
    LIMIT 15
";

echo "SQL: <br><pre style='background:#0f172a; padding:10px; border-radius:4px; font-size:11px;'>" . htmlspecialchars($sql) . "</pre><br>";
echo "Params: <pre style='background:#0f172a; padding:10px; border-radius:4px; font-size:11px;'>" . print_r($statusParams, true) . "</pre><br>";

$stmt = $pdo->prepare($sql);
$stmt->execute($statusParams);
$visibleBatches = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($visibleBatches)) {
    echo "<span class='fail'>❌ No batches visible to this user!</span><br>";
    
    // Check if there are any batches with this org_id
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM disbursement_batches WHERE organization_id = :org_id");
    $stmt->execute([':org_id' => $orgId]);
    $count = $stmt->fetchColumn();
    
    if ($count == 0) {
        echo "<span class='warn'>⚠️ No batches found with organization_id = $orgId</span><br>";
        echo "Batches exist but with different organization_id.<br>";
        
        // Show what organization_ids exist
        $stmt = $pdo->query("SELECT DISTINCT organization_id FROM disbursement_batches");
        $orgIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "Existing organization_ids in batches: " . implode(', ', $orgIds) . "<br>";
    } else {
        echo "<span class='info'>ℹ️ Found $count batches with organization_id = $orgId, but they're filtered out by role/status</span><br>";
    }
} else {
    echo "<span class='pass'>✅ Found " . count($visibleBatches) . " visible batches</span><br>";
    echo "<table>";
    echo "<tr><th>ID</th><th>Reference</th><th>Status</th><th>Created By</th></tr>";
    foreach ($visibleBatches as $batch) {
        echo "<tr>";
        echo "<td>" . $batch['id'] . "</td>";
        echo "<td>" . $batch['batch_reference'] . "</td>";
        echo "<td>" . $batch['status'] . "</td>";
        echo "<td>" . ($batch['created_by'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}
echo "</div>";

// ============================================================
// 4. Fix Suggestion
// ============================================================
echo "<div class='step'>";
echo "<h2>4. Fix Suggestion</h2>";

// Check what's wrong
$stmt = $pdo->prepare("SELECT COUNT(*) FROM disbursement_batches WHERE organization_id = :org_id");
$stmt->execute([':org_id' => $orgId]);
$orgBatchCount = $stmt->fetchColumn();

if ($orgBatchCount == 0) {
    echo "<span class='warn'>⚠️ Your batches have a DIFFERENT organization_id than your user.</span><br>";
    echo "<span class='info'>Run this SQL to fix:</span><br>";
    echo "<pre style='background:#0f172a; padding:10px; border-radius:4px; font-size:11px;'>
-- Check what org_id your batches have
SELECT DISTINCT organization_id FROM disbursement_batches;

-- Update all batches to match your organization (replace 1 with your actual org_id)
UPDATE disbursement_batches 
SET organization_id = 1
WHERE organization_id IS NULL OR organization_id != 1;

-- Also update your user's organization if needed
UPDATE organization_users 
SET organization_id = 1
WHERE user_id = $userId;
</pre>";
} elseif ($userRole === 'owner' && empty($visibleBatches)) {
    echo "<span class='fail'>❌ Owner role but no batches visible. This is a bug.</span><br>";
    echo "<span class='info'>Check if the owner filter is working correctly in index.php.</span><br>";
    echo "The filter for owners should be: <code>AND 1=1</code> (show all)<br>";
} else {
    echo "<span class='pass'>✅ Everything looks correct. Try refreshing the page.</span><br>";
}

echo "</div>";

// ============================================================
// 5. Quick Fix Button
// ============================================================
echo "<div class='box' style='border: 2px solid #4ade80; margin-top: 20px;'>";
echo "<h2>🛠️ Quick Fix</h2>";

// Get actual org_id from batches
$stmt = $pdo->query("SELECT DISTINCT organization_id FROM disbursement_batches LIMIT 1");
$batchOrgId = $stmt->fetchColumn();

if ($batchOrgId && $orgId != $batchOrgId) {
    echo "<span class='warn'>⚠️ Your user's org_id ($orgId) doesn't match batch org_id ($batchOrgId)</span><br>";
    echo "<form method='POST' action=''>";
    echo "<input type='hidden' name='fix_org' value='1'>";
    echo "<button type='submit' class='btn' style='background:#fbbf24; color:#0f172a; padding:8px 16px; border:none; border-radius:4px; cursor:pointer; margin-top:8px;'>🔧 Fix: Update User Organization to $batchOrgId</button>";
    echo "</form>";
}

// Handle fix
if (isset($_POST['fix_org'])) {
    $stmt = $pdo->prepare("UPDATE organization_users SET organization_id = :org_id WHERE user_id = :user_id");
    $stmt->execute([':org_id' => $batchOrgId, ':user_id' => $userId]);
    
    // Also update session
    $_SESSION['enterprise_user']['organization_id'] = $batchOrgId;
    
    echo "<span class='pass'>✅ Organization updated! Refreshing...</span><br>";
    echo "<script>setTimeout(function(){ window.location.href = 'index.php'; }, 1500);</script>";
}

echo "<div style='margin-top:12px; display:flex; gap:12px; flex-wrap:wrap;'>";
echo "<a href='check_user_org.php' class='btn' style='background:#60a5fa;'>🔄 Re-run</a>";
echo "<a href='index.php' class='btn'>📊 Dashboard</a>";
echo "<a href='logout.php' class='btn' style='background:#f87171;'>🚪 Logout</a>";
echo "</div>";
echo "</div>";

echo "</body></html>";
