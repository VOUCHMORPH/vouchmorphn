<?php
/**
 * ============================================================================
 * BATCH EXECUTION — WIRED TO SwapService (REPLACES THE STUB)
 * ============================================================================
 * One organization source (wallet/account) -> many destinations, in a single
 * SwapService::executeAtomicSwap() call, which auto-routes to
 * executeMultiDestinationSwap() when >=2 destinations are present.
 *
 * GET  ?batch_id=X  -> shows a confirmation screen (+ PIN field if the source
 *                      isn't a pre-authorized "hooked" wallet)
 * POST batch_id=X (+wallet_pin if required) -> actually executes
 * ============================================================================
 */

require_once __DIR__ . '/../auth.php';
$user = requireEnterpriseAuth();
require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../../../../src/Core/Database/DBConnection.php';

use Core\Database\DBConnection;
use Domain\Services\SwapService;
use Core\Config\LoadCountry;

// ============================================================
// FIXED: Use getConnection() to match auth.php
// ============================================================
$db = DBConnection::getConnection(); // FIXED: was getInstance()
$orgId = getOrganizationId();
$batchId = (int)($_GET['batch_id'] ?? $_POST['batch_id'] ?? 0);

// ----------------------------------------------------------------------------
// LOAD BATCH + SOURCE (shared by both GET confirm screen and POST execution)
// ----------------------------------------------------------------------------
$stmt = $db->prepare("SELECT * FROM import_batches WHERE id = :id AND organization_id = :org_id");
$stmt->execute([':id' => $batchId, ':org_id' => $orgId]);
$batch = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$batch) {
    die("Batch not found");
}
if ($batch['status'] !== 'APPROVED') {
    die("Batch is not in APPROVED status (currently: {$batch['status']}). Only approved batches can be executed.");
}

$stmt = $db->prepare("SELECT * FROM organization_sources WHERE id = :id AND organization_id = :org_id");
$stmt->execute([':id' => $batch['source_id'], ':org_id' => $orgId]);
$source = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$source) {
    die("Source account not found or does not belong to this organization");
}

$isHookedSource = !empty($source['source_reference']);

$stmt = $db->prepare("
    SELECT COUNT(*) as cnt, COALESCE(SUM(amount), 0) as total
    FROM import_rows WHERE batch_id = :batch_id AND validation_status = 'VALID'
");
$stmt->execute([':batch_id' => $batchId]);
$rowStats = $stmt->fetch(PDO::FETCH_ASSOC);

// ============================================================================
// GET — CONFIRMATION SCREEN
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Confirm Execution — VouchMorph Enterprise</title>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: 'Inter', sans-serif; background: #f1f5f9; color: #0f172a; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; }
            .card { background: white; border-radius: 20px; padding: 36px; max-width: 480px; width: 100%; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
            h2 { font-size: 20px; margin-bottom: 4px; }
            .sub { color: #64748b; font-size: 14px; margin-bottom: 24px; }
            .stat-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
            .stat-row strong { font-size: 15px; }
            .warn-box { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 14px 16px; border-radius: 10px; margin: 20px 0; font-size: 13px; color: #92400e; }
            .form-group { margin-top: 20px; }
            .form-group label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; }
            .form-group input { width: 100%; padding: 12px 14px; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 15px; letter-spacing: 2px; }
            .btn-group { display: flex; gap: 12px; margin-top: 24px; }
            .btn { flex: 1; padding: 13px; border-radius: 40px; font-weight: 600; border: none; cursor: pointer; font-size: 14px; text-align: center; text-decoration: none; }
            .btn-primary { background: #0f172a; color: white; }
            .btn-secondary { background: #e2e8f0; color: #0f172a; }
        </style>
    </head>
    <body>
    <div class="card">
        <h2>Confirm Batch Execution</h2>
        <p class="sub"><?php echo htmlspecialchars($batch['batch_reference']); ?></p>

        <div class="stat-row"><span>Recipients</span><strong><?php echo number_format($rowStats['cnt']); ?></strong></div>
        <div class="stat-row"><span>Total Amount</span><strong><?php echo htmlspecialchars($batch['currency'] ?? 'BWP'); ?> <?php echo number_format($rowStats['total'], 2); ?></strong></div>
        <div class="stat-row"><span>Source</span><strong><?php echo htmlspecialchars($source['source_name']); ?></strong></div>

        <div class="warn-box">
            ⚠️ This will place holds and initiate real transfers to <?php echo number_format($rowStats['cnt']); ?> destinations.
            This action cannot be undone once processing begins.
        </div>

        <form method="POST">
            <input type="hidden" name="batch_id" value="<?php echo $batchId; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            <?php if (!$isHookedSource): ?>
            <div class="form-group">
                <label>Source Wallet PIN</label>
                <input type="password" name="wallet_pin" inputmode="numeric" maxlength="6" required placeholder="••••••">
            </div>
            <?php else: ?>
            <p style="font-size: 12px; color: #64748b; margin-top: 12px;">✓ This source is pre-authorized — no PIN required.</p>
            <?php endif; ?>

            <div class="btn-group">
                <a href="../batches/view.php?id=<?php echo $batchId; ?>" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">Execute Now →</button>
            </div>
        </form>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// ============================================================================
// POST — ACTUAL EXECUTION
// ============================================================================

// CSRF Protection
$csrfToken = $_POST['csrf_token'] ?? null;
requireCsrfToken($csrfToken);

$walletPin = $_POST['wallet_pin'] ?? null;

if (!$isHookedSource && empty($walletPin)) {
    die("Wallet PIN is required to authorize this disbursement.");
}

// ----------------------------------------------------------------------------
// RACE PROTECTION: atomically flip APPROVED -> PROCESSING. If this affects
// zero rows, someone else (or a double-click) already started execution.
// ----------------------------------------------------------------------------
$stmt = $db->prepare("UPDATE import_batches SET status = 'PROCESSING' WHERE id = :id AND status = 'APPROVED'");
$stmt->execute([':id' => $batchId]);
if ($stmt->rowCount() === 0) {
    die("This batch is already being processed or is no longer in APPROVED status. Refresh and check its current state.");
}

// ----------------------------------------------------------------------------
// LOAD VALID ROWS
// ----------------------------------------------------------------------------
$stmt = $db->prepare("
    SELECT * FROM import_rows
    WHERE batch_id = :batch_id AND validation_status = 'VALID'
    ORDER BY row_number
");
$stmt->execute([':batch_id' => $batchId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    $db->prepare("UPDATE import_batches SET status = 'FAILED' WHERE id = :id")->execute([':id' => $batchId]);
    die("No valid rows to execute.");
}

// ----------------------------------------------------------------------------
// SPLIT ROWS: immediate destinations (known wallet/account/voucher) go into
// ONE executeMultiDestinationSwap() call. IDENTITY rows (beneficiary known
// only by National ID/phone/email, no wallet/account on file yet) are a
// fundamentally different flow -- each is a hold-and-wait: SwapService places
// a hold now via initiateSwapToIdentity(), then the RECIPIENT (or an agent
// verifying their physical ID) claims it later via confirmAndFinalizeIdentitySwap(),
// up to 24 hours after. These cannot be bundled into the same synchronous
// destinations[] array because they don't complete immediately.
// ----------------------------------------------------------------------------
$immediateRows = [];
$identityRows = [];
foreach ($rows as $row) {
    if (strtoupper($row['destination_type'] ?? '') === 'IDENTITY') {
        $identityRows[] = $row;
    } else {
        $immediateRows[] = $row;
    }
}

// ----------------------------------------------------------------------------
// BUILD THE SwapService PAYLOAD FOR IMMEDIATE ROWS — one source, many
// destinations, each independently routed to its own institution/delivery
// method (BancABC wallet, Mascom wallet, BancABC cashout voucher, Bank
// Gaborone cashout voucher, etc. can all sit in the same batch).
// ----------------------------------------------------------------------------
$destinations = [];
foreach ($immediateRows as $row) {
    $deliveryMethod = match (strtoupper($row['destination_type'] ?? '')) {
        'WALLET' => 'WALLET',
        'ACCOUNT' => 'DEPOSIT',
        'PHONE' => 'CASHOUT',
        'VOUCHER' => 'VOUCHER',
        default => 'DEPOSIT'
    };

    $destinations[] = [
        'amount' => (float)$row['amount'],
        'currency' => $row['currency'] ?? $batch['currency'] ?? 'BWP',
        'to_institution' => $row['destination_provider'],
        'destination_institution' => $row['destination_provider'],
        'destination_identifier' => $row['destination_value'],
        'destination_identifier_type' => strtolower($row['destination_type'] ?? 'account'),
        'delivery_method' => $deliveryMethod,
        'beneficiary_phone' => $row['recipient_phone'],
    ];
}

$payload = [
    'reference' => $batch['batch_reference'],
    'from_institution' => $source['provider'],
    'source_institution' => $source['provider'],
    'source_identifier' => $source['account_identifier'],
    'asset_type' => $source['asset_type'] ?? 'WALLET',
    'currency' => $batch['currency'] ?? 'BWP',
];

if ($isHookedSource) {
    $payload['_is_hooked'] = true;
    $payload['source_reference'] = $source['source_reference'];

    $stmt = $db->prepare("
        SELECT access_token FROM user_authorized_sources
        WHERE source_reference = :ref AND status = 'active'
    ");
    $stmt->execute([':ref' => $source['source_reference']]);
    $hooked = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($hooked) {
        $payload['access_token'] = $hooked['access_token'];
    }
} else {
    $payload['wallet_pin'] = $walletPin;
    $payload['pin'] = $walletPin;
}

// ----------------------------------------------------------------------------
// CALL THE REAL SwapService — same pattern as public/api/v1/swap/execute.php
// NOTE: executeMultiDestinationSwap() requires 2+ destinations. A batch with
// exactly one immediate destination uses the standard single-swap fields
// instead (destination_institution/destination_identifier directly on the
// payload, not wrapped in a destinations[] array) — otherwise SwapService
// would silently fall through to the wrong code path.
// ----------------------------------------------------------------------------
$startTime = microtime(true);
$result = null;
$executionError = null;
$countryName = $_ENV['VOUCHMORPH_COUNTRY'] ?? getenv('VOUCHMORPH_COUNTRY') ?? 'Botswana';

if (count($destinations) >= 2) {
    $payload['destinations'] = $destinations;
} elseif (count($destinations) === 1) {
    $only = $destinations[0];
    $payload['amount'] = $only['amount'];
    $payload['to_institution'] = $only['to_institution'];
    $payload['destination_institution'] = $only['destination_institution'];
    $payload['destination_identifier'] = $only['destination_identifier'];
    $payload['destination_identifier_type'] = $only['destination_identifier_type'];
    $payload['delivery_method'] = $only['delivery_method'];
    $payload['beneficiary_phone'] = $only['beneficiary_phone'];
    $payload['swap_type'] = in_array($only['delivery_method'], ['CASHOUT', 'VOUCHER', 'AGENT', 'ATM']) ? 'CASHOUT' : 'DEPOSIT';
}

if (!empty($destinations)) {
    try {
        $fullCountryConfig = LoadCountry::getConfig();
        $swapService = new SwapService($db, $fullCountryConfig, $countryName);
        $result = $swapService->executeAtomicSwap($payload);
    } catch (Throwable $e) {
        $executionError = $e->getMessage();
        error_log("[enterprise/execute.php] SwapService execution failed for batch {$batchId}: " . $e->getMessage());
    }
}

$executionSeconds = round(microtime(true) - $startTime, 2);

// ----------------------------------------------------------------------------
// PROCESS IMMEDIATE-ROW RESULTS — write per-destination outcomes back to
// payment_instructions. Mapped against $immediateRows (NOT the full $rows
// array) since identity rows never entered the destinations[] payload.
// ----------------------------------------------------------------------------
$successCount = 0;
$failedCount = 0;
$totalDelivered = 0;
$totalFees = 0;
$settlementReference = null;

if (empty($destinations)) {
    // Nothing immediate to process — batch was 100% identity rows
} elseif ($result && isset($result['destinations']) && is_array($result['destinations'])) {
    // Multi-destination path (2+ rows)
    $settlementReference = $result['reference'] ?? $batch['batch_reference'];

    foreach ($result['destinations'] as $destResult) {
        $idx = $destResult['index'] ?? null;
        $row = ($idx !== null && isset($immediateRows[$idx])) ? $immediateRows[$idx] : null;
        if (!$row) continue;

        $isSuccess = ($destResult['status'] ?? '') === 'success';
        if ($isSuccess) {
            $successCount++;
            $totalDelivered += (float)($destResult['deliverable_amount'] ?? $destResult['requested_amount'] ?? 0);
            $totalFees += (float)($destResult['fee'] ?? 0);
        } else {
            $failedCount++;
        }

        $stmt = $db->prepare("
            INSERT INTO payment_instructions (
                organization_id, batch_id, import_row_id, source_type, source_id,
                destination_type, destination_provider, destination_value,
                recipient_name, recipient_phone, amount, currency, swap_reference, status
            ) VALUES (
                :org_id, :batch_id, :row_id, 'organization_wallet', :source_id,
                :dest_type, :dest_provider, :dest_value, :name, :phone,
                :amount, :currency, :swap_ref, :status
            )
        ");
        $stmt->execute([
            ':org_id' => $orgId,
            ':batch_id' => $batchId,
            ':row_id' => $row['id'],
            ':source_id' => $batch['source_id'],
            ':dest_type' => $row['destination_type'],
            ':dest_provider' => $row['destination_provider'],
            ':dest_value' => $row['destination_value'],
            ':name' => $row['recipient_name'],
            ':phone' => $row['recipient_phone'],
            ':amount' => $row['amount'],
            ':currency' => $row['currency'] ?? $batch['currency'] ?? 'BWP',
            ':swap_ref' => $destResult['sub_reference'] ?? $destResult['transaction_reference'] ?? null,
            ':status' => $isSuccess ? 'SUCCESS' : 'FAILED',
        ]);
    }
} elseif ($result && count($destinations) === 1 && ($result['status'] ?? '') === 'success') {
    // Single-destination standard swap path — result has no 'destinations' key
    $row = $immediateRows[0];
    $successCount = 1;
    $totalDelivered += (float)($result['amount'] ?? $row['amount']);
    $totalFees += (float)($result['fee'] ?? 0);
    $settlementReference = $result['reference'] ?? $batch['batch_reference'];

    $stmt = $db->prepare("
        INSERT INTO payment_instructions (
            organization_id, batch_id, import_row_id, source_type, source_id,
            destination_type, destination_provider, destination_value,
            recipient_name, recipient_phone, amount, currency, swap_reference, status
        ) VALUES (
            :org_id, :batch_id, :row_id, 'organization_wallet', :source_id,
            :dest_type, :dest_provider, :dest_value, :name, :phone,
            :amount, :currency, :swap_ref, 'SUCCESS'
        )
    ");
    $stmt->execute([
        ':org_id' => $orgId,
        ':batch_id' => $batchId,
        ':row_id' => $row['id'],
        ':source_id' => $batch['source_id'],
        ':dest_type' => $row['destination_type'],
        ':dest_provider' => $row['destination_provider'],
        ':dest_value' => $row['destination_value'],
        ':name' => $row['recipient_name'],
        ':phone' => $row['recipient_phone'],
        ':amount' => $row['amount'],
        ':currency' => $row['currency'] ?? $batch['currency'] ?? 'BWP',
        ':swap_ref' => $result['reference'] ?? null,
    ]);
} elseif (!empty($destinations)) {
    // executeAtomicSwap threw, or returned an unexpected shape — treat every
    // IMMEDIATE row as failed rather than silently reporting success.
    // (Identity rows are untouched here — handled separately below.)
    foreach ($immediateRows as $row) {
        $failedCount++;
        $stmt = $db->prepare("
            INSERT INTO payment_instructions (
                organization_id, batch_id, import_row_id, source_type, source_id,
                destination_type, destination_provider, destination_value,
                recipient_name, recipient_phone, amount, currency, swap_reference, status
            ) VALUES (
                :org_id, :batch_id, :row_id, 'organization_wallet', :source_id,
                :dest_type, :dest_provider, :dest_value, :name, :phone,
                :amount, :currency, NULL, 'FAILED'
            )
        ");
        $stmt->execute([
            ':org_id' => $orgId,
            ':batch_id' => $batchId,
            ':row_id' => $row['id'],
            ':source_id' => $batch['source_id'],
            ':dest_type' => $row['destination_type'],
            ':dest_provider' => $row['destination_provider'],
            ':dest_value' => $row['destination_value'],
            ':name' => $row['recipient_name'],
            ':phone' => $row['recipient_phone'],
            ':amount' => $row['amount'],
            ':currency' => $row['currency'] ?? $batch['currency'] ?? 'BWP',
        ]);
    }
}

// ----------------------------------------------------------------------------
// PROCESS IDENTITY ROWS — each placed as its OWN hold via initiateSwapToIdentity().
// These do NOT complete now. The recipient (or an agent verifying their
// physical National ID) must claim it later via the identity-confirmation
// flow (confirmAndFinalizeIdentitySwap), within the 24-hour hold window.
// ----------------------------------------------------------------------------
$pendingIdentityCount = 0;

foreach ($identityRows as $row) {
    $identityPayload = [
        'reference' => $batch['batch_reference'] . '_ID_' . $row['id'],
        'from_institution' => $source['provider'],
        'source_institution' => $source['provider'],
        'source_identifier' => $source['account_identifier'],
        'asset_type' => $source['asset_type'] ?? 'WALLET',
        'amount' => (float)$row['amount'],
        'currency' => $row['currency'] ?? $batch['currency'] ?? 'BWP',
        'identity_type' => strtolower($row['identity_type'] ?? 'national_id'),
        'identity_value' => $row['destination_value'],
        'user_id' => $orgId,
    ];

    if ($isHookedSource) {
        $identityPayload['_is_hooked'] = true;
        $identityPayload['source_reference'] = $source['source_reference'];
        if (!empty($payload['access_token'])) {
            $identityPayload['access_token'] = $payload['access_token'];
        }
    } else {
        $identityPayload['wallet_pin'] = $walletPin;
        $identityPayload['pin'] = $walletPin;
    }

    $swapStatus = 'FAILED';
    $swapRef = null;

    try {
        if (!isset($swapService)) {
            $fullCountryConfig = LoadCountry::getConfig();
            $swapService = new SwapService($db, $fullCountryConfig, $countryName);
        }
        $identityResult = $swapService->initiateSwapToIdentity($identityPayload);
        if (($identityResult['status'] ?? '') === 'pending_identity_confirmation') {
            $swapStatus = 'PENDING_IDENTITY';
            $swapRef = $identityResult['swap_reference'] ?? null;
            $pendingIdentityCount++;
        }
    } catch (Throwable $e) {
        error_log("[enterprise/execute.php] initiateSwapToIdentity failed for row {$row['id']}: " . $e->getMessage());
    }

    $stmt = $db->prepare("
        INSERT INTO payment_instructions (
            organization_id, batch_id, import_row_id, source_type, source_id,
            destination_type, destination_provider, destination_value,
            recipient_name, recipient_phone, amount, currency, swap_reference, status
        ) VALUES (
            :org_id, :batch_id, :row_id, 'organization_wallet', :source_id,
            'IDENTITY', NULL, :dest_value, :name, :phone,
            :amount, :currency, :swap_ref, :status
        )
    ");
    $stmt->execute([
        ':org_id' => $orgId,
        ':batch_id' => $batchId,
        ':row_id' => $row['id'],
        ':source_id' => $batch['source_id'],
        ':dest_value' => $row['destination_value'],
        ':name' => $row['recipient_name'],
        ':phone' => $row['recipient_phone'],
        ':amount' => $row['amount'],
        ':currency' => $row['currency'] ?? $batch['currency'] ?? 'BWP',
        ':swap_ref' => $swapRef,
        ':status' => $swapStatus,
    ]);

    if ($swapStatus === 'FAILED') {
        $failedCount++;
    }
}

// ----------------------------------------------------------------------------
// UPDATE BATCH STATUS — now accounts for rows still awaiting identity claim
// ----------------------------------------------------------------------------
$finalStatus = $executionError
    ? 'FAILED'
    : ($pendingIdentityCount > 0 && $failedCount === 0 && count($rows) === $pendingIdentityCount + $successCount
        ? 'PARTIALLY_PENDING_IDENTITY'
        : ($failedCount === 0 ? 'COMPLETED' : ($successCount > 0 || $pendingIdentityCount > 0 ? 'PARTIAL' : 'FAILED')));

$stmt = $db->prepare("
    UPDATE import_batches
    SET status = :status, successful_count = :success, failed_count = :failed,
        pending_count = :pending, completed_at = NOW()
    WHERE id = :id
");
$stmt->execute([
    ':status' => $finalStatus,
    ':success' => $successCount,
    ':failed' => $failedCount,
    ':pending' => $pendingIdentityCount,
    ':id' => $batchId,
]);

// ----------------------------------------------------------------------------
// RECONCILIATION SUMMARY
// ----------------------------------------------------------------------------
$stmt = $db->prepare("
    INSERT INTO batch_execution_summary (
        batch_id, total_instructions, successful, failed, pending,
        total_amount_sent, total_fees, total_fx_applied, execution_time_seconds,
        settlement_reference, reconciliation_status, reconciliation_report, created_at
    ) VALUES (
        :batch_id, :total, :success, :failed, :pending,
        :amount_sent, :fees, :fx, :exec_time,
        :settlement_ref, :recon_status, :recon_report, NOW()
    )
");
$stmt->execute([
    ':batch_id' => $batchId,
    ':total' => count($rows),
    ':success' => $successCount,
    ':failed' => $failedCount,
    ':pending' => $pendingIdentityCount,
    ':amount_sent' => $totalDelivered,
    ':fees' => $totalFees,
    ':fx' => ($result['forex_applied'] ?? false) ? 1 : 0,
    ':exec_time' => $executionSeconds,
    ':settlement_ref' => $settlementReference,
    ':recon_status' => $executionError ? 'ERROR' : ($finalStatus === 'COMPLETED' ? 'RECONCILED' : 'NEEDS_REVIEW'),
    ':recon_report' => json_encode([
        'execution_error' => $executionError,
        'swap_service_result' => $result,
    ]),
]);

// ----------------------------------------------------------------------------
// AUDIT LOG
// ----------------------------------------------------------------------------
$stmt = $db->prepare("
    INSERT INTO organization_audit_logs (
        organization_id, user_id, action, entity_type, entity_id,
        old_values, new_values, ip_address, user_agent, created_at
    ) VALUES (
        :org_id, :user_id, 'BATCH_EXECUTED', 'import_batch', :entity_id,
        :old_values, :new_values, :ip, :ua, NOW()
    )
");
$stmt->execute([
    ':org_id' => $orgId,
    ':user_id' => $user['id'] ?? $user['user_id'] ?? null,
    ':entity_id' => $batchId,
    ':old_values' => json_encode(['status' => 'APPROVED']),
    ':new_values' => json_encode(['status' => $finalStatus, 'successful' => $successCount, 'failed' => $failedCount]),
    ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
]);

header("Location: ../batches/view.php?id={$batchId}&success=1");
exit;
