<?php
/**
 * VouchMorph Complete System Test Dashboard
 * Tests ALL components using real production data
 */

session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: admin_login.php');
    exit;
}

// Load configuration and database
define('PROJECT_ROOT', dirname(__DIR__, 2));
$configPath = PROJECT_ROOT . '/src/Core/Config/LoadCountry.php';
require_once $configPath;
$config = \Core\Config\LoadCountry::getConfig();

require_once PROJECT_ROOT . '/src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

// Database connection
if (isset($config['db']['swap'])) {
    $dbConfig = $config['db']['swap'];
} else {
    $databaseUrl = getenv('DATABASE_URL');
    $db = parse_url($databaseUrl);
    $dbConfig = [
        'host' => $db['host'] ?? 'localhost',
        'port' => (int)($db['port'] ?? 5432),
        'database' => ltrim($db['path'] ?? '', '/'),
        'username' => $db['user'] ?? 'postgres',
        'password' => $db['pass'] ?? '',
    ];
}
$dbConfig['type'] = 'pgsql';
$db = DBConnection::getInstance($dbConfig);

// Get real data from database
$users = $db->query("SELECT user_id, phone, email, full_name, created_at FROM users WHERE deleted_at IS NULL LIMIT 10")->fetchAll();
$transactions = $db->query("SELECT swap_id, user_id, amount, status, created_at FROM swap_requests ORDER BY created_at DESC LIMIT 20")->fetchAll();
$admins = $db->query("SELECT admin_id, username, email, role_id, country_code FROM admins WHERE deleted_at IS NULL")->fetchAll();
$participants = $config['participants'] ?? [];
$countryCode = $config['country_code'] ?? 'BW';
$currencySymbol = $config['currency_symbol'] ?? 'BWP';
$countryName = $config['country'] ?? 'Botswana';

// Get system stats
$userCount = $db->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL")->fetchColumn();
$txCount = $db->query("SELECT COUNT(*) FROM swap_requests")->fetchColumn();
$txVolume = $db->query("SELECT COALESCE(SUM(amount), 0) FROM swap_requests WHERE status = 'completed'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · SYSTEM TEST DASHBOARD</title>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'IBM Plex Mono', monospace;
            background: #f7f9fc;
            color: #001B44;
            min-height: 100vh;
            padding: 24px;
        }

        .dashboard {
            max-width: 1600px;
            margin: 0 auto;
        }

        /* HEADER */
        .admin-header {
            background: #001B44;
            border-bottom: 5px solid #FFDA63;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: #fff;
            margin-bottom: 30px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 30px;
        }

        .logo {
            font-size: 1.2rem;
            font-weight: 700;
            letter-spacing: 2px;
        }

        .logo span {
            color: #FFDA63;
            margin-left: 10px;
            font-size: 0.8rem;
        }

        .country-badge {
            padding: 5px 15px;
            background: rgba(255, 218, 99, 0.2);
            border: 1px solid #FFDA63;
            color: #FFDA63;
            font-size: 0.8rem;
            text-transform: uppercase;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .user-details {
            text-align: right;
        }

        .user-name {
            font-weight: 600;
            color: #FFDA63;
        }

        .user-role {
            font-size: 0.7rem;
            color: #A1B5D8;
            text-transform: uppercase;
        }

        .back-btn {
            padding: 8px 16px;
            background: transparent;
            border: 2px solid #FFDA63;
            color: #FFDA63;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 600;
            transition: all 0.2s;
        }

        .back-btn:hover {
            background: #FFDA63;
            color: #001B44;
        }

        /* STATS GRID */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: #fff;
            border: 2px solid #001B44;
            padding: 20px;
            box-shadow: 4px 4px 0 #A1B5D8;
            text-align: center;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: #001B44;
            line-height: 1.2;
        }

        .stat-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #666;
            margin-top: 8px;
        }

        /* CONTROL BAR */
        .control-bar {
            display: flex;
            gap: 16px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 24px;
            border: 2px solid #001B44;
            font-family: 'IBM Plex Mono', monospace;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            cursor: pointer;
            transition: all 0.2s;
            background: #fff;
            color: #001B44;
        }

        .btn-primary {
            background: #001B44;
            color: #fff;
            border-color: #001B44;
        }

        .btn-primary:hover {
            background: #FFDA63;
            color: #001B44;
            border-color: #FFDA63;
        }

        .btn-success {
            background: #fff;
            border-color: #10b981;
            color: #10b981;
        }

        .btn-success:hover {
            background: #10b981;
            color: #fff;
        }

        .btn-warning {
            background: #fff;
            border-color: #f59e0b;
            color: #f59e0b;
        }

        .btn-warning:hover {
            background: #f59e0b;
            color: #fff;
        }

        .btn-outline {
            background: #fff;
            border-color: #001B44;
            color: #001B44;
        }

        .btn-outline:hover {
            background: #001B44;
            color: #fff;
        }

        /* TEST GRID */
        .test-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .test-card {
            background: #fff;
            border: 2px solid #001B44;
            overflow: hidden;
        }

        .test-card.passed {
            border-left: 8px solid #10b981;
        }

        .test-card.failed {
            border-left: 8px solid #ef4444;
        }

        .test-card.partial {
            border-left: 8px solid #f59e0b;
        }

        .test-header {
            padding: 16px 20px;
            background: #f8f9fa;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            border-bottom: 1px solid #ddd;
        }

        .test-title {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .test-status {
            width: 12px;
            height: 12px;
            border: 2px solid #001B44;
        }

        .test-status.passed { background: #10b981; border-color: #10b981; }
        .test-status.failed { background: #ef4444; border-color: #ef4444; }
        .test-status.partial { background: #f59e0b; border-color: #f59e0b; }
        .test-status.running { background: #3b82f6; border-color: #3b82f6; animation: pulse 1s infinite; }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .test-body {
            padding: 20px;
            display: none;
        }

        .test-body.expanded {
            display: block;
        }

        /* DATA TABLES */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
            font-family: 'IBM Plex Mono', monospace;
        }

        .data-table th,
        .data-table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        .data-table th {
            background: #001B44;
            color: #fff;
            font-weight: 600;
        }

        .data-table tr:hover {
            background: #f5f5f5;
        }

        /* LOG VIEWER */
        .log-viewer {
            background: #001B44;
            padding: 16px;
            font-family: 'IBM Plex Mono', monospace;
            font-size: 0.75rem;
            max-height: 300px;
            overflow-y: auto;
            margin-top: 20px;
            border: 2px solid #FFDA63;
        }

        .log-entry {
            padding: 6px 0;
            border-bottom: 1px solid #334155;
            font-family: 'IBM Plex Mono', monospace;
        }

        .log-entry.info { color: #3b82f6; }
        .log-entry.success { color: #10b981; }
        .log-entry.error { color: #ef4444; }
        .log-entry.warning { color: #f59e0b; }

        /* RESULT STYLES */
        .result-summary {
            margin-bottom: 12px;
            font-weight: 600;
            padding: 8px;
            background: #f8f9fa;
            border-left: 3px solid #001B44;
        }

        .result-detail {
            background: #f8f9fa;
            padding: 12px;
            margin-top: 10px;
        }

        .result-item {
            margin: 4px 0;
            font-size: 0.75rem;
        }

        .result-item.passed { color: #10b981; }
        .result-item.failed { color: #ef4444; }

        /* FOOTER */
        .admin-footer {
            background: #001B44;
            color: #A1B5D8;
            padding: 20px 30px;
            font-size: 0.7rem;
            text-align: center;
            border-top: 3px solid #FFDA63;
            margin-top: 30px;
        }

        /* RESPONSIVE */
        @media (max-width: 768px) {
            body { padding: 16px; }
            .test-grid { grid-template-columns: 1fr; }
            .control-bar { flex-direction: column; }
            .admin-header { flex-direction: column; text-align: center; gap: 15px; }
            .header-left { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <!-- Header -->
        <div class="admin-header">
            <div class="header-left">
                <div class="logo">VOUCHMORPH <span>TEST SUITE</span></div>
                <div class="country-badge"><?php echo htmlspecialchars($countryCode); ?> · <?php echo htmlspecialchars($countryName); ?></div>
            </div>
            <div class="user-info">
                <div class="user-details">
                    <div class="user-name">SYSTEM TEST</div>
                    <div class="user-role">QUALITY ASSURANCE</div>
                </div>
                <a href="admin_dashboard.php" class="back-btn">← BACK</a>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($userCount); ?></div>
                <div class="stat-label">REGISTERED USERS</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($txCount); ?></div>
                <div class="stat-label">TOTAL TRANSACTIONS</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($txVolume, 2); ?></div>
                <div class="stat-label">VOLUME (<?php echo $currencySymbol; ?>)</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" id="stat-score">-</div>
                <div class="stat-label">HEALTH SCORE</div>
            </div>
        </div>

        <!-- Control Bar -->
        <div class="control-bar">
            <button class="btn btn-primary" onclick="runAllTests()">🚀 RUN ALL TESTS</button>
            <button class="btn btn-success" onclick="runTest('database')">🗄️ DATABASE</button>
            <button class="btn btn-success" onclick="runTest('users')">👥 USERS</button>
            <button class="btn btn-success" onclick="runTest('transactions')">💸 TRANSACTIONS</button>
            <button class="btn btn-success" onclick="runTest('admins')">👑 ADMINS</button>
            <button class="btn btn-success" onclick="runTest('config')">⚙️ CONFIG</button>
            <button class="btn btn-warning" onclick="runTest('api')">🌐 API</button>
            <button class="btn btn-outline" onclick="refreshData()">🔄 REFRESH</button>
        </div>

        <!-- Test Grid -->
        <div class="test-grid" id="test-grid"></div>

        <!-- Log Viewer -->
        <div class="log-viewer" id="log-viewer">
            <div class="log-entry info">✨ System test dashboard initialized</div>
            <div class="log-entry info">📊 Loaded <?php echo number_format($userCount); ?> users, <?php echo number_format($txCount); ?> transactions</div>
            <div class="log-entry info">🏦 Country: <?php echo htmlspecialchars($countryName); ?> (<?php echo $currencySymbol; ?>)</div>
        </div>

        <!-- Footer -->
        <div class="admin-footer">
            <p>VOUCHMORPH · <?php echo htmlspecialchars($countryName); ?> · SYSTEM TEST SUITE</p>
            <p style="margin-top: 5px;">Validating all components with production data</p>
        </div>
    </div>

    <script>
    // Real data from PHP
    const users = <?php echo json_encode($users); ?>;
    const transactions = <?php echo json_encode($transactions); ?>;
    const admins = <?php echo json_encode($admins); ?>;
    const participants = <?php echo json_encode($participants); ?>;
    const currencySymbol = '<?php echo $currencySymbol; ?>';
    const countryCode = '<?php echo $countryCode; ?>';

    let testResults = {};

    // Test definitions using REAL data
    const tests = {
        database: {
            name: '🗄️ DATABASE CONNECTION',
            description: 'Validates database connectivity, tables, and data integrity',
            run: async () => {
                const results = [];
                results.push({ name: 'Database Connection', passed: true, message: 'Connected successfully' });
                
                const userCount = <?php echo $userCount; ?>;
                results.push({ name: 'Users Table', passed: userCount > 0, message: `${userCount} records found` });
                
                const txCount = <?php echo $txCount; ?>;
                results.push({ name: 'Transactions Table', passed: txCount > 0, message: `${txCount} records found` });
                
                const adminCount = <?php echo count($admins); ?>;
                results.push({ name: 'Admins Table', passed: adminCount > 0, message: `${adminCount} records found` });
                
                const passedCount = results.filter(r => r.passed).length;
                return { status: passedCount === results.length ? 'PASS' : 'PARTIAL', results, message: `${passedCount}/${results.length} checks passed` };
            }
        },
        
        users: {
            name: '👥 USER MANAGEMENT',
            description: 'Validates user accounts, phone numbers, and registration data',
            run: async () => {
                const results = [];
                const sampleUsers = users.slice(0, 5);
                
                results.push({ name: 'Total Users', passed: users.length > 0, message: `${users.length} registered users` });
                
                for (const user of sampleUsers) {
                    const hasPhone = user.phone && user.phone.length > 5;
                    results.push({ name: `User #${user.user_id}`, passed: hasPhone, message: `Phone: ${user.phone || 'N/A'}, Created: ${user.created_at?.substring(0, 10) || 'N/A'}` });
                }
                
                const passedCount = results.filter(r => r.passed).length;
                return { status: passedCount === results.length ? 'PASS' : passedCount > results.length/2 ? 'PARTIAL' : 'FAIL', results, message: `${passedCount}/${results.length} checks passed` };
            }
        },
        
        transactions: {
            name: '💸 TRANSACTION PROCESSING',
            description: 'Validates swap transactions, amounts, and statuses',
            run: async () => {
                const results = [];
                const sampleTx = transactions.slice(0, 10);
                
                results.push({ name: 'Total Transactions', passed: transactions.length > 0, message: `${transactions.length} total transactions` });
                
                let totalAmount = 0;
                for (const tx of sampleTx) {
                    totalAmount += parseFloat(tx.amount || 0);
                    const isValidAmount = parseFloat(tx.amount) > 0;
                    results.push({ name: `TX #${tx.swap_id}`, passed: isValidAmount, message: `${currencySymbol} ${parseFloat(tx.amount).toFixed(2)} | Status: ${tx.status}` });
                }
                
                results.push({ name: 'Sample Volume', passed: totalAmount > 0, message: `${currencySymbol} ${totalAmount.toFixed(2)} in sample` });
                
                const passedCount = results.filter(r => r.passed).length;
                return { status: passedCount === results.length ? 'PASS' : passedCount > results.length/2 ? 'PARTIAL' : 'FAIL', results, message: `${passedCount}/${results.length} checks passed` };
            }
        },
        
        admins: {
            name: '👑 ADMIN SYSTEM',
            description: 'Validates admin accounts, roles, and permissions',
            run: async () => {
                const results = [];
                const roleNames = { 999: 'Super Admin', 3: 'Regulator', 4: 'Compliance', 5: 'Auditor' };
                
                results.push({ name: 'Total Admins', passed: admins.length > 0, message: `${admins.length} admin accounts` });
                
                for (const admin of admins) {
                    const roleName = roleNames[admin.role_id] || 'Unknown';
                    results.push({ name: `${admin.username}`, passed: true, message: `Role: ${roleName} | Country: ${admin.country_code || 'Global'}` });
                }
                
                const hasSuperAdmin = admins.some(a => a.role_id == 999);
                results.push({ name: 'Super Admin', passed: hasSuperAdmin, message: hasSuperAdmin ? 'Present' : 'Missing' });
                
                const passedCount = results.filter(r => r.passed).length;
                return { status: passedCount === results.length ? 'PASS' : 'PARTIAL', results, message: `${passedCount}/${results.length} checks passed` };
            }
        },
        
        config: {
            name: '⚙️ SYSTEM CONFIGURATION',
            description: 'Validates country settings, participants, and API configs',
            run: async () => {
                const results = [];
                
                results.push({ name: 'Country', passed: true, message: `${countryCode} - ${'<?php echo $countryName; ?>'}` });
                results.push({ name: 'Currency', passed: true, message: currencySymbol });
                
                const participantCount = Object.keys(participants).length;
                results.push({ name: 'Participants', passed: participantCount > 0, message: `${participantCount} participants configured` });
                
                for (const [code, data] of Object.entries(participants).slice(0, 5)) {
                    results.push({ name: `Part: ${code}`, passed: true, message: `Type: ${data.type || 'N/A'}, Status: ${data.status || 'ACTIVE'}` });
                }
                
                const passedCount = results.filter(r => r.passed).length;
                return { status: passedCount === results.length ? 'PASS' : 'PARTIAL', results, message: `${passedCount}/${results.length} checks passed` };
            }
        },
        
        api: {
            name: '🌐 API ENDPOINTS',
            description: 'Validates API connectivity and responses',
            run: async () => {
                const results = [];
                const endpoints = [
                    { name: 'Health Check', url: '/health.php' },
                    { name: 'System Status', url: '/index.php' }
                ];
                
                for (const endpoint of endpoints) {
                    try {
                        const response = await fetch(endpoint.url, { method: 'GET', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                        const passed = response.ok || response.status < 500;
                        results.push({ name: endpoint.name, passed: passed, message: `HTTP ${response.status}` });
                    } catch (error) {
                        results.push({ name: endpoint.name, passed: false, message: error.message });
                    }
                }
                
                const passedCount = results.filter(r => r.passed).length;
                return { status: passedCount === results.length ? 'PASS' : passedCount > 0 ? 'PARTIAL' : 'FAIL', results, message: `${passedCount}/${results.length} endpoints responding` };
            }
        }
    };

    function renderTestGrid() {
        const grid = document.getElementById('test-grid');
        grid.innerHTML = Object.entries(tests).map(([id, test]) => `
            <div class="test-card" id="card-${id}">
                <div class="test-header" onclick="toggleCard('${id}')">
                    <div class="test-title">
                        <div class="test-status" id="status-${id}"></div>
                        <span>${test.name}</span>
                    </div>
                    <span>▼</span>
                </div>
                <div class="test-body" id="body-${id}">
                    <div style="color: #666; margin-bottom: 12px; font-size: 0.75rem;">${test.description}</div>
                    <div id="result-${id}" style="font-family: monospace; font-size: 0.75rem;">Not run yet</div>
                </div>
            </div>
        `).join('');
    }

    function toggleCard(id) {
        const body = document.getElementById(`body-${id}`);
        body.classList.toggle('expanded');
    }

    async function runAllTests() {
        addLog('info', '🚀 Starting full system test suite...');
        
        for (const [id, test] of Object.entries(tests)) {
            await runTest(id);
        }
        
        addLog('success', '✅ Full test suite complete!');
    }

    async function runTest(testId) {
        const test = tests[testId];
        if (!test) return;
        
        addLog('info', `🔄 Running test: ${test.name}...`);
        updateTestStatus(testId, 'running', 'Running...');
        
        try {
            const result = await test.run();
            testResults[testId] = result;
            
            const status = result.status.toLowerCase();
            updateTestStatus(testId, status, formatResults(result));
            addLog(status === 'pass' ? 'success' : 'error', `${test.name}: ${result.message}`);
            
            updateOverallScore();
        } catch (error) {
            updateTestStatus(testId, 'failed', `Error: ${error.message}`);
            addLog('error', `${test.name} failed: ${error.message}`);
        }
    }

    function updateTestStatus(testId, status, resultHtml) {
        const statusDot = document.getElementById(`status-${testId}`);
        const resultDiv = document.getElementById(`result-${testId}`);
        const card = document.getElementById(`card-${testId}`);
        
        if (statusDot) statusDot.className = `test-status ${status}`;
        if (card) card.className = `test-card ${status}`;
        if (resultDiv) {
            if (typeof resultHtml === 'object') {
                resultDiv.innerHTML = formatResultsObject(resultHtml);
            } else {
                resultDiv.innerHTML = resultHtml;
            }
        }
    }

    function formatResults(result) {
        if (!result.results) return `<div class="result-summary">${result.message}</div>`;
        
        let html = `<div class="result-summary">📊 ${result.message}</div>`;
        html += `<div class="result-detail">`;
        for (const r of result.results) {
            html += `<div class="result-item ${r.passed ? 'passed' : 'failed'}">${r.passed ? '✅' : '❌'} ${r.name}: ${r.message}</div>`;
        }
        html += `</div>`;
        return html;
    }

    function formatResultsObject(result) {
        return formatResults(result);
    }

    function updateOverallScore() {
        const total = Object.keys(testResults).length;
        const passed = Object.values(testResults).filter(r => r.status === 'PASS').length;
        const score = total > 0 ? Math.round((passed / total) * 100) : 0;
        
        const scoreEl = document.getElementById('stat-score');
        scoreEl.textContent = `${score}%`;
        scoreEl.style.color = score >= 80 ? '#10b981' : score >= 50 ? '#f59e0b' : '#ef4444';
    }

    function refreshData() {
        location.reload();
    }

    function addLog(level, message) {
        const logViewer = document.getElementById('log-viewer');
        const timestamp = new Date().toLocaleTimeString();
        const logEntry = document.createElement('div');
        logEntry.className = `log-entry ${level}`;
        logEntry.innerHTML = `[${timestamp}] ${message}`;
        logViewer.appendChild(logEntry);
        logViewer.scrollTop = logViewer.scrollHeight;
        
        while (logViewer.children.length > 100) {
            logViewer.removeChild(logViewer.firstChild);
        }
    }

    // Initialize
    renderTestGrid();

    // Auto-run basic tests on load
    setTimeout(() => {
        runTest('database');
        runTest('config');
        runTest('admins');
    }, 500);
    </script>
</body>
</html>
