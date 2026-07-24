<?php
// test_diagnose_tracking_failure.php
// ULTIMATE DIAGNOSTIC: Find WHY populateTrackingTables() is not working
// Tests ALL possible causes WITHOUT changing any production code

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/Core/Config/LoadCountry.php';
require_once __DIR__ . '/../../src/Domain/Services/SwapService.php';

use Core\Database\DBConnection;
use Core\Config\LoadCountry;
use Domain\Services\SwapService;

// ============================================================
// CONFIGURATION
// ============================================================
$testConfig = [
    'source_institution' => 'ZURUBANK',
    'source_identifier' => '10000001',
    'source_asset_type' => 'ACCOUNT',
    'destination_institution' => 'SACCUSSALIS',
    'beneficiary_phone' => '+26770000000',
    'amount' => 1000,
    'currency' => 'BWP',
    'swap_type' => 'CASHOUT',
    'user_id' => 12,
];

echo "========================================\n";
echo "DIAGNOSE populateTrackingTables() FAILURE\n";
echo "Testing ALL possible causes\n";
echo "========================================\n\n";

// ============================================================
// 1. GET DATABASE CONNECTION
// ============================================================
$pdo = DBConnection::getConnection();

if (!$pdo) {
    die("❌ Failed to connect to database\n");
}

echo "✅ Database connected\n\n";

// ============================================================
// 2. CONNECTION DIAGNOSTICS
// ============================================================
echo "🔍 CONNECTION DIAGNOSTICS\n";
echo "=============================\n";

echo "PostgreSQL Version : " . $pdo->query("SELECT version()")->fetchColumn() . PHP_EOL;
echo "Current User       : " . $pdo->query("SELECT current_user")->fetchColumn() . PHP_EOL;
echo "Current Database   : " . $pdo->query("SELECT current_database()")->fetchColumn() . PHP_EOL;
echo "Current Schema     : " . $pdo->query("SELECT current_schema()")->fetchColumn() . PHP_EOL;
echo "Search Path        : " . $pdo->query("SHOW search_path")->fetchColumn() . PHP_EOL;
echo "In Transaction     : " . ($pdo->inTransaction() ? 'YES ⚠️' : 'NO ✅') . PHP_EOL;
echo "PDO Object ID      : " . spl_object_id($pdo) . PHP_EOL;

$pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, true);
echo "Autocommit set     : ON ✅\n";
echo "\n";

// ============================================================
// 3. CLEAN UP EARMARKED BALANCES
// ============================================================
echo "🧹 Cleaning up earmarked balances...\n";
try {
    $stmt = $pdo->prepare("
        UPDATE identity_earmarked_balances
        SET status = 'depleted', remaining_amount = 0, depleted_at = NOW()
        WHERE destination_institution = :inst
          AND destination_identifier = :ident
          AND status = 'open'
    ");
    $stmt->execute([
        ':inst' => $testConfig['source_institution'],
        ':ident' => $testConfig['source_identifier']
    ]);
    echo "   ✅ Closed " . $stmt->rowCount() . " earmarked balance(s)\n";
} catch (PDOException $e) {
    echo "   ⚠️  Could not clean earmarks: " . $e->getMessage() . "\n";
}
echo "\n";

// ============================================================
// 4. LOAD COUNTRY CONFIG
// ============================================================
echo "📂 Loading country config...\n";
$countryConfig = LoadCountry::getConfig();

if (empty($countryConfig)) {
    die("❌ Failed to load country config\n");
}
echo "✅ Country config loaded\n\n";

// ============================================================
// 5. CREATE SWAP PAYLOAD
// ============================================================
echo "📝 Creating swap payload...\n";

$reference = 'SWAP_TEST_' . time() . '_' . bin2hex(random_bytes(4));

$payload = [
    'swap_type' => $testConfig['swap_type'],
    'reference' => $reference,
    'idempotency_key' => 'IDEMP_' . $reference,
    'user_id' => $testConfig['user_id'],
    'from_institution' => $testConfig['source_institution'],
    'source_institution' => $testConfig['source_institution'],
    'asset_type' => $testConfig['source_asset_type'],
    'amount' => $testConfig['amount'],
    'currency' => $testConfig['currency'],
    'source_identifier' => $testConfig['source_identifier'],
    'source_identifier_type' => 'auto',
    'to_institution' => $testConfig['destination_institution'],
    'destination_institution' => $testConfig['destination_institution'],
    'beneficiary_phone' => $testConfig['beneficiary_phone'],
    'client_phone' => $testConfig['beneficiary_phone'],
    'delivery_method' => 'ATM',
    'destination_currency' => $testConfig['currency'],
];

echo "   Reference: {$reference}\n";
echo "   Amount: {$testConfig['amount']} {$testConfig['currency']}\n";
echo "   Source: {$testConfig['source_institution']} {$testConfig['source_identifier']}\n";
echo "   Destination: {$testConfig['destination_institution']}\n";
echo "   Phone: {$testConfig['beneficiary_phone']}\n\n";

// ============================================================
// 6. INITIALIZE SWAP SERVICE
// ============================================================
echo "⚙️  Initializing SwapService...\n";

try {
    $swapService = new SwapService(
        $pdo,
        $countryConfig,
        'Botswana',
        null
    );
    echo "✅ SwapService initialized\n\n";
} catch (Exception $e) {
    die("❌ Failed to initialize SwapService: " . $e->getMessage() . "\n");
}

// ============================================================
// 7. USE REFLECTION TO INSPECT AND MODIFY BEHAVIOR
// ============================================================
echo "🔍 Using Reflection to inspect SwapService...\n";
echo "=============================\n";

$reflection = new ReflectionClass($swapService);

// Get all private methods
$methods = $reflection->getMethods(ReflectionMethod::IS_PRIVATE);

echo "   Private methods found: " . count($methods) . "\n";

// Check if populateTrackingTables exists
$hasPopulateTracking = false;
$hasPopulateCashoutAuth = false;
$hasPopulateSwapRequest = false;
$hasPopulateSwapTransaction = false;

foreach ($methods as $method) {
    $name = $method->getName();
    if ($name === 'populateTrackingTables') {
        $hasPopulateTracking = true;
        echo "   ✅ populateTrackingTables() exists\n";
        
        // Get the method code
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();
        $length = $endLine - $startLine;
        echo "      Lines: {$startLine} - {$endLine} (approx {$length} lines)\n";
    }
    if ($name === 'populateCashoutAuthorization') {
        $hasPopulateCashoutAuth = true;
        echo "   ✅ populateCashoutAuthorization() exists\n";
    }
    if ($name === 'populateSwapRequest') {
        $hasPopulateSwapRequest = true;
        echo "   ✅ populateSwapRequest() exists\n";
    }
    if ($name === 'populateSwapTransaction') {
        $hasPopulateSwapTransaction = true;
        echo "   ✅ populateSwapTransaction() exists\n";
    }
}

if (!$hasPopulateTracking) {
    echo "   ❌ populateTrackingTables() does NOT exist!\n";
    echo "      This is the problem - the method is missing!\n";
}

echo "\n";

// ============================================================
// 8. TEST CAUSE 1: Is populateTrackingTables() being called?
// ============================================================
echo "============================================================\n";
echo "🔬 TEST CAUSE 1: Is populateTrackingTables() called?\n";
echo "============================================================\n";

// Create a test that overrides the method using a mock
echo "\n[TEST 1.1] Checking if populateTrackingTables() is called...\n";

// We'll use a proxy approach - extend SwapService and override the method
class SwapServiceProxy extends SwapService
{
    public static $populateTrackingCalled = false;
    public static $populateTrackingParams = null;
    public static $populateTrackingException = null;
    
    public function __construct($pdo, $config, $country, $logger = null)
    {
        parent::__construct($pdo, $config, $country, $logger);
    }
    
    private function populateTrackingTables(array $swapData, array $details, ?array $destResponse = null): void
    {
        self::$populateTrackingCalled = true;
        self::$populateTrackingParams = [
            'swapData' => $swapData,
            'details' => $details,
            'destResponse' => $destResponse
        ];
        
        try {
            // Call the parent method
            parent::populateTrackingTables($swapData, $details, $destResponse);
        } catch (Exception $e) {
            self::$populateTrackingException = $e;
            throw $e;
        }
    }
    
    public function testExecute($payload)
    {
        return $this->executeAtomicSwap($payload);
    }
}

echo "   Creating proxy SwapService...\n";

try {
    $proxyService = new SwapServiceProxy(
        $pdo,
        $countryConfig,
        'Botswana',
        null
    );
    echo "   ✅ Proxy created\n";
    
    // Execute the swap with the proxy
    $proxyRef = 'PROXY_TEST_' . time() . '_' . bin2hex(random_bytes(4));
    $proxyPayload = $payload;
    $proxyPayload['reference'] = $proxyRef;
    
    echo "   Executing swap with proxy...\n";
    $proxyResult = $proxyService->testExecute($proxyPayload);
    
    echo "   Result: " . ($proxyResult ? 'success' : 'failed') . "\n";
    echo "   populateTrackingTables() called: " . (SwapServiceProxy::$populateTrackingCalled ? '✅ YES' : '❌ NO') . "\n";
    
    if (SwapServiceProxy::$populateTrackingCalled) {
        echo "   ✅ populateTrackingTables() WAS called!\n";
        echo "   The method exists and is being called.\n";
        
        // Check what was passed
        $params = SwapServiceProxy::$populateTrackingParams;
        if ($params) {
            echo "   Parameters:\n";
            echo "      swapData keys: " . implode(', ', array_keys($params['swapData'])) . "\n";
            echo "      details keys: " . implode(', ', array_keys($params['details'])) . "\n";
            echo "      destResponse keys: " . ($params['destResponse'] ? implode(', ', array_keys($params['destResponse'])) : 'NULL') . "\n";
        }
        
        if (SwapServiceProxy::$populateTrackingException) {
            echo "   ❌ populateTrackingTables() threw an exception:\n";
            echo "      " . SwapServiceProxy::$populateTrackingException->getMessage() . "\n";
            echo "      This is why no data was written!\n";
        } else {
            echo "   ✅ populateTrackingTables() completed successfully!\n";
            
            // Check if data was written
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM swap_requests WHERE swap_uuid = ?");
            $stmt->execute([$proxyRef]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                echo "   ✅ Data WAS written to the database!\n";
                echo "   The method works when called directly.\n";
            } else {
                echo "   ❌ Data was NOT written to the database!\n";
                echo "   The method was called but failed silently.\n";
            }
        }
    } else {
        echo "   ❌ populateTrackingTables() was NOT called!\n";
        echo "   This is the root cause - the method is never invoked.\n";
    }
    
} catch (Exception $e) {
    echo "   ❌ Proxy test failed: " . $e->getMessage() . "\n";
}

echo "\n";

// ============================================================
// 9. TEST CAUSE 2: Is there an exception in populateTrackingTables?
// ============================================================
echo "============================================================\n";
echo "🔬 TEST CAUSE 2: Is populateTrackingTables() throwing?\n";
echo "============================================================\n";

echo "\n[TEST 2.1] Checking each tracking method individually...\n";

// Test each method independently
$trackingMethods = [
    'populateSwapRequest' => ['swap_uuid' => $reference],
    'populateSwapTransaction' => ['swap_id' => 999999, 'swap_ref' => $reference],
    'populateCashoutAuthorization' => ['swap_ref' => $reference],
    'populateMessageOutbox' => ['phone' => $testConfig['beneficiary_phone']],
];

foreach ($trackingMethods as $methodName => $params) {
    echo "\n   Testing {$methodName}():\n";
    
    if (!$reflection->hasMethod($methodName)) {
        echo "      ❌ Method does not exist\n";
        continue;
    }
    
    $method = $reflection->getMethod($methodName);
    $method->setAccessible(true);
    
    try {
        // Build parameters based on method signature
        $methodParams = $method->getParameters();
        $args = [];
        
        foreach ($methodParams as $param) {
            $paramName = $param->getName();
            if (isset($params[$paramName])) {
                $args[] = $params[$paramName];
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } else {
                $args[] = null;
            }
        }
        
        // Call the method with reflection
        $result = $method->invokeArgs($swapService, $args);
        
        // Check if data was written
        if ($methodName === 'populateSwapRequest') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM swap_requests WHERE swap_uuid = ?");
            $stmt->execute([$reference]);
            $count = $stmt->fetchColumn();
            echo "      ✅ Method executed - swap_requests count: {$count}\n";
        } elseif ($methodName === 'populateCashoutAuthorization') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM cashout_authorizations WHERE swap_reference = ?");
            $stmt->execute([$reference]);
            $count = $stmt->fetchColumn();
            echo "      ✅ Method executed - cashout_authorizations count: {$count}\n";
        } else {
            echo "      ✅ Method executed successfully\n";
        }
        
    } catch (Exception $e) {
        echo "      ❌ Method threw exception: " . $e->getMessage() . "\n";
        echo "      File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    }
}

echo "\n";

// ============================================================
// 10. TEST CAUSE 3: Is there a duplicate call issue?
// ============================================================
echo "============================================================\n";
echo "🔬 TEST CAUSE 3: Duplicate populateCashoutAuthorization() issue\n";
echo "============================================================\n";

echo "\n[TEST 3.1] Checking for duplicate calls...\n";

// Check if storeCashoutAuthorization() and populateCashoutAuthorization()
// are both being called
try {
    // Get the executeSignedCashout method
    if ($reflection->hasMethod('executeSignedCashout')) {
        $method = $reflection->getMethod('executeSignedCashout');
        $method->setAccessible(true);
        
        // Get the method code as string
        $fileName = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();
        
        if ($fileName) {
            $lines = file($fileName);
            $code = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));
            
            // Check for storeCashoutAuthorization calls
            $hasStoreCashout = strpos($code, 'storeCashoutAuthorization') !== false;
            $hasPopulateCashout = strpos($code, 'populateCashoutAuthorization') !== false;
            
            echo "   storeCashoutAuthorization() called: " . ($hasStoreCashout ? '✅ YES' : '❌ NO') . "\n";
            echo "   populateCashoutAuthorization() called: " . ($hasPopulateCashout ? '✅ YES' : '❌ NO') . "\n";
            
            if ($hasStoreCashout && $hasPopulateCashout) {
                echo "   ⚠️  BOTH methods are called - this may cause duplicate issues!\n";
                echo "      storeCashoutAuthorization() creates the record\n";
                echo "      populateCashoutAuthorization() tries to create it again\n";
                echo "      The ON CONFLICT clause may be failing\n";
            } else {
                echo "   ✅ Only one method is called - no duplicate issue\n";
            }
            
            // Check if populateTrackingTables is called
            $hasPopulateTrackingCall = strpos($code, 'populateTrackingTables') !== false;
            echo "   populateTrackingTables() called: " . ($hasPopulateTrackingCall ? '✅ YES' : '❌ NO') . "\n";
            
            if (!$hasPopulateTrackingCall) {
                echo "   ❌ CRITICAL: populateTrackingTables() is NOT called in executeSignedCashout()!\n";
                echo "      This is the root cause - the method is never invoked.\n";
            }
            
            // Check if there's a return before populateTrackingTables
            $returnPos = strpos($code, 'return [');
            $populatePos = strpos($code, 'populateTrackingTables');
            
            if ($returnPos !== false && $populatePos !== false) {
                if ($populatePos > $returnPos) {
                    echo "   ❌ populateTrackingTables() is called AFTER the return statement!\n";
                    echo "      This means it will NEVER execute!\n";
                } else {
                    echo "   ✅ populateTrackingTables() is called BEFORE the return\n";
                }
            }
        }
    }
} catch (Exception $e) {
    echo "   ⚠️  Could not analyze code: " . $e->getMessage() . "\n";
}

echo "\n";

// ============================================================
// 11. TEST CAUSE 4: Is the method signature wrong?
// ============================================================
echo "============================================================\n";
echo "🔬 TEST CAUSE 4: Method signature issues\n";
echo "============================================================\n";

echo "\n[TEST 4.1] Checking method signatures...\n";

// Check populateTrackingTables signature
if ($hasPopulateTracking) {
    $method = $reflection->getMethod('populateTrackingTables');
    $params = $method->getParameters();
    
    echo "   populateTrackingTables() parameters:\n";
    foreach ($params as $param) {
        $type = $param->getType() ? $param->getType()->getName() : 'mixed';
        $default = $param->isDefaultValueAvailable() ? ' = ' . var_export($param->getDefaultValue(), true) : '';
        echo "      - {$type} \${$param->getName()}{$default}\n";
    }
    
    // Check what's being passed
    $caller = $reflection->getMethod('executeSignedCashout');
    $caller->setAccessible(true);
    $fileName = $caller->getFileName();
    $startLine = $caller->getStartLine();
    $endLine = $caller->getEndLine();
    
    if ($fileName) {
        $lines = file($fileName);
        $code = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));
        
        // Find the populateTrackingTables call
        $pattern = '/populateTrackingTables\s*\(([^)]*)\)/s';
        preg_match($pattern, $code, $matches);
        
        if (isset($matches[1])) {
            echo "   Call signature: " . trim($matches[1]) . "\n";
            
            // Count the arguments
            $args = explode(',', $matches[1]);
            $argCount = count($args);
            $expectedCount = count($params);
            
            echo "   Arguments passed: {$argCount}\n";
            echo "   Parameters expected: {$expectedCount}\n";
            
            if ($argCount !== $expectedCount) {
                echo "   ❌ MISMATCH: {$argCount} arguments passed, {$expectedCount} expected!\n";
                echo "      This will cause a fatal error!\n";
            } else {
                echo "   ✅ Argument count matches\n";
            }
        } else {
            echo "   ❌ Could not find populateTrackingTables() call in executeSignedCashout()\n";
            echo "      The method may not be called at all!\n";
        }
    }
}

echo "\n";

// ============================================================
// 12. TEST CAUSE 5: Is the transaction being rolled back?
// ============================================================
echo "============================================================\n";
echo "🔬 TEST CAUSE 5: Transaction rollback analysis\n";
echo "============================================================\n";

echo "\n[TEST 5.1] Checking if populateTrackingTables() runs inside transaction...\n";

try {
    // Create a custom test that logs when transaction starts/commits/rolls back
    class TransactionLogger extends PDO
    {
        public static $log = [];
        
        public function beginTransaction(): bool
        {
            self::$log[] = ['BEGIN', microtime(true)];
            return parent::beginTransaction();
        }
        
        public function commit(): bool
        {
            self::$log[] = ['COMMIT', microtime(true)];
            return parent::commit();
        }
        
        public function rollBack(): bool
        {
            self::$log[] = ['ROLLBACK', microtime(true)];
            return parent::rollBack();
        }
    }
    
    // Create a new connection with logging
    $logPdo = DBConnection::getConnection();
    
    // We can't easily wrap PDO, so we'll use the existing one
    echo "   Using existing PDO connection\n";
    echo "   Transaction log will be captured via error_log\n";
    
} catch (Exception $e) {
    echo "   ⚠️  Could not set up transaction logging: " . $e->getMessage() . "\n";
}

echo "\n";

// ============================================================
// 13. TEST CAUSE 6: Is there a constraint violation?
// ============================================================
echo "============================================================\n";
echo "🔬 TEST CAUSE 6: Constraint violations\n";
echo "============================================================\n";

echo "\n[TEST 6.1] Checking for unique constraint violations...\n";

// Check the unique constraints on cashout_authorizations
try {
    $stmt = $pdo->query("
        SELECT conname, contype, pg_get_constraintdef(oid) 
        FROM pg_constraint 
        WHERE conrelid = 'cashout_authorizations'::regclass 
        AND contype = 'u'
    ");
    $constraints = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "   Unique constraints on cashout_authorizations:\n";
    foreach ($constraints as $constraint) {
        echo "      - {$constraint['conname']}: {$constraint['pg_get_constraintdef']}\n";
    }
    
    // Check if there's already a record with this reference
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cashout_authorizations WHERE swap_reference = ?");
    $stmt->execute([$reference]);
    $count = $stmt->fetchColumn();
    
    if ($count > 0) {
        echo "   ⚠️  Found {$count} existing record(s) with swap_reference = {$reference}\n";
        echo "      This will cause a unique constraint violation!\n";
    } else {
        echo "   ✅ No existing records with this swap_reference\n";
    }
    
    // Check the ON CONFLICT clause in populateCashoutAuthorization
    $method = $reflection->getMethod('populateCashoutAuthorization');
    $method->setAccessible(true);
    $fileName = $method->getFileName();
    $startLine = $method->getStartLine();
    $endLine = $method->getEndLine();
    
    if ($fileName) {
        $lines = file($fileName);
        $code = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));
        
        if (strpos($code, 'ON CONFLICT') !== false) {
            echo "   ✅ ON CONFLICT clause is present\n";
            
            // Check what's in the ON CONFLICT
            preg_match('/ON CONFLICT\s*\(([^)]*)\)\s*DO UPDATE SET\s*([^)]*)\s*WHERE/s', $code, $matches);
            if (isset($matches[1]) && isset($matches[2])) {
                echo "   Conflict column(s): {$matches[1]}\n";
                echo "   Update fields: " . substr(trim($matches[2]), 0, 100) . "...\n";
            }
        } else {
            echo "   ❌ No ON CONFLICT clause found!\n";
            echo "      This will cause duplicate key errors!\n";
        }
    }
    
} catch (Exception $e) {
    echo "   ⚠️  Could not check constraints: " . $e->getMessage() . "\n";
}

echo "\n";

// ============================================================
// 14. TEST CAUSE 7: Is there an exception being swallowed?
// ============================================================
echo "============================================================\n";
echo "🔬 TEST CAUSE 7: Swallowed exceptions\n";
echo "============================================================\n";

echo "\n[TEST 7.1] Checking for try/catch blocks that swallow exceptions...\n";

try {
    $method = $reflection->getMethod('populateTrackingTables');
    $fileName = $method->getFileName();
    $startLine = $method->getStartLine();
    $endLine = $method->getEndLine();
    
    if ($fileName) {
        $lines = file($fileName);
        $code = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));
        
        // Count try/catch blocks
        $tryCount = substr_count($code, 'try {');
        $catchCount = substr_count($code, 'catch (');
        
        echo "   Try blocks: {$tryCount}\n";
        echo "   Catch blocks: {$catchCount}\n";
        
        // Check if exceptions are re-thrown
        $hasThrow = strpos($code, 'throw') !== false;
        $hasLog = strpos($code, 'error_log') !== false || strpos($code, 'logger->error') !== false;
        
        echo "   Contains 'throw': " . ($hasThrow ? '✅ YES' : '❌ NO') . "\n";
        echo "   Contains error logging: " . ($hasLog ? '✅ YES' : '❌ NO') . "\n";
        
        if (!$hasThrow && !$hasLog) {
            echo "   ⚠️  No exception handling or logging found!\n";
            echo "      Exceptions may be silently failing.\n";
        }
        
        // Check if there's a catch without re-throw
        if (strpos($code, 'catch (') !== false && strpos($code, 'throw') === false) {
            echo "   ⚠️  Catch blocks found but no re-throw!\n";
            echo "      Exceptions are being swallowed silently.\n";
            echo "      This could hide the real problem.\n";
        }
    }
} catch (Exception $e) {
    echo "   ⚠️  Could not analyze code: " . $e->getMessage() . "\n";
}

echo "\n";

// ============================================================
// 15. SUMMARY AND RECOMMENDATIONS
// ============================================================
echo "============================================================\n";
echo "📊 SUMMARY AND RECOMMENDATIONS\n";
echo "============================================================\n";

echo "\nBased on the tests above:\n\n";

// Determine the most likely cause
$mostLikelyCause = "Unknown";

if ($hasPopulateTracking) {
    echo "✅ populateTrackingTables() EXISTS in the code\n";
    
    // Check if it was called
    if (SwapServiceProxy::$populateTrackingCalled ?? false) {
        echo "✅ populateTrackingTables() WAS called (proxy test)\n";
        
        if (SwapServiceProxy::$populateTrackingException ?? false) {
            $mostLikelyCause = "populateTrackingTables() is THROWING an exception";
            echo "❌ It THREW an exception: " . SwapServiceProxy::$populateTrackingException->getMessage() . "\n";
        } else {
            $mostLikelyCause = "populateTrackingTables() completed but data not written (ON CONFLICT issue)";
            echo "⚠️  It completed but data was not written\n";
        }
    } else {
        $mostLikelyCause = "populateTrackingTables() is NEVER called";
        echo "❌ It was NEVER called\n";
        echo "   The call may be after a return statement or in an unreachable code path\n";
    }
} else {
    $mostLikelyCause = "populateTrackingTables() does NOT exist in the code";
    echo "❌ populateTrackingTables() does NOT exist!\n";
}

echo "\n📋 RECOMMENDED SOLUTIONS:\n";
echo "------------------------------------------------------------\n";

switch ($mostLikelyCause) {
    case "populateTrackingTables() does NOT exist in the code":
        echo "1. Add the populateTrackingTables() method to SwapService\n";
        echo "2. Call it from executeSignedCashout() before returning\n";
        break;
        
    case "populateTrackingTables() is NEVER called":
        echo "1. Check if the call is after a return statement\n";
        echo "2. Check if the call is inside a conditional that's false\n";
        echo "3. Move the call before the return in executeSignedCashout()\n";
        break;
        
    case "populateTrackingTables() is THROWING an exception":
        echo "1. Wrap populateTrackingTables() in try/catch\n";
        echo "2. NEVER re-throw exceptions from tracking methods\n";
        echo "3. Log the error but continue\n";
        echo "4. Specific exception: " . (SwapServiceProxy::$populateTrackingException->getMessage() ?? 'Unknown') . "\n";
        break;
        
    case "populateTrackingTables() completed but data not written (ON CONFLICT issue)":
        echo "1. Check the ON CONFLICT clause in populateCashoutAuthorization()\n";
        echo "2. Remove duplicate populateCashoutAuthorization() call\n";
        echo "3. Use INSERT ... ON CONFLICT DO NOTHING instead of DO UPDATE\n";
        echo "4. Check if the UNIQUE constraint is being violated\n";
        break;
        
    default:
        echo "1. Check that swap_type in payload is exactly 'CASHOUT'\n";
        echo "2. Check that executeAtomicSwap() is calling executeSignedCashout()\n";
        echo "3. Add logging to confirm which code path is being executed\n";
        break;
}

echo "\n";
echo "========================================\n";
echo "DIAGNOSTIC COMPLETE\n";
echo "========================================\n";
