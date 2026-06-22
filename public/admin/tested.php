<?php
// public/test_dashboard_debug.php
// This script tests the dashboard's PIN detection and payload building

require_once __DIR__ . '/../../src/Application/Utils/SessionManager.php';
use Application\Utils\SessionManager;

SessionManager::start();

// If not logged in, redirect
if (!SessionManager::isLoggedIn()) {
    header("Location: login.php");
    exit;
}

$user = SessionManager::getUser();
$loggedPhone = htmlspecialchars($user['phone'] ?? '');
$userId = $user['user_id'] ?? null;

// Get the dashboard HTML but inject debugging
ob_start();
include __DIR__ . '/user/dashboard.php';
$dashboardHtml = ob_get_clean();

// Inject debug overlay
$debugJs = <<<'JS'
<script>
// Override the buildPayload function to log everything
(function() {
    console.log('🔍 [DEBUG] Dashboard debug mode active');
    
    // Store reference to original functions
    let originalBuildPayload = window.buildPayload;
    let originalGetPin = window.getPinFromForm;
    
    // Override getPinFromForm with logging
    window.getPinFromForm = function() {
        console.log('🔍 [DEBUG] getPinFromForm called');
        
        // Log all form fields
        console.log('🔍 [DEBUG] All password fields:');
        document.querySelectorAll('input[type="password"]').forEach(el => {
            console.log('  - ID:', el.id, 'Name:', el.name, 'Value:', el.value ? '****' : '(empty)');
        });
        
        console.log('🔍 [DEBUG] All .asset-field elements:');
        document.querySelectorAll('.asset-field').forEach(el => {
            console.log('  - ID:', el.id, 'Type:', el.type, 'Value:', el.value ? '****' : '(empty)');
        });
        
        // Check specific PIN fields
        const pinIds = ['wallet_pin', 'pin', 'walletPin', 'atm_pin', 'card_pin'];
        console.log('🔍 [DEBUG] Specific PIN field checks:');
        pinIds.forEach(id => {
            const el = document.getElementById(id);
            console.log('  - #' + id + ':', el ? (el.value ? 'FOUND (****)' : 'EMPTY') : 'NOT FOUND');
        });
        
        // Call original if it exists
        let result = null;
        if (typeof originalGetPin === 'function') {
            result = originalGetPin();
            console.log('🔍 [DEBUG] original getPinFromForm returned:', result ? 'PIN (****)' : 'null');
        } else {
            // Fallback implementation
            let pin = null;
            document.querySelectorAll('input[type="password"]').forEach(el => {
                if (!pin && el.value && el.value.trim()) {
                    pin = el.value.trim();
                }
            });
            if (!pin) {
                document.querySelectorAll('.asset-field').forEach(el => {
                    if (!pin && el.value && el.value.trim() && (el.type === 'password' || el.id.includes('pin'))) {
                        pin = el.value.trim();
                    }
                });
            }
            result = pin;
            console.log('🔍 [DEBUG] fallback getPinFromForm returned:', result ? 'PIN (****)' : 'null');
        }
        
        return result;
    };
    
    // Override buildPayload with logging
    window.buildPayload = function() {
        console.log('🔍 [DEBUG] ===== buildPayload called =====');
        
        // Log current form state
        console.log('🔍 [DEBUG] Current form values:');
        console.log('  - fromInstitution:', document.getElementById('fromInstitution')?.value || 'NOT SET');
        console.log('  - toInstitution:', document.getElementById('toInstitution')?.value || 'NOT SET');
        console.log('  - assetType:', document.getElementById('assetType')?.value || 'NOT SET');
        console.log('  - swapType:', document.getElementById('swapType')?.value || 'NOT SET');
        console.log('  - amount:', document.getElementById('amount')?.value || '0');
        console.log('  - sourceIdentifier:', document.getElementById('sourceIdentifier')?.value || 'NOT SET');
        
        // Get PIN using our overridden function
        const pin = window.getPinFromForm();
        console.log('🔍 [DEBUG] PIN from getPinFromForm():', pin ? '****' : 'null');
        
        // Call original if it exists
        let payload = null;
        if (typeof originalBuildPayload === 'function') {
            payload = originalBuildPayload();
            console.log('🔍 [DEBUG] original buildPayload returned:', payload);
        } else {
            // Build payload manually for debugging
            const fromInst = document.getElementById('fromInstitution')?.value || '';
            const toInst = document.getElementById('toInstitution')?.value || '';
            const assetType = document.getElementById('assetType')?.value || '';
            const swapType = document.getElementById('swapType')?.value || 'CASHOUT';
            const amount = parseFloat(document.getElementById('amount')?.value || 0);
            const sourceIdentifier = document.getElementById('sourceIdentifier')?.value || '';
            
            payload = {
                reference: 'DEBUG_' + Date.now(),
                from_institution: fromInst,
                to_institution: toInst,
                asset_type: assetType,
                swap_type: swapType,
                amount: amount,
                source_identifier: sourceIdentifier
            };
            
            // Add PIN if found
            if (pin) {
                payload.wallet_pin = pin;
                payload.pin = pin;
                payload.asset_fields = { wallet_pin: pin, pin: pin };
            }
            
            // Collect asset fields
            const assetFieldsData = {};
            document.querySelectorAll('.asset-field').forEach(field => {
                const value = field.value.trim();
                if (value) {
                    assetFieldsData[field.id] = value;
                    payload[field.id] = value;
                }
            });
            if (Object.keys(assetFieldsData).length > 0) {
                payload.asset_fields = { ...payload.asset_fields, ...assetFieldsData };
            }
        }
        
        console.log('🔍 [DEBUG] Final payload:');
        console.log('  - wallet_pin:', payload.wallet_pin ? '****' : 'MISSING');
        console.log('  - pin:', payload.pin ? '****' : 'MISSING');
        console.log('  - asset_fields:', payload.asset_fields);
        console.log('  - Full payload:', JSON.stringify(payload, null, 2));
        
        // Display payload on screen for debugging
        const debugOutput = document.getElementById('debugPayloadOutput');
        if (debugOutput) {
            const displayPayload = { ...payload };
            if (displayPayload.wallet_pin) displayPayload.wallet_pin = '****';
            if (displayPayload.pin) displayPayload.pin = '****';
            if (displayPayload.asset_fields) {
                if (displayPayload.asset_fields.wallet_pin) displayPayload.asset_fields.wallet_pin = '****';
                if (displayPayload.asset_fields.pin) displayPayload.asset_fields.pin = '****';
            }
            debugOutput.textContent = JSON.stringify(displayPayload, null, 2);
            debugOutput.style.display = 'block';
        }
        
        // Show PIN status
        const pinStatus = document.getElementById('debugPinStatus');
        if (pinStatus) {
            pinStatus.innerHTML = payload.wallet_pin ? 
                '✅ PIN FOUND (length: ' + payload.wallet_pin.length + ')' : 
                '❌ PIN MISSING';
            pinStatus.style.color = payload.wallet_pin ? '#4caf50' : '#ff6b6b';
        }
        
        return payload;
    };
    
    // Intercept the execute button click
    document.addEventListener('DOMContentLoaded', function() {
        const executeBtn = document.getElementById('executeBtn');
        if (executeBtn) {
            const originalClick = executeBtn.click;
            executeBtn.addEventListener('click', function(e) {
                console.log('🔍 [DEBUG] Execute button clicked');
                // Call our buildPayload to show debug info
                window.buildPayload();
            });
        }
        
        // Add debug panel to the page
        const debugPanel = document.createElement('div');
        debugPanel.style.cssText = `
            position: fixed;
            bottom: 10px;
            right: 10px;
            background: #12162e;
            border: 2px solid #00f0ff;
            border-radius: 12px;
            padding: 16px;
            max-width: 500px;
            max-height: 400px;
            overflow-y: auto;
            z-index: 9999;
            font-family: monospace;
            font-size: 12px;
            color: #fff;
            box-shadow: 0 0 30px rgba(0,240,255,0.2);
        `;
        debugPanel.innerHTML = `
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                <strong style="color:#00f0ff;">🔍 Dashboard Debug</strong>
                <button onclick="this.parentElement.parentElement.style.display='none'" 
                        style="background:#ff6b6b; border:none; color:#fff; padding:2px 8px; border-radius:4px; cursor:pointer;">×</button>
            </div>
            <div style="margin-bottom:8px;">
                <span style="color:#888;">PIN Status:</span> 
                <span id="debugPinStatus" style="color:#ff6b6b;">❌ Not checked</span>
            </div>
            <div style="margin-bottom:8px;">
                <button onclick="window.buildPayload()" 
                        style="background:#00f0ff; color:#0a0e27; border:none; padding:4px 12px; border-radius:4px; cursor:pointer; font-weight:bold;">
                    🔍 Test buildPayload
                </button>
                <button onclick="document.getElementById('wallet_pin')?.value='77777'" 
                        style="background:#1a1f3a; color:#fff; border:1px solid #2a2f4a; padding:4px 12px; border-radius:4px; cursor:pointer; margin-left:4px;">
                    Set PIN 77777
                </button>
            </div>
            <div style="background:#0a0e27; padding:8px; border-radius:4px; max-height:200px; overflow-y:auto;">
                <pre id="debugPayloadOutput" style="margin:0; font-size:11px; white-space:pre-wrap; word-break:break-all; display:none;"></pre>
            </div>
        `;
        document.body.appendChild(debugPanel);
        
        console.log('🔍 [DEBUG] Debug panel added to page');
    });
    
    console.log('🔍 [DEBUG] Dashboard debugging active!');
})();
</script>
JS;

// Inject the debug JS before the closing body tag
$dashboardHtml = str_replace('</body>', $debugJs . '</body>', $dashboardHtml);

echo $dashboardHtml;
?>
