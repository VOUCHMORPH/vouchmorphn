<?php
require_once '../auth.php';
$user = requireEnterpriseAuth();
require_once '../../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

$db = DBConnection::getInstance();
$orgId = getOrganizationId();

// Get filter parameters
$status = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';

$sql = "SELECT * FROM import_batches WHERE organization_id = :org_id";
$params = [':org_id' => $orgId];

if ($status !== 'all') {
    $sql .= " AND status = :status";
    $params[':status'] = $status;
}

if ($search) {
    $sql .= " AND (batch_reference ILIKE :search OR batch_name ILIKE :search)";
    $params[':search'] = "%$search%";
}

$sql .= " ORDER BY created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get status counts for filters
$stmt = $db->prepare("
    SELECT status, COUNT(*) as count FROM import_batches 
    WHERE organization_id = :org_id GROUP BY status
");
$stmt->execute([':org_id' => $orgId]);
$statusCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Batches - VouchMorph Enterprise</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f1f5f9;
            color: #0f172a;
        }
        .app { display: flex; min-height: 100vh; }
        .sidebar {
            width: 280px;
            background: #0f172a;
            color: #e2e8f0;
            position: fixed;
            height: 100vh;
        }
        .sidebar-header { padding: 24px; border-bottom: 1px solid #1e293b; }
        .sidebar-header h2 { font-size: 20px; font-weight: 700; }
        .sidebar-header span { color: #fbbf24; }
        .sidebar-nav { padding: 20px 0; }
        .nav-item {
            padding: 12px 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #cbd5e1;
            text-decoration: none;
        }
        .nav-item:hover, .nav-item.active { background: #1e293b; color: white; }
        .main {
            flex: 1;
            margin-left: 280px;
            padding: 24px 32px;
        }
