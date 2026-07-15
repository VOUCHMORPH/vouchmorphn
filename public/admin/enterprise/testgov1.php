<?php
/**
 * test_queries.php - Test different query variations to find what works
 */

require_once 'auth.php';
$user = requireEnterpriseAuth();
$pdo = getDBConnection();
$orgId = getOrganizationId();
$userId = $user['user_id'] ?? $user['id'] ?? null;
$userRole = $user['role'] ?? 'viewer';

echo "<!DOCTYPE html>
<html>
<head>
    <title>Test Queries</title>
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
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { padding: 6px 10px; text-align: left; border-bottom: 1px solid #334155; }
        th { background: #1e293b; color: #94a3b8; }
        pre { background: #0f172a; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 11px; color: #94a3b8; }
        .query-result { margin: 8px 0; padding: 8px; border-radius: 4px; }
        .query-result.pass { background: #052e16; border-left: 3px solid #4ade80; }
        .query-result.fail { background: #2c0a0a; border-left: 3px solid #f87171; }
        .query-result.warn { background: #2c240a; border-left: 3px solid #fbbf24; }
    </style>
</head>
<body>
<h1>🔍 Test Query Variations</h1>";

// ============================================================
// User Info
// ============================================================
echo "<div class='step info'>";
echo "<h2>Current User</h2>";
echo "User ID: <strong>" . ($userId ?? 'NULL') . "</strong><br>";
echo "Role: <strong>" . ($userRole ?? 'NULL') . "</strong><br>";
echo "Organization ID: <strong>" . ($orgId ?? 'NULL') . "</strong><br>";
echo "</div>";

// ============================================================
// Test 1: Simple Query - No Filters
// ============================================================
echo "<div class='step'>";
echo "<h2>Test 1: No Filters (Should return ALL batches)</h2>";

$sql1 = "SELECT id, batch_reference, status, created_by, organization_id FROM disbursement_batches ORDER BY id";
$stmt = $pdo->query($sql1);
$result1 = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<div class='query-result " . (count($result1) > 0 ? 'pass' : 'fail') . "'>";
echo "Count: " . count($result1) . " batches<br>";
echo "<pre>" . htmlspecialchars($sql1) . "</pre>";
if (count($result1) > 0) {
    echo "<table>";
    echo "<tr><th>ID</th><th>Reference</th><th>Status</th><th>Created By</th><th>Org ID</th></tr>";
    foreach ($result1 as $row) {
        echo "<tr><td>{$row['id']}</td><td>{$row['batch_reference']}</td><td>{$row['status']}</td><td>{$row['created_by']}</td><td>{$row['organization_id']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<span class='fail'>❌ No results</span>";
}
echo "</div>";
echo "</div>";

// ============================================================
// Test 2: With organization_id filter
// ============================================================
echo "<div class='step'>";
echo "<h2>Test 2: With organization_id = $orgId</h2>";

$sql2 = "SELECT id, batch_reference, status, created_by, organization_id FROM disbursement_batches WHERE organization_id = :org_id ORDER BY id";
$stmt = $pdo->prepare($sql2);
$stmt->execute([':org_id' => $orgId]);
$result2 = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<div class='query-result " . (count($result2) > 0 ? 'pass' : 'fail') . "'>";
echo "Count: " . count($result2) . " batches<br>";
echo "<pre>" . htmlspecialchars($sql2) . "</pre>";
echo "Params: org_id = $orgId<br>";
if (count($result2) > 0) {
    echo "<table>";
    echo "<tr><th>ID</th><th>Reference</th><th>Status</th><th>Created By</th></tr>";
    foreach ($result2 as $row) {
        echo "<tr><td>{$row['id']}</td><td>{$row['batch_reference']}</td><td>{$row['status']}</td><td>{$row['created_by']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<span class='fail'>❌ No results</span>";
}
echo "</div>";
echo "</div>";

// ============================================================
// Test 3: Owner filter (AND 1=1)
// ============================================================
echo "<div class='step'>";
echo "<h2>Test 3: Owner Filter (AND 1=1 - should show ALL)</h2>";

$sql3 = "SELECT id, batch_reference, status, created_by, organization_id FROM disbursement_batches WHERE organization_id = :org_id AND 1=1 ORDER BY id";
$stmt = $pdo->prepare($sql3);
$stmt->execute([':org_id' => $orgId]);
$result3 = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<div class='query-result " . (count($result3) > 0 ? 'pass' : 'fail') . "'>";
echo "Count: " . count($result3) . " batches<br>";
echo "<pre>" . htmlspecialchars($sql3) . "</pre>";
echo "Params: org_id = $orgId<br>";
if (count($result3) > 0) {
    echo "<span class='pass'>✅ This query works for owners!</span><br>";
    echo "<table>";
    echo "<tr><th>ID</th><th>Reference</th><th>Status</th><th>Created By</th></tr>";
    foreach ($result3 as $row) {
        echo "<tr><td>{$row['id']}</td><td>{$row['batch_reference']}</td><td>{$row['status']}</td><td>{$row['created_by']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<span class='fail'>❌ No results</span>";
}
echo "</div>";
echo "</div>";

// ============================================================
// Test 4: Approver filter (pending, approved, draft)
// ============================================================
echo "<div class='step'>";
echo "<h2>Test 4: Approver Filter (pending, approved, draft)</h2>";

$sql4 = "SELECT id, batch_reference, status, created_by, organization_id FROM disbursement_batches WHERE organization_id = :org_id AND status IN ('pending', 'pending_approval', 'approved', 'draft', 'PENDING', 'PENDING_APPROVAL', 'APPROVED') ORDER BY id";
$stmt = $pdo->prepare($sql4);
$stmt->execute([':org_id' => $orgId]);
$result4 = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<div class='query-result " . (count($result4) > 0 ? 'pass' : 'fail') . "'>";
echo "Count: " . count($result4) . " batches<br>";
echo "<pre>" . htmlspecialchars($sql4) . "</pre>";
echo "Params: org_id = $orgId<br>";
if (count($result4) > 0) {
    echo "<span class='pass'>✅ This query works for approvers!</span><br>";
} else {
    echo "<span class='fail'>❌ No results</span>";
}
echo "</div>";
echo "</div>";

// ============================================================
// Test 5: Loader filter (own + pending + approved + draft)
// ============================================================
echo "<div class='step'>";
echo "<h2>Test 5: Loader Filter (own + pending + approved + draft)</h2>";

$sql5 = "SELECT id, batch_reference, status, created_by, organization_id FROM disbursement_batches WHERE organization_id = :org_id AND (created_by = :user_id OR status IN ('pending', 'pending_approval', 'approved', 'draft')) ORDER BY id";
$stmt = $pdo->prepare($sql5);
$stmt->execute([':org_id' => $orgId, ':user_id' => $userId]);
$result5 = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<div class='query-result " . (count($result5) > 0 ? 'pass' : 'fail') . "'>";
echo "Count: " . count($result5) . " batches<br>";
echo "<pre>" . htmlspecialchars($sql5) . "</pre>";
echo "Params: org_id = $orgId, user_id = $userId<br>";
if (count($result5) > 0) {
    echo "<span class='pass'>✅ This query works for loaders!</span><br>";
} else {
    echo "<span class='fail'>❌ No results</span>";
}
echo "</div>";
echo "</div>";

// ============================================================
// Test 6: Supervisor filter (approved + completed)
// ============================================================
echo "<div class='step'>";
echo "<h2>Test 6: Supervisor Filter (approved + completed)</h2>";

$sql6 = "SELECT id, batch_reference, status, created_by, organization_id FROM disbursement_batches WHERE organization_id = :org_id AND status IN ('approved', 'completed', 'executed', 'APPROVED', 'COMPLETED', 'EXECUTED') ORDER BY id";
$stmt = $pdo->prepare($sql6);
$stmt->execute([':org_id' => $orgId]);
$result6 = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<div class='query-result " . (count($result6) > 0 ? 'pass' : 'warn') . "'>";
echo "Count: " . count($result6) . " batches<br>";
echo "<pre>" . htmlspecialchars($sql6) . "</pre>";
echo "Params: org_id = $orgId<br>";
if (count($result6) > 0) {
    echo "<span class='pass'>✅ This query works for supervisors!</span><br>";
} else {
    echo "<span class='warn'>⚠️ No approved/completed batches yet</span><br>";
}
echo "</div>";
echo "</div>";

// ============================================================
// Test 7: Read-only filter (completed only)
// ============================================================
echo "<div class='step'>";
echo "<h2>Test 7: Read-only Filter (completed only)</h2>";

$sql7 = "SELECT id, batch_reference, status, created_by, organization_id FROM disbursement_batches WHERE organization_id = :org_id AND status IN ('completed', 'executed', 'COMPLETED', 'EXECUTED') ORDER BY id";
$stmt = $pdo->prepare($sql7);
$stmt->execute([':org_id' => $orgId]);
$result7 = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<div class='query-result " . (count($result7) > 0 ? 'pass' : 'warn') . "'>";
echo "Count: " . count($result7) . " batches<br>";
echo "<pre>" . htmlspecialchars($sql7) . "</pre>";
echo "Params: org_id = $orgId<br>";
if (count($result7) > 0) {
    echo "<span class='pass'>✅ This query works for read-only users!</span><br>";
} else {
    echo "<span class='warn'>⚠️ No completed batches yet</span><br>";
}
echo "</div>";
echo "</div>";

// ============================================================
// Test 8: Full dashboard query (with ORDER BY and LIMIT)
// ============================================================
echo "<div class='step'>";
echo "<h2>Test 8: Full Dashboard Query (Owner - AND 1=1)</h2>";

$sql8 = "
    SELECT 
        id, batch_reference, batch_name, source_institution,
        total_amount, total_destinations, status, created_at,
        updated_at, created_by, department_id
    FROM disbursement_batches 
    WHERE organization_id = :org_id AND 1=1
    ORDER BY 
        CASE 
            WHEN status IN ('pending', 'pending_approval') THEN 1
            WHEN status = 'approved' THEN 2
            WHEN status = 'draft' THEN 3
            ELSE 4
        END,
        created_at DESC 
    LIMIT 15
";
$stmt = $pdo->prepare($sql8);
$stmt->execute([':org_id' => $orgId]);
$result8 = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<div class='query-result " . (count($result8) > 0 ? 'pass' : 'fail') . "'>";
echo "Count: " . count($result8) . " batches<br>";
echo "<pre>" . htmlspecialchars($sql8) . "</pre>";
echo "Params: org_id = $orgId<br>";
if (count($result8) > 0) {
    echo "<span class='pass'>✅ FULL DASHBOARD QUERY WORKS!</span><br>";
    echo "<table>";
    echo "<tr><th>ID</th><th>Reference</th><th>Status</th><th>Amount</th><th>Created By</th></tr>";
    foreach ($result8 as $row) {
        echo "<tr>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['batch_reference']}</td>";
        echo "<td>{$row['status']}</td>";
        echo "<td>" . number_format($row['total_amount'] ?? 0, 2) . "</td>";
        echo "<td>{$row['created_by']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<span class='fail'>❌ No results - something is wrong with the query</span>";
}
echo "</div>";
echo "</div>";

// ============================================================
// Summary
// ============================================================
echo "<div class='box' style='border: 2px solid #4ade80; margin-top: 20px;'>";
echo "<h2>📊 Summary</h2>";

$workingQueries = [];
if (count($result1) > 0) $workingQueries[] = "Test 1: No Filters";
if (count($result2) > 0) $workingQueries[] = "Test 2: organization_id only";
if (count($result3) > 0) $workingQueries[] = "Test 3: AND 1=1 (OWNER)";
if (count($result4) > 0) $workingQueries[] = "Test 4: Approver filter";
if (count($result5) > 0) $workingQueries[] = "Test 5: Loader filter";
if (count($result6) > 0) $workingQueries[] = "Test 6: Supervisor filter";
if (count($result7) > 0) $workingQueries[] = "Test 7: Read-only filter";
if (count($result8) > 0) $workingQueries[] = "Test 8: Full Dashboard Query";

echo "Working queries: <br>";
foreach ($workingQueries as $q) {
    echo "<span class='pass'>✅ $q</span><br>";
}

if (count($result8) > 0) {
    echo "<br><span class='pass'>✅ The FULL DASHBOARD QUERY with AND 1=1 works!</span><br>";
    echo "The fix is to make sure the owner role uses <code>AND 1=1</code> in the status filter.";
} else {
    echo "<br><span class='fail'>❌ None of the queries returned results. Check database connection.</span><br>";
}

echo "<div style='margin-top:16px; display:flex; gap:12px; flex-wrap:wrap;'>";
echo "<a href='test_queries.php' class='btn' style='background:#60a5fa; color:#0f172a; padding:8px 16px; border:none; border-radius:4px; cursor:pointer; text-decoration:none;'>🔄 Re-run</a>";
echo "<a href='index.php' class='btn' style='background:#4ade80; color:#0f172a; padding:8px 16px; border:none; border-radius:4px; cursor:pointer; text-decoration:none;'>📊 Dashboard</a>";
echo "</div>";
echo "</div>";

echo "</body></html>";
