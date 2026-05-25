<?php
/**
 * Revolutionary Test Dashboard
 * 
 * Real-time ISO20022/8583 compliant testing dashboard
 * FNB-grade user interface
 */

session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: admin_login.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VouchMorph Revolutionary Test Dashboard | FNB Standards</title>
    <style>
        :root {
            --fnb-blue: #1a3b5c;
            --fnb-gold: #c8a13a;
            --success: #10b981;
            --warning: #f59e0b;
            --error: #ef4444;
            --bg-dark: #0f172a;
            --bg-card: #1e293b;
            --border: #334155;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-dark);
            color: #e2e8f0;
            padding: 24px;
        }
        
        .dashboard {
            max-width: 1600px;
            margin: 0 auto;
        }
        
        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
            padding-bottom: 24px;
            border-bottom: 2px solid var(--border);
        }
        
        .logo h1 {
            font-size: 28px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .logo p {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 4px;
        }
        
        .fnb-badge {
            background: var(--fnb-blue);
            padding: 8px 20px;
            border-radius: 40px;
            border-left: 4px solid var(--fnb-gold);
        }
        
        .fnb-badge span {
            color: var(--fnb-gold);
            font-weight: 600;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }
        
        .stat-card {
            background: var(--bg-card);
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            border: 1px solid var(--border);
            transition: transform 0.2s;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
        }
        
        .stat-value {
            font-size: 36px;
            font-weight: bold;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .stat-label {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 8px;
        }
        
        /* Control Bar */
        .control-bar {
            display: flex;
            gap: 16px;
            margin-bottom: 32px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: #3b82f6;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2563eb;
            transform: translateY(-1px);
        }
        
        .btn-success {
            background: var(--success);
            color: white;
        }
        
        .btn-warning {
            background: var(--warning);
            color: white;
        }
        
        .btn-outline {
            background: transparent;
            border: 1px solid var(--border);
            color: #e2e8f0;
        }
        
        .btn-outline:hover {
            background: var(--bg-card);
        }
        
        /* Test Grid */
        .test-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }
        
        .test-card {
            background: var(--bg-card);
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid var(--border);
            transition: all 0.3s;
        }
        
        .test-card.passed {
            border-left: 4px solid var(--success);
        }
        
        .test-card.failed {
            border-left: 4px solid var(--error);
        }
        
        .test-card.partial {
            border-left: 4px solid var(--warning);
        }
        
        .test-header {
            padding: 16px 20px;
            background: rgba(15, 23, 42, 0.5);
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
        }
        
        .test-title {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 600;
        }
        
        .test-status {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }
        
        .test-status.passed { background: var(--success); box-shadow: 0 0 8px var(--success); }
        .test-status.failed { background: var(--error); box-shadow: 0 0 8px var(--error); }
        .test-status.partial { background: var(--warning); box-shadow: 0 0 8px var(--warning); }
        .test-status.running { background: #3b82f6; animation: pulse 1s infinite; }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .test-body {
            padding: 20px;
            display: none;
            border-top: 1px solid var(--border);
        }
        
        .test-body.expanded {
            display: block;
        }
        
        /* Trace Panel */
        .trace-panel {
            background: var(--bg-card);
            border-radius: 16px;
            margin-top: 32px;
            border: 1px solid var(--border);
        }
        
        .trace-header {
            padding: 16px 20px;
            background: rgba(15, 23, 42, 0.5);
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        
        .trace-input {
            display: flex;
            gap: 12px;
            flex: 1;
            max-width: 500px;
        }
        
        .trace-input input {
            flex: 1;
            padding: 10px 16px;
            background: var(--bg-dark);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: #e2e8f0;
            font-family: monospace;
        }
        
        .trace-content {
            padding: 20px;
            font-family: 'Fira Code', monospace;
            font-size: 13px;
            max-height: 500px;
            overflow-y: auto;
        }
        
        /* Log Viewer */
        .log-viewer {
            background: var(--bg-dark);
            border-radius: 12px;
            padding: 16px;
            font-family: 'Fira Code', monospace;
            font-size: 12px;
            max-height: 300px;
            overflow-y: auto;
            margin-top: 20px;
        }
        
        .log-entry {
            padding: 6px 0;
            border-bottom: 1px solid var(--border);
            font-family: monospace;
        }
        
        .log-entry.info { color: #3b82f6; }
        .log-entry.success { color: var(--success); }
        .log-entry.error { color: var(--error); }
        .log-entry.warning { color: var(--warning); }
        
        /* ISO Message Viewer */
        .iso-message {
            background: var(--bg-dark);
            border-radius: 8px;
            padding: 12px;
            margin-top: 8px;
            font-family: monospace;
            font-size: 11px;
            overflow-x: auto;
        }
        
        /* Loading */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid var(--border);
            border-top-color: #3b82f6;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            body { padding: 16px; }
            .test-grid { grid-template-columns: 1fr; }
            .control-bar { flex-direction: column; }
            .trace-input { max-width: 100%; flex-direction: column; }
        }
        
        /* FNB Compliance Footer */
        .compliance-footer {
            margin-top: 32px;
            padding: 20px;
            background: linear-gradient(135deg, var(--fnb-blue) 0%, #0f172a 100%);
            border-radius: 16px;
            text-align: center;
        }
        
        .compliance-badge {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            padding: 12px 24px;
            background: rgba(255,255,255,0.1);
            border-radius: 40px;
        }
    </style>
</head>
<body>
<div class="dashboard">
    <!-- Header -->
    <div class="header">
        <div class="logo">
            <h1>🔄 VouchMorph | Revolutionary Test Suite</h1>
            <p>ISO20022 · ISO8583 · Mobile Money · FNB Standards</p>
        </div>
        <div class="fnb-badge">
            <span>🏦 FNB COMPLIANCE TESTING</span>
        </div>
    </div>
    
    <!-- Stats -->
    <div class="stats-grid" id="stats-grid">
        <div class="stat-card">
            <div class="stat-value" id="stat-passed">-</div>
            <div class="stat-label">Tests Passed</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" id="stat-total">-</div>
            <div class="stat-label">Total Tests</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" id="stat-score">-</div>
            <div class="stat-label">Compliance Score</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" id="stat-fnb">-</div>
            <div class="stat-label">FNB Status</div>
        </div>
    </div>
    
    <!-- Control Bar -->
    <div class="control-bar">
        <button class="btn btn-primary" onclick="runFullSuite()">
            🚀 Run Full Test Suite
        </button>
        <button class="btn btn-success" onclick="runTest('iso20022')">
            📨 Test ISO20022
        </button>
        <button class="btn btn-success" onclick="runTest('iso8583')">
            💳 Test ISO8583
        </button>
        <button class="btn btn-warning" onclick="runTest('cross_border')">
            🌍 Test Cross-Border
        </button>
        <button class="btn btn-outline" onclick="refreshDashboard()">
            🔄 Refresh
        </button>
    </div>
    
    <!-- Test Grid -->
    <div class="test-grid" id="test-grid">
        <!-- Populated by JavaScript -->
    </div>
    
    <!-- Trace Panel -->
    <div class="trace-panel">
        <div class="trace-header">
            <span>🔍 Transaction Trace (ISO20022 Format)</span>
            <div class="trace-input">
                <input type="text" id="trace-swap-ref" placeholder="Enter Swap Reference...">
                <button class="btn btn-outline" onclick="traceSwap()">Trace</button>
            </div>
        </div>
        <div class="trace-content" id="trace-content">
            <div style="color: #64748b; text-align: center;">Enter a swap reference to trace the full ISO20022 message flow</div>
        </div>
    </div>
    
    <!-- Log Viewer -->
    <div class="log-viewer" id="log-viewer">
        <div class="log-entry info">✨ Revolutionary Test Dashboard ready</div>
        <div class="log-entry info">🏦 FNB Compliance Mode: ACTIVE</div>
        <div class="log-entry info">📨 ISO20022 Message Validation: READY</div>
        <div class="log-entry info">💳 ISO8583 Message Validation: READY</div>
    </div>
    
    <!-- Compliance Footer -->
    <div class="compliance-footer">
        <div class="compliance-badge">
            <span>🏦</span>
            <span>FNB COMPLIANT MESSAGING</span>
            <span>ISO20022 ✅</span>
            <span>ISO8583 ✅</span>
            <span>SWIFT MT103 ✅</span>
        </div>
    </div>
</div>

<script>
    // Test categories
    const testCategories = [
        { id: 'iso20022', name: 'ISO20022 Compliance', icon: '📨', description: 'pacs.008, pacs.002, camt.056 validation' },
        { id: 'iso8583', name: 'ISO8583 Compliance', icon: '💳', description: '0200/0210 authorization, 0400 reversal' },
        { id: 'mobile_money', name: 'Mobile Money', icon: '📱', description: 'FNB Connect / eWallet transfers' },
        { id: 'local_swap', name: 'Local Swap', icon: '🔄', description: 'BWP → BWP (FNB Standard)' },
        { id: 'cross_border', name: 'Cross-Border SWIFT', icon: '🌍', description: 'BWP → ZAR with SWIFT MT103' },
        { id: 'atm_cashout', name: 'ATM Cashout', icon: '🏧', description: 'FNB ATM Network compatibility' },
        { id: 'card_load', name: 'Message Card', icon: '💳', description: 'Message-based card authorization' },
        { id: 'fees', name: 'Fee & VAT', icon: '💰', description: 'Regulatory compliance' },
        { id: 'settlement_finality', name: 'Settlement Finality', icon: '✅', description: 'DvP / PvP finality' },
        { id: 'audit_trace', name: 'Audit Trace', icon: '🔍', description: 'Full transaction audit trail' },
        { id: 'disaster_recovery', name: 'Disaster Recovery', icon: '🔄', description: 'Hold release, idempotency' },
        { id: 'concurrent_performance', name: 'Performance', icon: '⚡', description: 'Concurrent swaps, TPS' }
    ];
    
    let testResults = {};
    
    // Initialize
    document.addEventListener('DOMContentLoaded', () => {
        renderTestGrid();
        loadMetrics();
    });
    
    function renderTestGrid() {
        const grid = document.getElementById('test-grid');
        grid.innerHTML = testCategories.map(cat => `
            <div class="test-card" id="card-${cat.id}">
                <div class="test-header" onclick="toggleCard('${cat.id}')">
                    <div class="test-title">
                        <div class="test-status pending" id="status-${cat.id}"></div>
                        <span>${cat.icon} ${cat.name}</span>
                    </div>
                    <span>▼</span>
                </div>
                <div class="test-body" id="body-${cat.id}">
                    <div style="color: #94a3b8; margin-bottom: 12px;">${cat.description}</div>
                    <div id="result-${cat.id}" style="font-family: monospace; font-size: 13px;">Pending...</div>
                </div>
            </div>
        `).join('');
    }
    
    function toggleCard(id) {
        const body = document.getElementById(`body-${id}`);
        body.classList.toggle('expanded');
    }
    
    async function runFullSuite() {
        addLog('info', '🚀 Starting full revolutionary test suite...');
        
        // Show running state
        testCategories.forEach(cat => {
            updateTestStatus(cat.id, 'running', 'Testing...');
        });
        
        try {
            const response = await fetch('/api/v1/tests/run-full-suite', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' }
            });
            
            const results = await response.json();
            testResults = results;
            
            let passed = 0;
            let total = 0;
            
            for (const [testId, result] of Object.entries(results)) {
                total++;
                const status = result.status === 'PASS' ? 'passed' : (result.status === 'PARTIAL' ? 'partial' : 'failed');
                if (status === 'passed') passed++;
                updateTestStatus(testId, status, formatTestResult(result));
            }
            
            const score = total > 0 ? Math.round((passed / total) * 100) : 0;
            updateStats(passed, total, score);
            
            addLog('success', `✅ Test suite complete: ${passed}/${total} passed (${score}%)`);
            
            // FNB Compliance check
            if (score >= 90) {
                addLog('success', '🏆 EXCEPTIONAL! VouchMorph meets FNB international banking standards.');
            } else if (score >= 70) {
                addLog('warning', '👍 Good! Minor improvements needed for FNB certification.');
            } else {
                addLog('error', '⚠️ Review required to meet FNB standards.');
            }
            
        } catch (error) {
            addLog('error', `❌ Test suite failed: ${error.message}`);
        }
    }
    
    async function runTest(testId) {
        addLog('info', `🔄 Running test: ${testId}...`);
        updateTestStatus(testId, 'running', 'Running...');
        
        try {
            const response = await fetch(`/api/v1/tests/run/${testId}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' }
            });
            
            const result = await response.json();
            const status = result.status === 'PASS' ? 'passed' : (result.status === 'PARTIAL' ? 'partial' : 'failed');
            updateTestStatus(testId, status, formatTestResult(result));
            
            addLog(status === 'passed' ? 'success' : 'error', `${testId}: ${result.message || (status === 'passed' ? 'Passed' : 'Failed')}`);
            
            loadMetrics();
            
        } catch (error) {
            updateTestStatus(testId, 'failed', `Error: ${error.message}`);
            addLog('error', `${testId} failed: ${error.message}`);
        }
    }
    
    function updateTestStatus(testId, status, resultHtml) {
        const statusDot = document.getElementById(`status-${testId}`);
        const resultDiv = document.getElementById(`result-${testId}`);
        const card = document.getElementById(`card-${testId}`);
        
        statusDot.className = `test-status ${status}`;
        card.className = `test-card ${status}`;
        
        if (typeof resultHtml === 'object') {
            resultDiv.innerHTML = formatJsonResult(resultHtml);
        } else {
            resultDiv.innerHTML = resultHtml;
        }
    }
    
    function formatTestResult(result) {
        if (result.status === 'PASS') {
            let html = `<div style="color: #10b981;">✅ ${result.message || 'Test passed'}</div>`;
            if (result.swap_ref) html += `<div>📌 Swap: <code>${result.swap_ref}</code></div>`;
            if (result.duration_ms) html += `<div>⏱️ Duration: ${result.duration_ms}ms</div>`;
            if (result.fx_rate) html += `<div>💱 FX Rate: ${result.fx_rate}</div>`;
            if (result.details) {
                html += '<div style="margin-top: 8px;">';
                for (const [key, detail] of Object.entries(result.details)) {
                    if (detail.passed !== undefined) {
                        html += `<div>${detail.passed ? '✅' : '❌'} ${key}: ${detail.message || (detail.passed ? 'Valid' : 'Invalid')}</div>`;
                    }
                }
                html += '</div>';
            }
            return html;
        } else if (result.status === 'PARTIAL') {
            return `<div style="color: #f59e0b;">⚠️ ${result.message || 'Partial pass - review details'}</div>`;
        } else {
            return `<div style="color: #ef4444;">❌ ${result.error || result.message || 'Test failed'}</div>`;
        }
    }
    
    function formatJsonResult(result) {
        if (result.status === 'PASS') {
            let html = `<div style="color: #10b981;">✅ ${result.message || 'Passed'}</div>`;
            if (result.checks) {
                html += '<div style="margin-top: 8px;">';
                for (const [key, check] of Object.entries(result.checks)) {
                    html += `<div>${check.passed ? '✅' : '❌'} ${key}: ${check.message}</div>`;
                }
                html += '</div>';
            }
            return html;
        }
        return `<div style="color: #ef4444;">${JSON.stringify(result, null, 2)}</div>`;
    }
    
    async function traceSwap() {
        const swapRef = document.getElementById('trace-swap-ref').value.trim();
        if (!swapRef) {
            addLog('error', 'Please enter a swap reference');
            return;
        }
        
        addLog('info', `🔍 Tracing swap: ${swapRef}...`);
        
        try {
            const response = await fetch(`/api/v1/tests/trace/${swapRef}`);
            const trace = await response.json();
            const content = document.getElementById('trace-content');
            
            if (trace.error) {
                content.innerHTML = `<div style="color: #ef4444;">❌ ${trace.error}</div>`;
                addLog('error', trace.error);
                return;
            }
            
            content.innerHTML = formatTrace(trace);
            addLog('success', `✅ Trace complete for ${swapRef}`);
            
        } catch (error) {
            addLog('error', `❌ Trace failed: ${error.message}`);
        }
    }
    
    function formatTrace(trace) {
        let html = '<div style="display: flex; flex-direction: column; gap: 16px;">';
        
        // ISO20022 Message Flow
        html += `
            <div style="background: #0f172a; padding: 16px; border-radius: 12px;">
                <div style="color: #3b82f6; margin-bottom: 12px;">📨 ISO20022 MESSAGE FLOW</div>
                <div style="font-family: monospace; font-size: 12px;">
                    ${trace.iso_messages ? JSON.stringify(trace.iso_messages, null, 2) : 'No ISO20022 messages found'}
                </div>
            </div>
        `;
        
        // Swap Details
        if (trace.swap) {
            html += `
                <div style="background: #0f172a; padding: 16px; border-radius: 12px;">
                    <div style="color: #10b981; margin-bottom: 8px;">📋 SWAP DETAILS</div>
                    <div>Reference: <code>${trace.swap.swap_uuid}</code></div>
                    <div>Status: ${trace.swap.status}</div>
                    <div>Amount: ${trace.swap.amount} ${trace.swap.from_currency}</div>
                    <div>Created: ${trace.swap.created_at}</div>
                </div>
            `;
        }
        
        // Fee Verification
        if (trace.fee_equation) {
            const eq = trace.fee_equation;
            html += `
                <div style="background: #0f172a; padding: 16px; border-radius: 12px;">
                    <div style="color: #f59e0b; margin-bottom: 8px;">💰 FEE VERIFICATION</div>
                    <div>Gross: ${eq.gross}</div>
                    <div>Total Fee: ${eq.total_fee}</div>
                    <div>Net: ${eq.net}</div>
                    <div style="color: ${eq.balanced ? '#10b981' : '#ef4444'}">${eq.balanced ? '✅ Equation balanced' : '❌ Equation unbalanced'}</div>
                </div>
            `;
        }
        
        // Settlement Messages
        if (trace.settlement && trace.settlement.length > 0) {
            html += `
                <div style="background: #0f172a; padding: 16px; border-radius: 12px;">
                    <div style="color: #8b5cf6; margin-bottom: 8px;">📤 SETTLEMENT MESSAGES (${trace.settlement.length})</div>
                    ${trace.settlement.map(s => `<div>${s.message_type}: ${s.source_institution} → ${s.destination_institution} | ${s.amount} ${s.currency} | ${s.status}</div>`).join('')}
                </div>
            `;
        }
        
        html += '</div>';
        return html;
    }
    
    async function loadMetrics() {
        try {
            const response = await fetch('/api/v1/tests/metrics');
            const metrics = await response.json();
            updateStats(metrics.passed || 0, metrics.total || 0, metrics.score || 0);
        } catch (error) {
            console.error('Failed to load metrics:', error);
        }
    }
    
    function updateStats(passed, total, score) {
        document.getElementById('stat-passed').textContent = passed;
        document.getElementById('stat-total').textContent = total;
        document.getElementById('stat-score').textContent = `${score}%`;
        
        const fnbStatus = score >= 90 ? 'APPROVED' : (score >= 70 ? 'REVIEW' : 'FAILED');
        document.getElementById('stat-fnb').textContent = fnbStatus;
        document.getElementById('stat-fnb').style.color = score >= 90 ? '#10b981' : (score >= 70 ? '#f59e0b' : '#ef4444');
    }
    
    function refreshDashboard() {
        loadMetrics();
        addLog('info', '🔄 Dashboard refreshed');
    }
    
    function addLog(level, message) {
        const logViewer = document.getElementById('log-viewer');
        const timestamp = new Date().toLocaleTimeString();
        const logEntry = document.createElement('div');
        logEntry.className = `log-entry ${level}`;
        logEntry.innerHTML = `[${timestamp}] ${message}`;
        logViewer.appendChild(logEntry);
        logViewer.scrollTop = logViewer.scrollHeight;
        
        // Keep last 100 logs
        while (logViewer.children.length > 100) {
            logViewer.removeChild(logViewer.firstChild);
        }
    }
</script>
</body>
</html>
