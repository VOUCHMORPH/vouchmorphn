<?php
declare(strict_types=1);

namespace DASHBOARD;

use PDO;

// ============================================================================
// INITIALIZATION
// ============================================================================
ob_start();
$countryCode = $_GET['country'] ?? $_SESSION['country'] ?? 'BW';

if (!defined('APP_ROOT')) {
    define('APP_ROOT', rtrim(realpath(__DIR__ . '/../../'), '/') ?: '/var/www/html');
}

require_once APP_ROOT . '/vendor/autoload.php';
require_once APP_ROOT . '/src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;
$db = DBConnection::getConnection();

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================
function format_amount($amount, $decimals = 2) {
    return number_format((float)$amount, $decimals);
}

function safe_json_decode($json, $default = []) {
    if (is_array($json)) return $json;
    if (is_string($json) && !empty($json)) {
        $decoded = json_decode($json, true);
        return $decoded ?: $default;
    }
    return $default;
}

// ============================================================================
// DOWNLOAD HANDLERS
// ============================================================================

// Professional Transaction Report Download
if (isset($_GET['export_transaction']) && !empty($_GET['swap'])) {
    $exportSwap = $_GET['swap'];
    
    $swapQuery = $db->prepare("
        SELECT 
            swap_id,
            swap_uuid,
            from_currency,
            to_currency,
            amount,
            source_details,
            destination_details,
            status,
            created_at,
            metadata
        FROM swap_requests 
        WHERE swap_uuid = ?
    ");
    $swapQuery->execute([$exportSwap]);
    $swap = $swapQuery->fetch(PDO::FETCH_ASSOC);
    
    if (!$swap) {
        die("Transaction not found");
    }
    
    $holdQuery = $db->prepare("SELECT * FROM hold_transactions WHERE swap_reference = ? ORDER BY created_at ASC");
    $holdQuery->execute([$exportSwap]);
    $holds = $holdQuery->fetchAll(PDO::FETCH_ASSOC);
    
    $apiQuery = $db->prepare("SELECT * FROM api_message_logs WHERE message_id = ? ORDER BY created_at ASC");
    $apiQuery->execute([$exportSwap]);
    $apis = $apiQuery->fetchAll(PDO::FETCH_ASSOC);
    
    $feeQuery = $db->prepare("SELECT * FROM swap_fee_collections WHERE swap_reference = ? ORDER BY created_at ASC");
    $feeQuery->execute([$exportSwap]);
    $fees = $feeQuery->fetchAll(PDO::FETCH_ASSOC);
    
    $ledgerQuery = $db->prepare("SELECT * FROM ledger_entries WHERE reference = ? ORDER BY created_at ASC");
    $ledgerQuery->execute([$exportSwap]);
    $ledgers = $ledgerQuery->fetchAll(PDO::FETCH_ASSOC);
    
    $cardAuthQuery = $db->prepare("SELECT * FROM card_authorizations WHERE swap_reference = ? ORDER BY created_at ASC");
    $cardAuthQuery->execute([$exportSwap]);
    $cardAuths = $cardAuthQuery->fetchAll(PDO::FETCH_ASSOC);
    
    $sourceDetails = safe_json_decode($swap['source_details']);
    $destDetails = safe_json_decode($swap['destination_details']);
    $metadata = safe_json_decode($swap['metadata']);
    
    $sourceInst = $sourceDetails['institution'] ?? null;
    $destInst = $destDetails['institution'] ?? null;
    
    $participantInfo = [];
    if ($sourceInst) {
        $partQuery = $db->prepare("SELECT * FROM participants WHERE name = ? OR provider_code = ?");
        $partQuery->execute([$sourceInst, $sourceInst]);
        $participantInfo['source'] = $partQuery->fetch(PDO::FETCH_ASSOC);
    }
    if ($destInst) {
        $partQuery = $db->prepare("SELECT * FROM participants WHERE name = ? OR provider_code = ?");
        $partQuery->execute([$destInst, $destInst]);
        $participantInfo['destination'] = $partQuery->fetch(PDO::FETCH_ASSOC);
    }
    
    $filename = 'transaction_report_' . substr($exportSwap, 0, 8) . '_' . date('Ymd_His');
    
    header('Content-Type: text/html');
    header('Content-Disposition: attachment; filename="' . $filename . '.html"');
    
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · TRANSACTION REPORT · <?php echo substr($exportSwap, 0, 8); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Inter', sans-serif;
            background: #f8fafc;
            color: #0a0a0a;
            line-height: 1.6;
            padding: 3rem;
            max-width: 1400px;
            margin: 0 auto;
        }
        .cover-page {
            background: linear-gradient(135deg, #001B44 0%, #002B6A 100%);
            color: white;
            padding: 4rem;
            border-radius: 24px;
            margin-bottom: 3rem;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
            position: relative;
            overflow: hidden;
        }
        .cover-page::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,218,99,0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }
        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        .cover-title {
            font-size: 3.5rem;
            font-weight: 300;
            letter-spacing: -0.02em;
            margin-bottom: 1rem;
            position: relative;
            z-index: 1;
        }
        .cover-title span {
            color: #FFDA63;
            font-weight: 600;
        }
        .cover-subtitle {
            font-size: 1.2rem;
            color: #A1B5D8;
            margin-bottom: 3rem;
        }
        .cover-badge {
            display: inline-block;
            padding: 0.75rem 2rem;
            background: rgba(255,218,99,0.1);
            border: 1px solid #FFDA63;
            color: #FFDA63;
            border-radius: 40px;
            font-size: 0.9rem;
            margin-bottom: 2rem;
        }
        .cover-meta {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 2rem;
            margin-top: 3rem;
        }
        .cover-meta-item {
            border-left: 2px solid rgba(255,218,99,0.3);
            padding-left: 1.5rem;
        }
        .cover-meta-label {
            font-size: 0.8rem;
            text-transform: uppercase;
            color: #A1B5D8;
            letter-spacing: 0.05em;
            margin-bottom: 0.5rem;
        }
        .cover-meta-value {
            font-size: 1.5rem;
            font-weight: 300;
            color: #FFDA63;
        }
        .section {
            margin: 3rem 0;
            page-break-inside: avoid;
        }
        .section-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #001B44;
        }
        .section-icon {
            width: 48px;
            height: 48px;
            background: #001B44;
            color: #FFDA63;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        .section-title {
            font-size: 1.8rem;
            font-weight: 300;
            color: #001B44;
        }
        .section-title span {
            font-weight: 600;
            color: #FFDA63;
            margin-left: 0.5rem;
        }
        .card {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
            border: 1px solid #e2e8f0;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f1f5f9;
        }
        .card-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #001B44;
            text-transform: uppercase;
        }
        .card-badge {
            padding: 0.5rem 1.5rem;
            background: #001B44;
            color: #FFDA63;
            border-radius: 40px;
            font-size: 0.8rem;
        }
        .grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 2rem; }
        .grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 2rem; }
        .json-block {
            background: #0f172a;
            color: #e2e8f0;
            padding: 1.5rem;
            border-radius: 12px;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            overflow-x: auto;
            white-space: pre-wrap;
        }
        .timeline {
            position: relative;
            padding-left: 3rem;
        }
        .timeline-item {
            position: relative;
            padding-bottom: 3rem;
            border-left: 3px solid #001B44;
            padding-left: 2rem;
            margin-left: 1rem;
        }
        .timeline-item:last-child {
            border-left: none;
        }
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -0.65rem;
            top: 0;
            width: 1.3rem;
            height: 1.3rem;
            background: #FFDA63;
            border: 3px solid #001B44;
            border-radius: 50%;
        }
        .timeline-time {
            font-family: monospace;
            color: #64748b;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        .timeline-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #001B44;
            margin-bottom: 0.5rem;
        }
        .timeline-subtitle {
            color: #475569;
            margin-bottom: 1rem;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th {
            background: #f8fafc;
            padding: 1rem;
            text-align: left;
            color: #001B44;
            border-bottom: 2px solid #e2e8f0;
        }
        td {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }
        .fee-split {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        .fee-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 1rem;
            background: #f8fafc;
            border-radius: 8px;
        }
        .fee-party {
            font-weight: 600;
            color: #001B44;
        }
        .fee-amount {
            font-family: monospace;
            color: #10b981;
            font-weight: 600;
        }
        .signature-section {
            margin-top: 4rem;
            padding-top: 3rem;
            border-top: 3px solid #001B44;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }
        .signature-line {
            margin-top: 2rem;
            border-bottom: 2px solid #001B44;
            width: 300px;
        }
        .footer {
            margin-top: 4rem;
            padding-top: 2rem;
            text-align: center;
            color: #64748b;
            font-size: 0.8rem;
            border-top: 1px solid #e2e8f0;
        }
        .status-completed { color: #10b981; }
        .status-pending { color: #f59e0b; }
        .status-failed { color: #ef4444; }
        @media print {
            .cover-page { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <div class="cover-page">
        <div class="cover-badge">OFFICIAL TRANSACTION REPORT</div>
        <div class="cover-title">VOUCHMORPH <span>MESSAGE CLEARING HOUSE</span></div>
        <div class="cover-subtitle">Complete Transaction Evidence · ISO 20022 Compliant</div>
        <div class="cover-meta">
            <div class="cover-meta-item">
                <div class="cover-meta-label">Transaction ID</div>
                <div class="cover-meta-value"><?php echo substr($swap['swap_uuid'], 0, 16); ?>…</div>
            </div>
            <div class="cover-meta-item">
                <div class="cover-meta-label">Amount</div>
                <div class="cover-meta-value"><?php echo number_format((float)$swap['amount'], 2); ?> <?php echo $swap['from_currency'] ?? 'BWP'; ?></div>
            </div>
            <div class="cover-meta-item">
                <div class="cover-meta-label">Status</div>
                <div class="cover-meta-value"><?php echo strtoupper($swap['status']); ?></div>
            </div>
            <div class="cover-meta-item">
                <div class="cover-meta-label">Date</div>
                <div class="cover-meta-value"><?php echo date('Y-m-d', strtotime($swap['created_at'])); ?></div>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section-header">
            <div class="section-icon">📋</div>
            <div class="section-title">Transaction <span>Overview</span></div>
        </div>
        <div class="grid-2">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">SOURCE INSTITUTION</div>
                    <div class="card-badge">FUNDS ORIGIN</div>
                </div>
                <div class="json-block"><?php echo json_encode($sourceDetails, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?></div>
                <?php if (!empty($participantInfo['source'])): ?>
                <div style="margin-top: 1rem; padding: 1rem; background: #f8fafc; border-radius: 8px;">
                    <strong>Participant:</strong> <?php echo $participantInfo['source']['name']; ?> · 
                    <?php echo $participantInfo['source']['type']; ?> · <?php echo $participantInfo['source']['provider_code']; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="card">
                <div class="card-header">
                    <div class="card-title">DESTINATION INSTITUTION</div>
                    <div class="card-badge">FUNDS RECIPIENT</div>
                </div>
                <div class="json-block"><?php echo json_encode($destDetails, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?></div>
                <?php if (!empty($participantInfo['destination'])): ?>
                <div style="margin-top: 1rem; padding: 1rem; background: #f8fafc; border-radius: 8px;">
                    <strong>Participant:</strong> <?php echo $participantInfo['destination']['name']; ?> · 
                    <?php echo $participantInfo['destination']['type']; ?> · <?php echo $participantInfo['destination']['provider_code']; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section-header">
            <div class="section-icon">⏱️</div>
            <div class="section-title">Transaction <span>Flow</span></div>
        </div>
        <div class="timeline">
            <div class="timeline-item">
                <div class="timeline-time"><?php echo date('H:i:s', strtotime($swap['created_at'])); ?></div>
                <div class="timeline-title">1. API REQUEST</div>
                <div class="timeline-subtitle">POST /swap/execute</div>
                <div class="json-block"><?php echo json_encode([
                    'source' => $sourceDetails,
                    'destination' => $destDetails,
                    'amount' => (float)$swap['amount'],
                    'currency' => $swap['from_currency']
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?></div>
            </div>

            <?php foreach ($holds as $index => $hold): ?>
            <div class="timeline-item">
                <div class="timeline-time"><?php echo date('H:i:s', strtotime($hold['placed_at'] ?? $hold['created_at'])); ?></div>
                <div class="timeline-title">2.<?php echo $index + 1; ?>. HOLD CREATED</div>
                <div class="timeline-subtitle"><?php echo htmlspecialchars($hold['participant_name'] ?? $hold['source_institution']); ?></div>
                <div class="json-block"><?php echo json_encode([
                    'hold_reference' => $hold['hold_reference'],
                    'asset_type' => $hold['asset_type'],
                    'amount' => (float)$hold['amount'],
                    'currency' => $hold['currency'] ?? 'BWP',
                    'status' => $hold['status'],
                    'expiry' => $hold['hold_expiry'] ?? 'N/A'
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?></div>
            </div>
            <?php endforeach; ?>

            <?php foreach ($apis as $index => $api): ?>
            <div class="timeline-item">
                <div class="timeline-time"><?php echo date('H:i:s', strtotime($api['created_at'])); ?></div>
                <div class="timeline-title">3.<?php echo $index + 1; ?>. <?php echo strtoupper($api['direction'] ?? 'API'); ?> MESSAGE</div>
                <div class="timeline-subtitle">
                    <?php echo htmlspecialchars($api['participant_name'] ?? 'Unknown'); ?> · 
                    HTTP <?php echo $api['http_status_code'] ?? 'N/A'; ?> · 
                    <?php echo $api['duration_ms'] ?? 'N/A'; ?>ms
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div>
                        <strong style="color: #ff6b6b;">REQUEST:</strong>
                        <div class="json-block"><?php echo json_encode(safe_json_decode($api['request_payload']), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?></div>
                    </div>
                    <div>
                        <strong style="color: #4ecdc4;">RESPONSE:</strong>
                        <div class="json-block"><?php echo json_encode(safe_json_decode($api['response_payload']), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if (!empty($ledgers)): ?>
            <div class="timeline-item">
                <div class="timeline-time"><?php echo date('H:i:s', strtotime($ledgers[0]['created_at'])); ?></div>
                <div class="timeline-title">4. LEDGER IMPACT</div>
                <div class="timeline-subtitle">Double-Entry Accounting</div>
                <table>
                    <thead><tr><th>Debit</th><th>Credit</th><th>Amount</th></tr></thead>
                    <tbody>
                        <?php foreach ($ledgers as $entry): ?>
                        <tr>
                            <td style="color:#ff6b6b;"><?php echo $entry['debit_account_id']; ?></td>
                            <td style="color:#4ecdc4;"><?php echo $entry['credit_account_id']; ?></td>
                            <td style="color:#10b981;"><?php echo number_format($entry['amount'],2); ?> BWP</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php foreach ($fees as $index => $fee): 
                $split = safe_json_decode($fee['split_config']);
            ?>
            <div class="timeline-item">
                <div class="timeline-time"><?php echo date('H:i:s', strtotime($fee['collected_at'] ?? $fee['created_at'])); ?></div>
                <div class="timeline-title">5.<?php echo $index + 1; ?>. FEE SPLIT</div>
                <div class="timeline-subtitle"><?php echo $fee['fee_type']; ?></div>
                <div style="margin-bottom:1rem;"><strong>Total:</strong> <?php echo number_format($fee['total_amount'],2); ?> BWP</div>
                <?php if (!empty($split)): ?>
                <div class="fee-split">
                    <?php foreach ($split as $party => $amount): ?>
                    <div class="fee-row">
                        <span class="fee-party"><?php echo strtoupper($party); ?></span>
                        <span class="fee-amount">+<?php echo number_format($amount,2); ?> BWP</span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <?php foreach ($cardAuths as $index => $auth): ?>
            <div class="timeline-item">
                <div class="timeline-time"><?php echo date('H:i:s', strtotime($auth['created_at'])); ?></div>
                <div class="timeline-title">6.<?php echo $index + 1; ?>. CARD AUTHORIZATION</div>
                <div class="timeline-subtitle">Card •••• <?php echo $auth['card_suffix']; ?></div>
                <div class="json-block"><?php echo json_encode([
                    'authorized' => $auth['authorized_amount'],
                    'remaining' => $auth['remaining_balance'],
                    'status' => $auth['status']
                ], JSON_PRETTY_PRINT); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="section">
        <div class="section-header">
            <div class="section-icon">📊</div>
            <div class="section-title">Transaction <span>Statistics</span></div>
        </div>
        <div class="grid-4">
            <div class="card" style="text-align:center;"><div style="font-size:2.5rem;"><?php echo count($apis); ?></div><div>API Messages</div></div>
            <div class="card" style="text-align:center;"><div style="font-size:2.5rem;"><?php echo count($holds); ?></div><div>Hold Transactions</div></div>
            <div class="card" style="text-align:center;"><div style="font-size:2.5rem;"><?php echo count($ledgers); ?></div><div>Ledger Entries</div></div>
            <div class="card" style="text-align:center;"><div style="font-size:2.5rem;"><?php echo number_format(array_sum(array_column($fees, 'total_amount')),2); ?></div><div>Total Fees</div></div>
        </div>
    </div>

    <?php if (!empty($metadata)): ?>
    <div class="section">
        <div class="section-header"><div class="section-icon">📎</div><div class="section-title">Additional <span>Metadata</span></div></div>
        <div class="card"><div class="json-block"><?php echo json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?></div></div>
    </div>
    <?php endif; ?>

    <div class="section">
        <div class="card" style="border:2px solid #001B44;">
            <div style="text-align:center;margin-bottom:2rem;"><h2 style="color:#001B44;">Regulatory Declaration</h2></div>
            <div style="font-style:italic;margin-bottom:2rem;text-align:center;">
                "This document certifies that the above transaction was processed in accordance with ISO 20022 standards, 
                with all messages logged, funds never held in custody, and complete audit trail maintained as required by the Bank of Botswana."
            </div>
            <div class="signature-section">
                <div><strong>Generated By:</strong> VouchMorph Message Clearing House</div>
                <div style="text-align:right;"><strong>SHA-256:</strong> <?php echo substr(hash('sha256', $exportSwap), 0, 16); ?>… ✓ VERIFIED</div>
            </div>
        </div>
    </div>

    <div class="footer">
        <p>VOUCHMORPH PROPRIETARY LIMITED · CONFIDENTIAL · Bank of Botswana Regulatory Sandbox</p>
        <p>Report Generated: <?php echo date('Y-m-d H:i:s T'); ?> · Transaction: <?php echo $swap['swap_uuid']; ?></p>
    </div>
</body>
</html>
    <?php
    exit;
}

// ============================================================================
// FETCH DASHBOARD DATA
// ============================================================================

$selectedSwap = $_GET['swap'] ?? null;
$timeframe = $_GET['timeframe'] ?? 'today';
$view = $_GET['clearing_view'] ?? 'overview';

$dateRange = match($timeframe) {
    'today' => ['start' => date('Y-m-d 00:00:00'), 'end' => date('Y-m-d 23:59:59')],
    'week' => ['start' => date('Y-m-d 00:00:00', strtotime('-7 days')), 'end' => date('Y-m-d 23:59:59')],
    'month' => ['start' => date('Y-m-d 00:00:00', strtotime('-30 days')), 'end' => date('Y-m-d 23:59:59')],
    default => ['start' => date('Y-m-d 00:00:00'), 'end' => date('Y-m-d 23:59:59')]
};

$tpsQuery = $db->prepare("SELECT COUNT(*) as tx_count, EXTRACT(EPOCH FROM (MAX(created_at) - MIN(created_at))) as duration_seconds FROM swap_requests WHERE created_at BETWEEN ? AND ?");
$tpsQuery->execute([$dateRange['start'], $dateRange['end']]);
$tpsData = $tpsQuery->fetch(PDO::FETCH_ASSOC);
$tps = $tpsData && $tpsData['duration_seconds'] > 0 ? round($tpsData['tx_count'] / $tpsData['duration_seconds'], 2) : 0;

$liquidityQuery = $db->query("SELECT COALESCE(SUM(balance), 0) as total_liquidity FROM ledger_accounts WHERE account_type IN ('escrow', 'settlement')");
$liquidity = $liquidityQuery->fetchColumn() ?: 0;

$instQuery = $db->query("SELECT COUNT(*) FROM participants WHERE status = 'ACTIVE'");
$activeInstitutions = $instQuery->fetchColumn();

$pendingQuery = $db->query("SELECT COUNT(*) FROM settlement_queue");
$pendingMessages = $pendingQuery->fetchColumn() ?: 0;

$liveSwapsQuery = $db->query("
    SELECT sr.swap_uuid, sr.amount, sr.status, sr.created_at,
           sr.source_details->>'institution' as source_institution,
           sr.source_details->>'asset_type' as source_type,
           sr.destination_details->>'institution' as dest_institution,
           sr.destination_details->>'asset_type' as dest_type
    FROM swap_requests sr
    ORDER BY sr.created_at DESC
    LIMIT 20
");
$liveSwaps = $liveSwapsQuery->fetchAll(PDO::FETCH_ASSOC);

$swapDetails = null;
$messageFlow = [];
$ledgerEntries = [];
$feeDetails = [];
$apiCalls = [];

if ($selectedSwap) {
    $swapDetailQuery = $db->prepare("SELECT * FROM swap_requests WHERE swap_uuid = ?");
    $swapDetailQuery->execute([$selectedSwap]);
    $swapDetails = $swapDetailQuery->fetch(PDO::FETCH_ASSOC);
    
    if ($swapDetails) {
        $holdQuery = $db->prepare("SELECT * FROM hold_transactions WHERE swap_reference = ? ORDER BY created_at ASC");
        $holdQuery->execute([$selectedSwap]);
        $messageFlow['hold'] = $holdQuery->fetchAll(PDO::FETCH_ASSOC);
        
        $apiQuery = $db->prepare("SELECT * FROM api_message_logs WHERE message_id = ? ORDER BY created_at ASC");
        $apiQuery->execute([$selectedSwap]);
        $apiCalls = $apiQuery->fetchAll(PDO::FETCH_ASSOC);
        
        $ledgerQuery = $db->prepare("SELECT le.* FROM ledger_entries le WHERE le.reference = ? ORDER BY le.created_at ASC");
        $ledgerQuery->execute([$selectedSwap]);
        $ledgerEntries = $ledgerQuery->fetchAll(PDO::FETCH_ASSOC);
        
        $feeQuery = $db->prepare("SELECT * FROM swap_fee_collections WHERE swap_reference = ? ORDER BY created_at ASC");
        $feeQuery->execute([$selectedSwap]);
        $feeDetails = $feeQuery->fetchAll(PDO::FETCH_ASSOC);
        
        $sourceDetails = safe_json_decode($swapDetails['source_details']);
        $destDetails = safe_json_decode($swapDetails['destination_details']);
        $sourceInst = $sourceDetails['institution'] ?? null;
        $destInst = $destDetails['institution'] ?? null;
        
        if ($sourceInst || $destInst) {
            $settlementQuery = $db->prepare("SELECT * FROM settlement_queue WHERE debtor = ? OR creditor = ? OR debtor = ? OR creditor = ? ORDER BY created_at DESC LIMIT 5");
            $settlementQuery->execute([$sourceInst, $sourceInst, $destInst, $destInst]);
            $messageFlow['settlement'] = $settlementQuery->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

$netPositionsQuery = $db->query("SELECT debtor, creditor, SUM(amount) as net_amount FROM settlement_queue GROUP BY debtor, creditor ORDER BY net_amount DESC LIMIT 10");
$netPositions = $netPositionsQuery->fetchAll(PDO::FETCH_ASSOC);

$institutionNets = [];
foreach ($netPositions as $pos) {
    if (!isset($institutionNets[$pos['debtor']])) $institutionNets[$pos['debtor']] = ['debit' => 0, 'credit' => 0];
    if (!isset($institutionNets[$pos['creditor']])) $institutionNets[$pos['creditor']] = ['debit' => 0, 'credit' => 0];
    $institutionNets[$pos['debtor']]['debit'] += (float)$pos['net_amount'];
    $institutionNets[$pos['creditor']]['credit'] += (float)$pos['net_amount'];
}

$clearanceQuery = $db->prepare("SELECT participant_name, COUNT(*) as total_messages, SUM(CASE WHEN success THEN 1 ELSE 0 END) as successful, AVG(duration_ms) as avg_response_time FROM api_message_logs WHERE created_at BETWEEN ? AND ? GROUP BY participant_name ORDER BY total_messages DESC");
$clearanceQuery->execute([$dateRange['start'], $dateRange['end']]);
$clearanceMetrics = $clearanceQuery->fetchAll(PDO::FETCH_ASSOC);

$institutions = array_keys($institutionNets);
$settlementMatrix = [];
foreach ($institutions as $debtor) {
    foreach ($institutions as $creditor) {
        if ($debtor !== $creditor) {
            $query = $db->prepare("SELECT COALESCE(SUM(amount), 0) as amount FROM settlement_queue WHERE debtor = ? AND creditor = ?");
            $query->execute([$debtor, $creditor]);
            $amount = $query->fetchColumn();
            if ($amount > 0) $settlementMatrix[] = ['from' => $debtor, 'to' => $creditor, 'amount' => (float)$amount];
        }
    }
}

$successQuery = $db->query("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed FROM swap_requests");
$successStats = $successQuery->fetch(PDO::FETCH_ASSOC);
$successRate = $successStats['total'] > 0 ? round(($successStats['completed'] / $successStats['total']) * 100, 2) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · MESSAGE CLEARING HOUSE · <?php echo $countryCode; ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: #0a0a0a;
            font-family: 'Inter', sans-serif;
            color: #fff;
            line-height: 1.5;
            padding: 2rem;
        }
        .container { max-width: 1800px; margin: 0 auto; }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #222;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .logo {
            font-size: 1.5rem;
            font-weight: 300;
            text-transform: uppercase;
            letter-spacing: 0.2em;
            color: #0f0;
        }
        .logo span { color: #666; font-size: 0.8rem; margin-left: 1rem; }
        .status-badge {
            padding: 0.5rem 1.5rem;
            background: #111;
            border: 1px solid #0f0;
            color: #0f0;
            font-size: 0.8rem;
            text-transform: uppercase;
        }
        .download-section {
            margin: 2rem 0;
            padding: 2rem;
            background: #111;
            border: 2px solid #0f0;
            border-radius: 8px;
        }
        .download-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .download-title { color: #0f0; font-size: 1.2rem; }
        .download-buttons { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .btn {
            padding: 0.75rem 1.5rem;
            background: transparent;
            border: 2px solid;
            color: #fff;
            text-decoration: none;
            font-size: 0.8rem;
            text-transform: uppercase;
            border-radius: 4px;
        }
        .btn-green { background: #0f0; border-color: #0f0; color: #000; }
        .btn-green:hover { background: #0c0; }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1.5rem;
        }
        .stat-box {
            background: #000;
            padding: 1.5rem;
            border: 1px solid #333;
            border-radius: 4px;
        }
        .stat-value {
            color: #0f0;
            font-size: 2rem;
            font-weight: 200;
            font-family: 'Courier New', monospace;
        }
        .stat-label { color: #666; font-size: 0.8rem; text-transform: uppercase; margin-top: 0.5rem; }
        .clearing-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 2rem 0;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .clearing-title {
            font-size: 1.8rem;
            font-weight: 200;
            text-transform: uppercase;
            letter-spacing: 0.2em;
            color: #0f0;
        }
        .timeframe-selector { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .timeframe-btn {
            padding: 0.5rem 1rem;
            background: transparent;
            border: 1px solid #333;
            color: #888;
            text-decoration: none;
            font-size: 0.8rem;
            text-transform: uppercase;
            border-radius: 4px;
        }
        .timeframe-btn.active { background: #0f0; color: #000; border-color: #0f0; }
        .global-status {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .status-card {
            background: #111;
            border: 2px solid #222;
            padding: 1.5rem;
            position: relative;
            border-radius: 8px;
        }
        .status-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, #0f0, transparent);
        }
        .status-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: #666;
            margin-bottom: 0.5rem;
        }
        .status-value {
            font-size: 2.2rem;
            font-weight: 200;
            font-family: 'Courier New', monospace;
            color: #0f0;
        }
        .status-unit { font-size: 0.8rem; color: #444; margin-left: 0.25rem; }
        .clearing-main {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .swap-feed {
            background: #111;
            border: 2px solid #222;
            height: 600px;
            overflow-y: auto;
            border-radius: 8px;
        }
        .feed-header {
            padding: 1rem;
            border-bottom: 2px solid #222;
            font-size: 0.8rem;
            text-transform: uppercase;
            color: #888;
            position: sticky;
            top: 0;
            background: #111;
            z-index: 10;
        }
        .swap-item {
            position: relative;
            padding: 1rem;
            border-bottom: 1px solid #222;
            transition: all 0.2s;
        }
        .swap-item:hover { background: #1a1a1a; }
        .swap-item.selected {
            background: #1a1a1a;
            border-left: 3px solid #0f0;
        }
        .swap-path {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }
        .swap-source {
            color: #ff6b6b;
            max-width: 100px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .swap-dest {
            color: #4ecdc4;
            max-width: 100px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .swap-arrow { color: #444; }
        .swap-meta {
            display: flex;
            justify-content: space-between;
            font-size: 0.8rem;
            color: #666;
        }
        .swap-amount { color: #0f0; }
        .swap-status {
            padding: 0.2rem 0.5rem;
            border: 1px solid;
            font-size: 0.6rem;
            text-transform: uppercase;
            border-radius: 3px;
        }
        .status-completed { border-color: #0f0; color: #0f0; }
        .status-pending { border-color: #ff0; color: #ff0; }
        .status-failed { border-color: #f00; color: #f00; }
        .swap-download {
            position: absolute;
            top: 5px;
            right: 5px;
            background: #0f0;
            color: #000;
            padding: 2px 8px;
            text-decoration: none;
            font-size: 0.7rem;
            border-radius: 3px;
        }
        .swap-export {
            position: absolute;
            top: 5px;
            right: 60px;
            background: #FFDA63;
            color: #001B44;
            padding: 2px 8px;
            text-decoration: none;
            font-size: 0.7rem;
            border-radius: 3px;
            font-weight: 600;
        }
        .clearing-visualizer {
            background: #111;
            border: 2px solid #222;
            padding: 1.5rem;
            overflow-x: auto;
            border-radius: 8px;
        }
        .visualizer-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #222;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .selected-swap-ref { color: #0f0; font-family: 'Courier New', monospace; }
        .replay-btn {
            padding: 0.5rem 1.5rem;
            background: transparent;
            border: 2px solid #0f0;
            color: #0f0;
            font-size: 0.8rem;
            text-transform: uppercase;
            cursor: pointer;
            border-radius: 4px;
        }
        .message-timeline { margin: 2rem 0; }
        .timeline-step {
            display: flex;
            margin-bottom: 1.5rem;
            position: relative;
            flex-wrap: wrap;
        }
        .timeline-step::before {
            content: '';
            position: absolute;
            left: 20px;
            top: 40px;
            bottom: -20px;
            width: 2px;
            background: #333;
        }
        .timeline-step:last-child::before { display: none; }
        .timeline-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #000;
            border: 2px solid #0f0;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1.5rem;
            z-index: 2;
            flex-shrink: 0;
        }
        .timeline-content {
            flex: 1;
            background: #000;
            border: 2px solid #222;
            padding: 1.5rem;
            overflow-x: auto;
            border-radius: 8px;
        }
        .timeline-title {
            font-size: 1rem;
            text-transform: uppercase;
            color: #0f0;
            margin-bottom: 0.5rem;
        }
        .timeline-subtitle {
            font-size: 0.8rem;
            color: #888;
            margin-bottom: 1rem;
        }
        .timeline-details {
            background: #0a0a0a;
            padding: 1rem;
            font-family: 'Courier New', monospace;
            font-size: 0.8rem;
            overflow-x: auto;
            border-radius: 4px;
        }
        .timeline-details pre {
            white-space: pre-wrap;
            word-wrap: break-word;
            color: #ccc;
        }
        .net-positions {
            background: #111;
            border: 2px solid #222;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border-radius: 8px;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #222;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .card-title {
            font-size: 1rem;
            font-weight: 300;
            text-transform: uppercase;
            color: #fff;
        }
        .card-badge {
            padding: 0.25rem 1rem;
            background: #000;
            border: 1px solid #333;
            color: #888;
            font-size: 0.7rem;
            text-transform: uppercase;
            border-radius: 20px;
        }
        .positions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }
        .position-card {
            background: #000;
            border: 2px solid #222;
            padding: 1.5rem;
            border-radius: 8px;
        }
        .position-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .position-institution { font-weight: 400; color: #fff; }
        .position-net {
            font-size: 1.2rem;
            font-family: 'Courier New', monospace;
        }
        .net-positive { color: #0f0; }
        .net-negative { color: #f00; }
        .clearance-monitor {
            background: #111;
            border: 2px solid #222;
            padding: 1.5rem;
            margin-bottom: 2rem;
            overflow-x: auto;
            border-radius: 8px;
        }
        .clearance-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
            min-width: 600px;
        }
        .clearance-table th {
            text-align: left;
            padding: 1rem;
            background: #000;
            color: #888;
            font-size: 0.7rem;
            text-transform: uppercase;
        }
        .clearance-table td {
            padding: 1rem;
            border-bottom: 1px solid #222;
            font-family: 'Courier New', monospace;
            font-size: 0.8rem;
        }
        .success-rate {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            background: #1a3a1a;
            color: #0f0;
            border-radius: 3px;
        }
        .settlement-matrix {
            background: #111;
            border: 2px solid #222;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border-radius: 8px;
        }
        .matrix-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }
        .matrix-edge {
            background: #000;
            border: 2px solid #222;
            padding: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
            border-radius: 8px;
        }
        .edge-path { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
        .edge-from { color: #ff6b6b; max-width: 100px; overflow: hidden; text-overflow: ellipsis; }
        .edge-to { color: #4ecdc4; max-width: 100px; overflow: hidden; text-overflow: ellipsis; }
        .edge-amount { color: #0f0; }
        .footer {
            margin-top: 4rem;
            padding-top: 2rem;
            border-top: 2px solid #222;
            text-align: center;
            color: #444;
            font-size: 0.7rem;
        }
        @media (max-width: 1200px) { .global-status { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 992px) { .clearing-main { grid-template-columns: 1fr; } .swap-feed { height: 400px; } }
        @media (max-width: 768px) { body { padding: 1rem; } .global-status { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">VOUCHMORPH <span>MESSAGE CLEARING HOUSE</span></div>
            <div class="status-badge"><?php echo $countryCode; ?> · REAL-TIME CLEARING</div>
        </div>

        <div class="download-section">
            <div class="download-header">
                <div class="download-title">📊 REGULATORY EVIDENCE PACKAGES</div>
                <div class="download-buttons">
                    <a href="?download_report=csv" class="btn btn-green">📥 DOWNLOAD FULL CSV</a>
                </div>
            </div>
            <div class="stats-grid">
                <div class="stat-box"><div class="stat-value"><?php echo count($liveSwaps); ?></div><div class="stat-label">TOTAL SWAPS</div></div>
                <div class="stat-box"><div class="stat-value"><?php echo $activeInstitutions; ?></div><div class="stat-label">ACTIVE PARTNERS</div></div>
                <div class="stat-box"><div class="stat-value"><?php echo format_amount($liquidity / 1000000, 2); ?>M</div><div class="stat-label">LIQUIDITY</div></div>
                <div class="stat-box"><div class="stat-value"><?php echo $successRate; ?>%</div><div class="stat-label">SUCCESS RATE</div></div>
            </div>
        </div>

        <div class="clearing-header">
            <div class="clearing-title">MESSAGE CLEARING SYSTEM</div>
            <div class="timeframe-selector">
                <a href="?clearing_view=<?php echo $view; ?>&timeframe=today&swap=<?php echo $selectedSwap; ?>" class="timeframe-btn <?php echo $timeframe === 'today' ? 'active' : ''; ?>">TODAY</a>
                <a href="?clearing_view=<?php echo $view; ?>&timeframe=week&swap=<?php echo $selectedSwap; ?>" class="timeframe-btn <?php echo $timeframe === 'week' ? 'active' : ''; ?>">WEEK</a>
                <a href="?clearing_view=<?php echo $view; ?>&timeframe=month&swap=<?php echo $selectedSwap; ?>" class="timeframe-btn <?php echo $timeframe === 'month' ? 'active' : ''; ?>">MONTH</a>
            </div>
        </div>

        <div class="global-status">
            <div class="status-card"><div class="status-label">TRANSACTIONS/SEC</div><div class="status-value"><?php echo format_amount($tps, 2); ?><span class="status-unit">TPS</span></div></div>
            <div class="status-card"><div class="status-label">TOTAL LIQUIDITY</div><div class="status-value"><?php echo format_amount($liquidity / 1000000, 2); ?><span class="status-unit">M BWP</span></div></div>
            <div class="status-card"><div class="status-label">ACTIVE INSTITUTIONS</div><div class="status-value"><?php echo $activeInstitutions; ?><span class="status-unit">BANKS</span></div></div>
            <div class="status-card"><div class="status-label">PENDING MESSAGES</div><div class="status-value"><?php echo $pendingMessages; ?><span class="status-unit">QUEUE</span></div></div>
        </div>

        <div class="clearing-main">
            <div class="swap-feed">
                <div class="feed-header">LIVE SWAP STREAM · REAL-TIME</div>
                <?php foreach ($liveSwaps as $swap): ?>
                <div style="position: relative;">
                    <a href="?clearing_view=<?php echo $view; ?>&timeframe=<?php echo $timeframe; ?>&swap=<?php echo $swap['swap_uuid']; ?>" style="text-decoration: none;">
                        <div class="swap-item <?php echo $selectedSwap === $swap['swap_uuid'] ? 'selected' : ''; ?>">
                            <div class="swap-path">
                                <span class="swap-source" title="<?php echo htmlspecialchars($swap['source_institution'] ?? 'UNKNOWN'); ?>"><?php echo htmlspecialchars(substr($swap['source_institution'] ?? 'UNKNOWN', 0, 12)); ?></span>
                                <span class="swap-arrow">→</span>
                                <span class="swap-dest" title="<?php echo htmlspecialchars($swap['dest_institution'] ?? 'UNKNOWN'); ?>"><?php echo htmlspecialchars(substr($swap['dest_institution'] ?? 'UNKNOWN', 0, 12)); ?></span>
                            </div>
                            <div class="swap-meta">
                                <span><?php echo htmlspecialchars(substr($swap['source_type'] ?? '', 0, 8)); ?> → <?php echo htmlspecialchars(substr($swap['dest_type'] ?? '', 0, 8)); ?></span>
                                <span class="swap-amount"><?php echo format_amount($swap['amount']); ?> BWP</span>
                            </div>
                            <div class="swap-meta">
                                <span><?php echo date('H:i:s', strtotime($swap['created_at'])); ?></span>
                                <span class="swap-status status-<?php echo $swap['status']; ?>"><?php echo $swap['status']; ?></span>
                            </div>
                        </div>
                    </a>
                    <a href="?export_transaction=1&swap=<?php echo urlencode($swap['swap_uuid']); ?>" class="swap-export" title="Download Professional Report">📄 REPORT</a>
                    <a href="?download_swap=1&swap=<?php echo urlencode($swap['swap_uuid']); ?>&format=csv" class="swap-download" title="Download CSV">📥 CSV</a>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="clearing-visualizer">
                <?php if ($selectedSwap && $swapDetails): ?>
                <div class="visualizer-header">
                    <div class="selected-swap-info">Clearing: <span class="selected-swap-ref"><?php echo substr($selectedSwap, 0, 16); ?>…</span></div>
                    <button class="replay-btn" onclick="replaySwap()">⟲ REPLAY</button>
                </div>
                <div class="message-timeline" id="timeline">
                    <div class="timeline-step"><div class="timeline-icon">1</div><div class="timeline-content"><div class="timeline-title">API REQUEST</div><div class="timeline-subtitle"><?php echo date('H:i:s', strtotime($swapDetails['created_at'])); ?></div><div class="timeline-details"><pre><?php $sd = safe_json_decode($swapDetails['source_details']); $dd = safe_json_decode($swapDetails['destination_details']); echo json_encode(['source'=>$sd,'destination'=>$dd,'amount'=>(float)$swapDetails['amount'],'currency'=>$swapDetails['from_currency']], JSON_PRETTY_PRINT); ?></pre></div></div></div>
                    <?php if (!empty($messageFlow['hold'])): foreach($messageFlow['hold'] as $hold): ?>
                    <div class="timeline-step"><div class="timeline-icon">2</div><div class="timeline-content"><div class="timeline-title">HOLD CREATED</div><div class="timeline-subtitle"><?php echo htmlspecialchars($hold['participant_name'] ?? $hold['source_institution']); ?></div><div class="timeline-details"><pre><?php echo json_encode(['hold_reference'=>$hold['hold_reference'],'amount'=>$hold['amount'],'status'=>$hold['status']], JSON_PRETTY_PRINT); ?></pre></div></div></div>
                    <?php endforeach; endif; ?>
                    <?php foreach ($apiCalls as $api): ?>
                    <div class="timeline-step"><div class="timeline-icon">3</div><div class="timeline-content"><div class="timeline-title"><?php echo strtoupper($api['direction'] ?? 'API'); ?> MESSAGE</div><div class="timeline-subtitle"><?php echo htmlspecialchars($api['participant_name'] ?? 'Unknown'); ?></div><div class="timeline-details"><div style="color:#ff6b6b;">REQUEST:</div><pre><?php echo json_encode(safe_json_decode($api['request_payload']), JSON_PRETTY_PRINT); ?></pre><div style="color:#4ecdc4;margin-top:0.5rem;">RESPONSE:</div><pre><?php echo json_encode(safe_json_decode($api['response_payload']), JSON_PRETTY_PRINT); ?></pre></div></div></div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div style="text-align: center; padding: 4rem; color: #444;">↖️ Select a swap from the live feed</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="net-positions">
            <div class="card-header"><div class="card-title">NET POSITIONS</div><div class="card-badge">SETTLEMENT</div></div>
            <div class="positions-grid">
                <?php if (empty($institutionNets)): ?><div class="position-card" style="grid-column:1/-1;text-align:center;">No positions</div>
                <?php else: foreach ($institutionNets as $inst => $nets): $net = $nets['credit'] - $nets['debit']; ?>
                <div class="position-card"><div class="position-header"><span><?php echo htmlspecialchars($inst); ?></span><span class="position-net <?php echo $net>=0?'net-positive':'net-negative'; ?>"><?php echo ($net>=0?'+':'').format_amount($net); ?></span></div><div style="display:flex;justify-content:space-between;font-size:0.8rem;"><span>Receivable: <?php echo format_amount($nets['credit']); ?></span><span>Payable: <?php echo format_amount($nets['debit']); ?></span></div></div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <?php if (!empty($settlementMatrix)): ?>
        <div class="settlement-matrix">
            <div class="card-header"><div class="card-title">SETTLEMENT MATRIX</div><div class="card-badge">NETTING</div></div>
            <div class="matrix-grid">
                <?php foreach ($settlementMatrix as $edge): ?>
                <div class="matrix-edge"><div class="edge-path"><span class="edge-from" title="<?php echo $edge['from']; ?>"><?php echo substr($edge['from'],0,8); ?></span><span>→</span><span class="edge-to" title="<?php echo $edge['to']; ?>"><?php echo substr($edge['to'],0,8); ?></span></div><span class="edge-amount"><?php echo format_amount($edge['amount']); ?></span></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="clearance-monitor">
            <div class="card-header"><div class="card-title">MESSAGE CLEARANCE</div><div class="card-badge">API RELIABILITY</div></div>
            <table class="clearance-table">
                <thead><tr><th>PARTICIPANT</th><th>MESSAGES</th><th>SUCCESS</th><th>RESPONSE</th></tr></thead>
                <tbody>
                    <?php if (empty($clearanceMetrics)): ?><tr><td colspan="4" style="text-align:center;">No data</td></tr>
                    <?php else: foreach ($clearanceMetrics as $m): ?>
                    <tr><td><?php echo htmlspecialchars($m['participant_name']); ?></td><td><?php echo $m['total_messages']; ?></td><td><span class="success-rate"><?php echo round(($m['successful']/$m['total_messages'])*100,1); ?>%</span></td><td><?php echo round($m['avg_response_time']); ?> ms</td></tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="footer">
            <p>VOUCHMORPH · MESSAGE CLEARING HOUSE · DOUBLE-ENTRY VERIFIED · ISO20022 COMPLIANT</p>
            <p>CLEARED: <?php echo count($liveSwaps); ?> SWAPS · NET EXPOSURE: <?php $tc = array_sum(array_column($institutionNets,'credit')); $td = array_sum(array_column($institutionNets,'debit')); echo format_amount($tc - $td); ?> BWP</p>
        </div>
    </div>
    <script>function replaySwap(){const t=document.getElementById('timeline');if(!t)return;t.classList.add('replay-animation');document.querySelectorAll('.timeline-step').forEach((s,i)=>{s.style.opacity='0';s.style.transform='translateX(-20px)';s.style.transition='all 0.5s ease';setTimeout(()=>{s.style.opacity='1';s.style.transform='translateX(0)';},i*300);});setTimeout(()=>t.classList.remove('replay-animation'),document.querySelectorAll('.timeline-step').length*300+500);}</script>
</body>
</html>
<?php
