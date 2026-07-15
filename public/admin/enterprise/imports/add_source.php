<?php
// enterprise/imports/add_source.php - Add source account for disbursement
require_once '../auth.php';
$user = requireEnterpriseAuth();
require_once '../../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

$db = DBConnection::getConnection();
$orgId = getOrganizationId();
$userId = $user['id'] ?? $user['user_id'] ?? null;

$error = '';
$success = '';
$sourceId = null;

// Get existing sources for dropdown
$sources = [];
$stmt = $db->prepare("
    SELECT * FROM source_accounts 
    WHERE organization_id = :org_id AND is_active = true
    ORDER BY institution, source_identifier
");
$stmt->execute([':org_id' => $orgId]);
$sources = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get participants from config
$participants = [];
try {
    // Load participants from YAML
    $configPath = __DIR__ . '/../../../../src/Core/Config/Countries/Botswana/participants.yaml';
    if (file_exists($configPath)) {
        $content = file_get_contents($configPath);
        // Simple YAML parsing for participants
        preg_match_all('/^  ([A-Z_]+):$/m', $content, $matches);
        $participants = $matches[1] ?? [];
    }
} catch (Exception $e) {
    error_log("[add_source] Error loading participants: " . $e->getMessage());
}
// Fallback participants if YAML not found
if (empty($participants)) {
    $participants = ['ZURUBANK', 'SACCUSSALIS', 'CAZACOM', 'VOUCHMORPH'];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_source') {
        $institution = trim($_POST['institution'] ?? '');
        $assetType = trim($_POST['asset_type'] ?? 'ACCOUNT');
        $sourceIdentifier = trim($_POST['source_identifier'] ?? '');
        $identifierType = trim($_POST['identifier_type'] ?? 'account_number');
        $accountName = trim($_POST['account_name'] ?? '');
        $currency = trim($_POST['currency'] ?? 'BWP');
        $balance = floatval($_POST['balance'] ?? 0);
        $isHooked = isset($_POST['is_hooked']) ? 1 : 0;
        $accessToken = trim($_POST['access_token'] ?? '');
        $refreshToken = trim($_POST['refresh_token'] ?? '');
        $tokenExpiry = !empty($_POST['token_expiry']) ? $_POST['token_expiry'] : null;
        
        // Validate
        if (empty($institution)) {
            $error = 'Institution is required.';
        } elseif (empty($sourceIdentifier)) {
            $error = 'Source identifier (account number/phone) is required.';
        } elseif ($balance < 0) {
            $error = 'Balance cannot be negative.';
        } else {
            try {
                // Check if source already exists
                $stmt = $db->prepare("
                    SELECT id FROM source_accounts 
                    WHERE organization_id = :org_id 
                    AND institution = :institution 
                    AND source_identifier = :identifier
                ");
                $stmt->execute([
                    ':org_id' => $orgId,
                    ':institution' => $institution,
                    ':identifier' => $sourceIdentifier
                ]);
                
                if ($stmt->fetch()) {
                    $error = 'This source already exists for your organization.';
                } else {
                    // Insert new source
                    $stmt = $db->prepare("
                        INSERT INTO source_accounts (
                            organization_id, institution, asset_type,
                            source_identifier, source_identifier_type,
                            account_name, currency, balance,
                            is_hooked, access_token, refresh_token,
                            token_expires_at, is_active, created_by,
                            created_at, updated_at
                        ) VALUES (
                            :org_id, :institution, :asset_type,
                            :identifier, :identifier_type,
                            :account_name, :currency, :balance,
                            :is_hooked, :access_token, :refresh_token,
                            :token_expiry, true, :user_id,
                            NOW(), NOW()
                        ) RETURNING id
                    ");
                    $stmt->execute([
                        ':org_id' => $orgId,
                        ':institution' => $institution,
                        ':asset_type' => $assetType,
                        ':identifier' => $sourceIdentifier,
                        ':identifier_type' => $identifierType,
                        ':account_name' => $accountName,
                        ':currency' => $currency,
                        ':balance' => $balance,
                        ':is_hooked' => $isHooked,
                        ':access_token' => $accessToken,
                        ':refresh_token' => $refreshToken,
                        ':token_expiry' => $tokenExpiry,
                        ':user_id' => $userId
                    ]);
                    
                    $sourceId = $db->lastInsertId();
                    $success = "Source account added successfully!";
                    
                    // Log audit
                    try {
                        $auditStmt = $db->prepare("
                            INSERT INTO organization_audit_logs (
                                organization_id, user_id, action, entity_type,
                                entity_id, new_values, ip_address, user_agent,
                                created_at
                            ) VALUES (
                                :org_id, :user_id, 'SOURCE_ADDED', 'source_account',
                                :entity_id, :new_values, :ip, :ua, NOW()
                            )
                        ");
                        $auditStmt->execute([
                            ':org_id' => $orgId,
                            ':user_id' => $userId,
                            ':entity_id' => $sourceId,
                            ':new_values' => json_encode([
                                'institution' => $institution,
                                'asset_type' => $assetType,
                                'source_identifier' => $sourceIdentifier,
                                'account_name' => $accountName,
                                'currency' => $currency,
                                'balance' => $balance,
                                'is_hooked' => $isHooked
                            ]),
                            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null
                        ]);
                    } catch (PDOException $e) {
                        error_log("[add_source] Audit log failed: " . $e->getMessage());
                    }
                }
            } catch (PDOException $e) {
                error_log("[add_source] Error: " . $e->getMessage());
                $error = "Database error: " . $e->getMessage();
            }
        }
    } elseif ($action === 'delete_source') {
        $sourceId = (int)($_POST['source_id'] ?? 0);
        
        if ($sourceId) {
            $stmt = $db->prepare("
                UPDATE source_accounts 
                SET is_active = false, deleted_at = NOW(), updated_at = NOW()
                WHERE id = :id AND organization_id = :org_id
            ");
            $stmt->execute([':id' => $sourceId, ':org_id' => $orgId]);
            $success = "Source account deleted successfully.";
        }
    } elseif ($action === 'refresh_token') {
        $sourceId = (int)($_POST['source_id'] ?? 0);
        
        if ($sourceId) {
            // Get source
            $stmt = $db->prepare("
                SELECT * FROM source_accounts 
                WHERE id = :id AND organization_id = :org_id
            ");
            $stmt->execute([':id' => $sourceId, ':org_id' => $orgId]);
            $source = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($source && $source['is_hooked']) {
                // TODO: Implement token refresh logic
                // For now, just update expiry
                $stmt = $db->prepare("
                    UPDATE source_accounts 
                    SET token_expires_at = NOW() + INTERVAL '1 hour',
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([':id' => $sourceId]);
                $success = "Token refreshed successfully.";
            } else {
                $error = "Source is not hooked or not found.";
            }
        }
    }
}

$csrfToken = generateCsrfToken();
$roleDisplay = strtoupper($user['role'] ?? 'USER');
$orgName = htmlspecialchars($user['organization_name'] ?? 'ORGANIZATIONAL');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Source Account · VouchMorph Enterprise</title>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --paper: #EEF1EF;
            --panel: #FFFFFF;
            --ink-900: #0F2138;
            --ink-700: #1D3557;
            --ink-500: #4A5A6E;
            --ink-300: #8A96A3;
            --line: #D3DAD6;
            --brass: #8A6D3B;
            --brass-tint: #F4EFE3;
            --seal-red: #7A2118;
            --ledger-green: #24513A;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'IBM Plex Sans', sans-serif;
            background: var(--paper);
            color: var(--ink-900);
            min-height: 100vh;
        }
        .masthead {
            background: var(--ink-900);
            color: white;
            padding: 14px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 3px solid var(--brass);
            flex-wrap: wrap;
            gap: 10px;
        }
        .masthead h1 { font-size: 18px; font-weight: 700; }
        .masthead .role-pill {
            font-size: 10px;
            font-weight: 700;
            color: var(--brass);
            border: 1px solid var(--brass);
            padding: 2px 10px;
            text-transform: uppercase;
        }
        .stage { max-width: 800px; margin: 0 auto; padding: 30px 20px; }
        .card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 20px;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--line);
            flex-wrap: wrap;
            gap: 10px;
        }
        .card-title { font-size: 16px; font-weight: 700; text-transform: uppercase; }
        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--ink-500);
            margin-bottom: 4px;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid var(--line);
            border-radius: 8px;
            font-size: 13px;
            font-family: inherit;
            background: #fff;
        }
        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: var(--brass);
        }
        .form-group .help {
            font-size: 11px;
            color: var(--ink-300);
            margin-top: 4px;
        }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
        .btn {
            padding: 10px 24px;
            border: none;
            border-radius: 30px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.15s;
            font-family: inherit;
        }
        .btn-primary { background: var(--ink-900); color: white; }
        .btn-primary:hover { background: var(--brass); }
        .btn-success { background: var(--ledger-green); color: white; }
        .btn-success:hover { background: #1a3d2c; }
        .btn-secondary { background: var(--line); color: var(--ink-700); }
        .btn-secondary:hover { background: var(--line-strong); }
        .btn-danger { background: var(--seal-red); color: white; }
        .btn-danger:hover { background: #5a1812; }
        .btn-outline { background: transparent; border: 2px solid var(--line); }
        .btn-outline:hover { border-color: var(--brass); }
        .btn-sm { padding: 6px 14px; font-size: 11px; }
        .error {
            background: #fbeceb;
            color: var(--seal-red);
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            border-left: 3px solid var(--seal-red);
        }
        .success {
            background: #dcfce7;
            color: #166534;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            border-left: 3px solid #10b981;
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--ink-500);
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 16px;
        }
        .back-link:hover { color: var(--brass); }
        .source-list table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .source-list th {
            background: var(--ink-900);
            color: white;
            padding: 10px;
            text-align: left;
        }
        .source-list td {
            padding: 10px;
            border-bottom: 1px solid var(--line);
        }
        .source-list tr:hover { background: var(--brass-tint); }
        .status-badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        .status-active { background: #dcfce7; color: #166534; }
        .status-inactive { background: #fbeceb; color: var(--seal-red); }
        .hooked-badge {
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
            background: #dbeafe;
            color: #1e40af;
        }
        .actions-bar {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 16px;
        }
        @media (max-width: 768px) {
            .grid-2, .grid-3 { grid-template-columns: 1fr; }
            .masthead { flex-direction: column; text-align: center; }
        }
    </style>
</head>
<body>
    <div class="masthead">
        <h1>VouchMorph · Source Accounts</h1>
        <div>
            <span class="role-pill"><?php echo $roleDisplay; ?></span>
            <span style="color:var(--ink-300); font-size:12px; margin-left:12px;">
                <?php echo htmlspecialchars($orgName); ?>
            </span>
            <a href="../logout.php" style="color: rgba(255,255,255,0.4); text-decoration: none; margin-left: 16px; font-size: 12px;">Logout</a>
        </div>
    </div>

    <div class="stage">
        <a href="source_input.php" class="back-link">← Back to Source Selection</a>

        <?php if ($error): ?>
        <div class="error">⚠️ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
        <div class="success">✅ <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <!-- Add Source Form -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">💰 Add Source Account</span>
                <span style="font-size:11px; color:var(--ink-300);">Source accounts are used for disbursements</span>
            </div>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="add_source">

                <div class="grid-2">
                    <div class="form-group">
                        <label>Institution *</label>
                        <select name="institution" required>
                            <option value="">Select institution</option>
                            <?php foreach ($participants as $p): ?>
                            <option value="<?php echo htmlspecialchars($p); ?>"><?php echo htmlspecialchars($p); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="help">The financial institution hosting the source account</div>
                    </div>

                    <div class="form-group">
                        <label>Asset Type</label>
                        <select name="asset_type">
                            <option value="ACCOUNT">Bank Account</option>
                            <option value="WALLET">Wallet</option>
                            <option value="BANK-WALLET">Bank Wallet</option>
                            <option value="CARD">Payment Card</option>
                            <option value="VOUCHER">Voucher</option>
                        </select>
                        <div class="help">Type of asset to use as source</div>
                    </div>

                    <div class="form-group">
                        <label>Source Identifier *</label>
                        <input type="text" name="source_identifier" required 
                               placeholder="e.g., 10000001, +26770000000">
                        <div class="help">Account number, phone number, or wallet ID</div>
                    </div>

                    <div class="form-group">
                        <label>Identifier Type</label>
                        <select name="identifier_type">
                            <option value="account_number">Account Number</option>
                            <option value="phone">Phone Number</option>
                            <option value="email">Email</option>
                            <option value="national_id">National ID</option>
                            <option value="wallet_id">Wallet ID</option>
                        </select>
                        <div class="help">Type of identifier used</div>
                    </div>

                    <div class="form-group">
                        <label>Account Name</label>
                        <input type="text" name="account_name" placeholder="e.g., Saccussalis Main Account">
                        <div class="help">Display name for the source account</div>
                    </div>

                    <div class="form-group">
                        <label>Currency</label>
                        <select name="currency">
                            <option value="BWP">BWP - Botswana Pula</option>
                            <option value="ZAR">ZAR - South African Rand</option>
                            <option value="USD">USD - US Dollar</option>
                            <option value="EUR">EUR - Euro</option>
                        </select>
                        <div class="help">Currency of the source account</div>
                    </div>

                    <div class="form-group">
                        <label>Balance</label>
                        <input type="number" name="balance" step="0.01" min="0" 
                               placeholder="0.00" value="0">
                        <div class="help">Current balance (optional, for reference)</div>
                    </div>

                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 8px; text-transform: none; font-weight: 400;">
                            <input type="checkbox" name="is_hooked" value="1">
                            🔗 Hooked Source (OAuth/Token based)
                        </label>
                        <div class="help">Check if this source uses OAuth or token-based authentication</div>
                    </div>
                </div>

                <div class="grid-2" id="tokenFields" style="display: none;">
                    <div class="form-group">
                        <label>Access Token</label>
                        <input type="text" name="access_token" placeholder="eyJhbGciOiJIUzI1NiIs...">
                        <div class="help">OAuth access token (if hooked)</div>
                    </div>
                    <div class="form-group">
                        <label>Refresh Token</label>
                        <input type="text" name="refresh_token" placeholder="Refresh token (if hooked)">
                        <div class="help">OAuth refresh token</div>
                    </div>
                    <div class="form-group">
                        <label>Token Expiry</label>
                        <input type="datetime-local" name="token_expiry">
                        <div class="help">When the token expires</div>
                    </div>
                </div>

                <div class="actions-bar">
                    <button type="submit" class="btn btn-primary">➕ Add Source Account</button>
                    <a href="source_input.php" class="btn btn-secondary">← Back to Selection</a>
                </div>
            </form>
        </div>

        <!-- Source List -->
        <div class="card source-list">
            <div class="card-header">
                <span class="card-title">📋 Your Source Accounts</span>
                <span class="btn btn-secondary btn-sm" onclick="refreshList()">🔄 Refresh</span>
            </div>
            <?php if (empty($sources)): ?>
            <div style="padding: 30px; text-align: center; color: var(--ink-300);">
                <p style="font-size: 20px; margin-bottom: 8px;">📭</p>
                <p>No source accounts configured yet.</p>
                <p style="font-size: 12px; margin-top: 4px;">Add a source account above to get started.</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Institution</th>
                            <th>Identifier</th>
                            <th>Asset Type</th>
                            <th>Balance</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sources as $source): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($source['institution']); ?></strong></td>
                            <td><?php echo htmlspecialchars($source['source_identifier']); ?></td>
                            <td><?php echo htmlspecialchars($source['asset_type']); ?></td>
                            <td>
                                <?php echo number_format($source['balance'] ?? 0, 2); ?> 
                                <?php echo htmlspecialchars($source['currency'] ?? 'BWP'); ?>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo $source['is_active'] ? 'active' : 'inactive'; ?>">
                                    <?php echo $source['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                                <?php if ($source['is_hooked']): ?>
                                <span class="hooked-badge">🔗 Hooked</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                    <?php if ($source['is_active']): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                        <input type="hidden" name="action" value="delete_source">
                                        <input type="hidden" name="source_id" value="<?php echo $source['id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Delete this source?')">Delete</button>
                                    </form>
                                    <?php endif; ?>
                                    <?php if ($source['is_hooked']): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                        <input type="hidden" name="action" value="refresh_token">
                                        <input type="hidden" name="source_id" value="<?php echo $source['id']; ?>">
                                        <button type="submit" class="btn btn-secondary btn-sm">🔄 Refresh Token</button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Toggle token fields when hooked checkbox is checked
        document.querySelector('input[name="is_hooked"]').addEventListener('change', function() {
            document.getElementById('tokenFields').style.display = this.checked ? 'grid' : 'none';
        });

        function refreshList() {
            location.reload();
        }

        // Auto-fill example for Saccussalis
        document.querySelector('select[name="institution"]')?.addEventListener('change', function() {
            if (this.value === 'SACCUSSALIS') {
                const identifier = document.querySelector('input[name="source_identifier"]');
                const accountName = document.querySelector('input[name="account_name"]');
                if (!identifier.value) {
                    identifier.value = '10000001';
                }
                if (!accountName.value) {
                    accountName.value = 'Saccussalis Main Account';
                }
            }
        });
    </script>
</body>
</html>
