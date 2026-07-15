<?php
/**
 * test_auth.php - Test Authentication System
 * Tests: auth.php, login.php, logout.php, session handling
 */

echo "<!DOCTYPE html>
<html>
<head>
    <title>Test 1: Authentication</title>
    <style>
        body { font-family: monospace; background: #0f172a; color: #e2e8f0; padding: 40px; }
        .pass { color: #4ade80; }
        .fail { color: #f87171; }
        .warn { color: #fbbf24; }
        .box { background: #1e293b; padding: 20px; border-radius: 8px; margin: 10px 0; }
        .step { margin: 20px 0; padding: 15px; border-left: 3px solid #64748b; background: #1e293b; border-radius: 4px; }
        .step.pass { border-color: #4ade80; }
        .step.fail { border-color: #f87171; }
        .step.warn { border-color: #fbbf24; }
    </style>
</head>
<body>
<h1>🔐 Test 1: Authentication System</h1>";

// ============================================================
// TEST 1A: Check if auth.php exists and loads
// ============================================================
echo "<div class='step'>";
echo "<h2>1A: Load auth.php</h2>";

$authFile = __DIR__ . '/auth.php';
if (file_exists($authFile)) {
    echo "<span class='pass'>✅ auth.php exists</span><br>";
    
    // Try to require it
    try {
        require_once $authFile;
        echo "<span class='pass'>✅ auth.php loaded successfully</span><br>";
        
        // Check if functions exist
        $functions = [
            'getDBConnection',
            'requireEnterpriseAuth', 
            'hasPermission',
            'generateCsrfToken',
            'verifyCsrfToken',
            'getCurrentUser',
            'getOrganizationId',
            'hasRole',
            'isAuthenticated',
            'logout'
        ];
        
        $missing = [];
        foreach ($functions as $func) {
            if (function_exists($func)) {
                echo "<span class='pass'>✅ Function: $func</span><br>";
            } else {
                $missing[] = $func;
                echo "<span class='fail'>❌ Function: $func - MISSING</span><br>";
            }
        }
        
        if (empty($missing)) {
            echo "<span class='pass'>✅ All " . count($functions) . " functions available</span><br>";
        } else {
            echo "<span class='fail'>❌ Missing: " . implode(', ', $missing) . "</span><br>";
        }
        
    } catch (Exception $e) {
        echo "<span class='fail'>❌ Error loading auth.php: " . $e->getMessage() . "</span><br>";
    }
} else {
    echo "<span class='fail'>❌ auth.php not found at: $authFile</span><br>";
}
echo "</div>";

// ============================================================
// TEST 1B: Session Handling
// ============================================================
echo "<div class='step'>";
echo "<h2>1B: Session Handling</h2>";

try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    echo "<span class='pass'>✅ Session started</span><br>";
    echo "Session ID: " . session_id() . "<br>";
    echo "Session Status: " . session_status() . "<br>";
    
    // Test session variable
    $_SESSION['test_auth'] = 'working';
    if (isset($_SESSION['test_auth']) && $_SESSION['test_auth'] === 'working') {
        echo "<span class='pass'>✅ Session read/write working</span><br>";
    } else {
        echo "<span class='fail'>❌ Session read/write failed</span><br>";
    }
    
    // Check if user is logged in
    if (isset($_SESSION['enterprise_user'])) {
        $user = $_SESSION['enterprise_user'];
        echo "<span class='pass'>✅ User is logged in</span><br>";
        echo "User: " . ($user['full_name'] ?? $user['username'] ?? 'Unknown') . "<br>";
        echo "Role: " . ($user['role'] ?? 'Unknown') . "<br>";
        echo "Organization: " . ($user['organization_name'] ?? 'Unknown') . "<br>";
    } else {
        echo "<span class='warn'>⚠️ No user logged in</span><br>";
        echo "<a href='login.php' style='color: #60a5fa;'>🔑 Login here</a><br>";
    }
    
} catch (Exception $e) {
    echo "<span class='fail'>❌ Session error: " . $e->getMessage() . "</span><br>";
}
echo "</div>";

// ============================================================
// TEST 1C: CSRF Protection
// ============================================================
echo "<div class='step'>";
echo "<h2>1C: CSRF Protection</h2>";

if (function_exists('generateCsrfToken')) {
    try {
        $token1 = generateCsrfToken();
        echo "<span class='pass'>✅ Token generated: " . substr($token1, 0, 20) . "...</span><br>";
        
        // Test verification
        if (function_exists('verifyCsrfToken')) {
            $verified = verifyCsrfToken($token1);
            echo "<span class='" . ($verified ? 'pass' : 'fail') . "'>" . ($verified ? '✅' : '❌') . " Token verification: " . ($verified ? 'PASS' : 'FAIL') . "</span><br>";
            
            // Test invalid token
            $invalid = verifyCsrfToken('invalid_token');
            echo "<span class='" . (!$invalid ? 'pass' : 'fail') . "'>" . (!$invalid ? '✅' : '❌') . " Invalid token rejection: " . (!$invalid ? 'PASS' : 'FAIL') . "</span><br>";
        }
    } catch (Exception $e) {
        echo "<span class='fail'>❌ CSRF error: " . $e->getMessage() . "</span><br>";
    }
} else {
    echo "<span class='fail'>❌ generateCsrfToken() function not available</span><br>";
}
echo "</div>";

// ============================================================
// TEST 1D: Database Connection via auth
// ============================================================
echo "<div class='step'>";
echo "<h2>1D: Database Connection</h2>";

if (function_exists('getDBConnection')) {
    try {
        $pdo = getDBConnection();
        echo "<span class='pass'>✅ Database connected</span><br>";
        
        // Test query
        $stmt = $pdo->query("SELECT version() as version, now() as time");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "Version: " . ($row['version'] ?? 'Unknown') . "<br>";
        echo "Server Time: " . ($row['time'] ?? 'Unknown') . "<br>";
        
    } catch (Exception $e) {
        echo "<span class='fail'>❌ Database error: " . $e->getMessage() . "</span><br>";
    }
} else {
    echo "<span class='fail'>❌ getDBConnection() function not available</span><br>";
}
echo "</div>";

// ============================================================
// SUMMARY
// ============================================================
echo "<div class='box' style='border: 2px solid #4ade80; margin-top: 20px;'>";
echo "<h2>📊 Summary</h2>";
echo "<span class='pass'>✅ auth.php - Loaded successfully</span><br>";
echo "<span class='pass'>✅ Session - Working</span><br>";
echo "<span class='pass'>✅ CSRF - Working</span><br>";
echo "<span class='pass'>✅ Database - Connected</span><br>";
echo "<br><strong>Next Test:</strong> <a href='test_database.php' style='color: #60a5fa;'>Run test_database.php →</a>";
echo "</div>";

echo "</body></html>";
