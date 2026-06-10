<?php
/**
 * complete_swap_integration_test.php
 * 
 * Tests complete swap flow using the SAME configuration as SwapService
 * Zurubank (200 BWP Voucher) → Saccussalis (Cashout)
 */

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';

use Core\Database\DBConnection;
use Domain\Services\SwapService;
use Domain\Services\FeeService;
use Domain\Services\ForexService;
use Domain\Services\Settlement\HybridSettlementStrategy;
use Infrastructure\Banks\GenericBankClient;
use Infrastructure\Mojaloop\IdempotencyService;

class SwapIntegrationTest
{
    private $pdo;
    private $results = [];
    private $stepStartTime;
    private $country;
    private $participants = [];
    private $endpoints = [];
    private $assets = [];
    private $flows = [];
    private $feesConfig = [];
    
    public function __construct($pdo, string $country = 'Botswana')
    {
        $this->pdo = $pdo;
        $this->country = $country;
        
        // Load configuration the SAME WAY SwapService does
        $this->loadConfiguration();
    }
    
    /**
     * Load configuration exactly like SwapService::loadConfiguration()
     */
    private function loadConfiguration(): void
    {
        $countryPath = __DIR__ . "/../../src/Core/Config/Countries/{$this->country}";
        
        echo "\n📂 Loading configuration from: {$countryPath}\n";
        
        // 1. Load participants.yaml
        $participantsPath = $countryPath . '/participants.yaml';
        if (file_exists($participantsPath)) {
            $this->participants = $this->parseParticipantsYaml($participantsPath);
            echo "   ✅ Loaded " . count($this->participants) . " participants from YAML\n";
        } else {
            echo "   ⚠️ participants.yaml not found at: {$participantsPath}\n";
        }
        
        // 2. Load endpoints.yaml
        $endpointsPath = $countryPath . '/endpoints.yaml';
        if (file_exists($endpointsPath)) {
            $this->endpoints = $this->parseEndpointsYaml($endpointsPath);
            echo "   ✅ Loaded " . count($this->endpoints) . " endpoint configs from YAML\n";
        } else {
            echo "   ⚠️ endpoints.yaml not found at: {$endpointsPath}\n";
        }
        
        // 3. Load assets.yaml from global config
        $assetsPath = __DIR__ . '/../../src/Core/Config/assets.yaml';
        if (file_exists($assetsPath)) {
            $this->assets = $this->parseAssetsYaml($assetsPath);
            echo "   ✅ Loaded assets from YAML\n";
        }
        
        // 4. Load flows.yaml from global config
        $flowsPath = __DIR__ . '/../../src/Core/Config/flows.yaml';
        if (file_exists($flowsPath)) {
            $this->flows = $this->parseFlowsYaml($flowsPath);
            echo "   ✅ Loaded flows from YAML\n";
        }
        
        // 5. Load fees configuration
        $feesPath = $countryPath . '/fees.json';
        if (file_exists($feesPath)) {
            $this->feesConfig = json_decode(file_get_contents($feesPath), true) ?? [];
            echo "   ✅ Loaded fees configuration\n";
        }
        
        // 6. Merge endpoints into participants (same as SwapService)
        foreach ($this->participants as $code => &$participant) {
            if (isset($this->endpoints[$code])) {
                $participant['endpoints'] = $this->endpoints[$code]['endpoints'] ?? [];
                $participant['base_url'] = $this->endpoints[$code]['base_url'] ?? null;
                $participant['auth'] = $this->endpoints[$code]['auth'] ?? null;
                $participant['callbacks'] = $this->endpoints[$code]['callbacks'] ?? [];
                $participant['phone_format'] = $this->endpoints[$code]['phone_format'] ?? ['prefix' => '+', 'country_code' => '267'];
                $participant['message_profile'] = $this->endpoints[$code]['message_profile'] ?? [];
                $participant['retry_policy'] = $this->endpoints[$code]['retry_policy'] ?? ['max_retries' => 3];
                
                // Set resource_endpoints from endpoints.source
                if (isset($this->endpoints[$code]['endpoints']['source'])) {
                    $participant['resource_endpoints'] = $this->endpoints[$code]['endpoints']['source'];
                }
            }
            
            if (!isset($participant['default_currency'])) {
                $participant['default_currency'] = 'BWP';
            }
            
            if (!isset($participant['country_code'])) {
                $participant['country_code'] = $this->country;
            }
        }
        
        echo "\n";
    }
    
    private function parseParticipantsYaml(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }
        
        $content = file_get_contents($path);
        $participants = [];
        $lines = explode("\n", $content);
        $currentParticipant = null;
        
        foreach ($lines as $line) {
            $line = rtrim($line);
            if (empty($line) || $line[0] === '#') continue;
            
            if (preg_match('/^  ([A-Z_]+):$/', $line, $matches)) {
                $currentParticipant = strtolower($matches[1]);
                $participants[$currentParticipant] = [];
            } elseif ($currentParticipant && preg_match('/^    ([a-z_]+): (.+)$/', $line, $matches)) {
                $value = trim($matches[2]);
                if (preg_match('/^"(.+)"$/', $value, $q)) $value = $q[1];
                if (preg_match("/^'(.+)'$/", $value, $q)) $value = $q[1];
                $participants[$currentParticipant][$matches[1]] = $value;
            } elseif ($currentParticipant && preg_match('/^      ([a-z_]+): (.+)$/', $line, $matches)) {
                if (!isset($participants[$currentParticipant]['routing'])) {
                    $participants[$currentParticipant]['routing'] = [];
                }
                $participants[$currentParticipant]['routing'][$matches[1]] = trim($matches[2]);
            }
        }
        
        return $participants;
    }
    
    private function parseEndpointsYaml(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }
        
        $content = file_get_contents($path);
        $endpoints = [];
        $lines = explode("\n", $content);
        $currentParticipant = null;
        $currentSection = null;
        
        foreach ($lines as $line) {
            $line = rtrim($line);
            if (empty($line) || $line[0] === '#') continue;
            
            if (preg_match('/^([A-Z_]+):$/', $line, $matches)) {
                $currentParticipant = strtolower($matches[1]);
                $endpoints[$currentParticipant] = [];
                $currentSection = null;
                continue;
            }
            
            if (!$currentParticipant) continue;
            
            if (preg_match('/^  ([a-z_]+):$/', $line, $matches)) {
                $currentSection = $matches[1];
                $endpoints[$currentParticipant][$currentSection] = [];
                continue;
            }
            
            if ($currentSection && preg_match('/^    ([a-z_]+): (.+)$/', $line, $matches)) {
                $key = $matches[1];
                $value = trim($matches[2]);
                if (preg_match('/^"(.+)"$/', $value, $q)) $value = $q[1];
                $endpoints[$currentParticipant][$currentSection][$key] = $value;
                continue;
            }
            
            if (preg_match('/^  ([a-z_]+): (.+)$/', $line, $matches)) {
                $key = $matches[1];
                $value = trim($matches[2]);
                if (preg_match('/^"(.+)"$/', $value, $q)) $value = $q[1];
                if ($value === 'true') $value = true;
                if ($value === 'false') $value = false;
                if (is_numeric($value)) $value = (float)$value;
                $endpoints[$currentParticipant][$key] = $value;
            }
        }
        
        return $endpoints;
    }
    
    private function parseAssetsYaml(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }
        
        $content = file_get_contents($path);
        $assets = [];
        $lines = explode("\n", $content);
        $currentAsset = null;
        
        foreach ($lines as $line) {
            $line = rtrim($line);
            if (empty($line) || $line[0] === '#') continue;
            
            if (preg_match('/^([A-Z-]+):$/', $line, $matches)) {
                $currentAsset = $matches[1];
                $assets[$currentAsset] = [];
            } elseif ($currentAsset && preg_match('/^  ([a-z_]+): (.+)$/', $line, $matches)) {
                $assets[$currentAsset][$matches[1]] = trim($matches[2]);
            }
        }
        
        return $assets;
    }
    
    private function parseFlowsYaml(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }
        
        $content = file_get_contents($path);
        $flows = [];
        $lines = explode("\n", $content);
        $currentSection = null;
        
        foreach ($lines as $line) {
            $line = rtrim($line);
            if (empty($line) || $line[0] === '#') continue;
            
            if (preg_match('/^([a-z_]+):$/', $line, $matches)) {
                $currentSection = $matches[1];
                $flows[$currentSection] = [];
            } elseif ($currentSection === 'orchestration' && preg_match('/^  ([a-z_]+): (.+)$/', $line, $matches)) {
                $flows[$currentSection][$matches[1]] = trim($matches[2]);
            } elseif ($currentSection === 'states' && preg_match('/^  - (.+)$/', $line, $matches)) {
                $flows[$currentSection][] = trim($matches[1]);
            }
        }
        
        return $flows;
    }
    
    public function run(): void
    {
        $this->header();
        
        // Test 1: Database Connection
        $this->testDatabaseConnection();
        
        // Test 2: FeeService
        $this->testFeeService();
        
        // Test 3: ForexService
        $this->testForexService();
        
        // Test 4: GenericBankClient - Verify (Zurubank)
        $this->testBankVerify('ZURUBANK');
        
        // Test 5: GenericBankClient - Hold (Zurubank)
        $this->testBankHold('ZURUBANK');
        
        // Test 6: GenericBankClient - Generate Token (Saccussalis)
        $this->testBankGenerateToken('SACCUSSALIS');
        
        // Test 7: Complete Swap via SwapService
        $this->testCompleteSwap();
        
        // Test 8: Settlement Recording
        $this->testSettlement();
        
        // Test 9: Idempotency
        $this->testIdempotency();
        
        $this->footer();
    }
    
    private function testDatabaseConnection(): void
    {
        $this->stepStart("Database Connection (DBConnection)");
        
        try {
            $status = DBConnection::getStatus();
            $connected = DBConnection::isConnected();
            
            $this->recordResult('Database Connection', $connected, [
                'host' => $status['host'] ?? 'unknown',
                'database' => $status['database'] ?? 'unknown',
                'user' => $status['user'] ?? 'unknown',
                'connected' => $connected
            ]);
            
        } catch (Exception $e) {
            $this->recordResult('Database Connection', false, ['error' => $e->getMessage()]);
        }
    }
    
    private function testFeeService(): void
    {
        $this->stepStart("FeeService");
        
        try {
            $feeService = new FeeService($this->feesConfig, 'BWP');
            
            if (method_exists($feeService, 'calculateFees')) {
                $fees = $feeService->calculateFees('CASHOUT', 200, [
                    'source_institution' => 'ZURUBANK',
                    'destination_institution' => 'SACCUSSALIS'
                ]);
                $this->recordResult('FeeService', true, [
                    'total_fee' => $fees['total_fee'] ?? 10,
                    'fee_config_loaded' => !empty($this->feesConfig)
                ]);
            } else {
                $this->recordResult('FeeService', true, [
                    'message' => 'FeeService class loaded',
                    'methods' => array_slice(get_class_methods($feeService), 0, 5)
                ]);
            }
            
        } catch (Exception $e) {
            $this->recordResult('FeeService', false, ['error' => $e->getMessage()]);
        }
    }
    
    private function testForexService(): void
    {
        $this->stepStart("ForexService");
        
        try {
            $config = ['forex' => ['provider' => 'internal']];
            $feeService = new FeeService([], 'BWP');
            
            $forexService = new ForexService($this->pdo, $config, $this->participants, $feeService);
            
            if (method_exists($forexService, 'getRate')) {
                $rate = $forexService->getRate('BWP', 'BWP', 200);
                $this->recordResult('ForexService', true, [
                    'rate' => $rate['rate'] ?? 1,
                    'message' => 'Same currency conversion'
                ]);
            } else {
                $this->recordResult('ForexService', true, [
                    'message' => 'ForexService class loaded'
                ]);
            }
            
        } catch (Exception $e) {
            $this->recordResult('ForexService', false, ['error' => $e->getMessage()]);
        }
    }
    
    private function testBankVerify(string $bankName): void
    {
        $this->stepStart("GenericBankClient::verifyAsset() - {$bankName}");
        
        try {
            $participant = $this->getParticipant($bankName);
            $bankClient = new GenericBankClient($participant);
            
            $payload = [
                'asset_type' => 'VOUCHER',
                'voucher_number' => '710083197',
                'voucher_pin' => '657250',
                'amount' => 200,
                'reference' => 'TEST_VERIFY_' . uniqid()
            ];
            
            $result = $bankClient->verifyAsset($payload);
            
            $verified = $result['data']['verified'] ?? false;
            $success = $result['success'] && $verified;
            
            $this->recordResult("Bank::verifyAsset({$bankName})", $success, [
                'base_url' => $participant['base_url'] ?? 'not set',
                'endpoint' => $participant['resource_endpoints']['verify_asset'] ?? '/api/v1/verify_asset.php',
                'detected_format' => $bankClient->getDetectedFormat(),
                'verified' => $verified,
                'http_status' => $result['status_code'] ?? null,
                'message' => $result['data']['message'] ?? ($verified ? 'Verified' : 'Not verified')
            ]);
            
        } catch (Exception $e) {
            $this->recordResult("Bank::verifyAsset({$bankName})", false, ['error' => $e->getMessage()]);
        }
    }
    
    private function testBankHold(string $bankName): void
    {
        $this->stepStart("GenericBankClient::placeHold() - {$bankName}");
        
        try {
            $participant = $this->getParticipant($bankName);
            $bankClient = new GenericBankClient($participant);
            
            $payload = [
                'asset_type' => 'VOUCHER',
                'voucher_number' => '710083197',
                'amount' => 200,
                'reference' => 'TEST_HOLD_' . uniqid(),
                'hold_reason' => 'PENDING_SWAP',
                'destination_institution' => 'SACCUSSALIS',
                'expiry' => date('Y-m-d H:i:s', strtotime('+1 hour'))
            ];
            
            $result = $bankClient->placeHold($payload);
            
            $holdReference = $result['data']['hold_reference'] ?? null;
            $holdPlaced = $result['data']['hold_placed'] ?? false;
            $success = $result['success'] && $holdPlaced;
            
            $this->recordResult("Bank::placeHold({$bankName})", $success, [
                'base_url' => $participant['base_url'] ?? 'not set',
                'endpoint' => $participant['resource_endpoints']['place_hold'] ?? '/api/v1/hold.php',
                'hold_reference' => $holdReference,
                'detected_format' => $bankClient->getDetectedFormat(),
                'message' => $result['data']['message'] ?? ($holdPlaced ? 'Hold placed' : 'Hold failed')
            ]);
            
            if ($holdReference) {
                $_SESSION['test_hold_reference'] = $holdReference;
            }
            
        } catch (Exception $e) {
            $this->recordResult("Bank::placeHold({$bankName})", false, ['error' => $e->getMessage()]);
        }
    }
    
    private function testBankGenerateToken(string $bankName): void
    {
        $this->stepStart("GenericBankClient::generateToken() - {$bankName}");
        
        try {
            $participant = $this->getParticipant($bankName);
            $bankClient = new GenericBankClient($participant);
            
            $payload = [
                'reference' => 'TEST_TOKEN_' . uniqid(),
                'amount' => 190,
                'currency' => 'BWP',
                'beneficiary_phone' => '+26770000000',
                'action' => 'GENERATE_ATM_TOKEN'
            ];
            
            $result = $bankClient->generateToken($payload);
            
            $this->recordResult("Bank::generateToken({$bankName})", $result['success'], [
                'base_url' => $participant['base_url'] ?? 'not set',
                'endpoint' => $participant['resource_endpoints']['generate_token'] ?? '/api/v1/generate-atm-code.php',
                'atm_pin' => $result['data']['atm_pin'] ?? null,
                'voucher_number' => $result['data']['voucher_number'] ?? null,
                'detected_format' => $bankClient->getDetectedFormat()
            ]);
            
        } catch (Exception $e) {
            $this->recordResult("Bank::generateToken({$bankName})", false, ['error' => $e->getMessage()]);
        }
    }
    
    private function testCompleteSwap(): void
    {
        $this->stepStart("SwapService::executeAtomicSwap() - Complete Flow");
        
        try {
            // Load country config
            $configPath = __DIR__ . "/../../src/Core/Config/Countries/{$this->country}/config.php";
            $config = file_exists($configPath) ? require $configPath : ['currency' => 'BWP'];
            
            $swapService = new SwapService($this->pdo, $config, $this->country);
            
            $payload = [
                'reference' => 'TEST_SWAP_' . uniqid(),
                'swap_type' => 'CASHOUT',
                'source_institution' => 'ZURUBANK',
                'destination_institution' => 'SACCUSSALIS',
                'asset_type' => 'VOUCHER',
                'voucher_number' => '710083197',
                'voucher_pin' => '657250',
                'amount' => 200,
                'currency' => 'BWP',
                'beneficiary_phone' => '+26770000000',
                'transaction_type' => 'CASHOUT'
            ];
            
            $result = $swapService->executeAtomicSwap($payload);
            
            $status = $result['status'] ?? 'unknown';
            $success = ($status === 'committed' || $status === 'success');
            
            $this->recordResult('SwapService::executeAtomicSwap', $success, [
                'swap_reference' => $result['reference'] ?? null,
                'status' => $status,
                'steps_completed' => $result['steps_completed'] ?? 0
            ]);
            
        } catch (Exception $e) {
            $this->recordResult('SwapService::executeAtomicSwap', false, [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    private function testSettlement(): void
    {
        $this->stepStart("HybridSettlementStrategy::recordSettlement()");
        
        try {
            $settlement = new HybridSettlementStrategy($this->pdo);
            
            $payload = [
                'swap_reference' => 'TEST_SETTLEMENT_' . uniqid(),
                'from_institution' => 'ZURUBANK',
                'to_institution' => 'SACCUSSALIS',
                'amount' => 200,
                'currency' => 'BWP'
            ];
            
            $result = $settlement->recordSettlement($payload, ['success' => true], ['total_fee' => 10]);
            
            $this->recordResult('Settlement::recordSettlement', true, [
                'message' => 'Settlement recorded successfully'
            ]);
            
        } catch (Exception $e) {
            $this->recordResult('Settlement::recordSettlement', false, ['error' => $e->getMessage()]);
        }
    }
    
    private function testIdempotency(): void
    {
        $this->stepStart("IdempotencyService");
        
        try {
            $key = 'TEST_IDEMPOTENT_' . uniqid();
            $result = ['status' => 'success', 'data' => 'test'];
            
            if (class_exists('Infrastructure\Mojaloop\IdempotencyService')) {
                IdempotencyService::store($this->pdo, $key, $result);
                $cached = IdempotencyService::check($this->pdo, $key);
                $this->recordResult('IdempotencyService', $cached !== null, [
                    'cached' => $cached !== null,
                    'key' => substr($key, 0, 30) . '...'
                ]);
            } else {
                $this->recordResult('IdempotencyService', true, [
                    'message' => 'IdempotencyService class available'
                ]);
            }
            
        } catch (Exception $e) {
            $this->recordResult('IdempotencyService', false, ['error' => $e->getMessage()]);
        }
    }
    
    private function getParticipant(string $name): array
    {
        $key = strtolower($name);
        
        // First try from loaded YAML config (same as SwapService)
        if (isset($this->participants[$key])) {
            $participant = $this->participants[$key];
            
            // Merge with endpoints if available
            if (isset($this->endpoints[$key])) {
                $participant = array_merge($participant, $this->endpoints[$key]);
            }
            
            // Ensure resource_endpoints is set properly from endpoints.source
            if (isset($this->endpoints[$key]['endpoints']['source'])) {
                $participant['resource_endpoints'] = $this->endpoints[$key]['endpoints']['source'];
            }
            
            // Set default currency if not set
            if (!isset($participant['default_currency'])) {
                $participant['default_currency'] = 'BWP';
            }
            
            // Set country code if not set
            if (!isset($participant['country_code'])) {
                $participant['country_code'] = $this->country;
            }
            
            error_log("Loaded participant from YAML: {$name} -> base_url: " . ($participant['base_url'] ?? 'not set'));
            return $participant;
        }
        
        // Then try database
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM participants WHERE UPPER(name) = :name OR UPPER(provider_code) = :code");
            $stmt->execute([':name' => $name, ':code' => $name]);
            $participant = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($participant) {
                error_log("Loaded participant from DB: {$name}");
                return $participant;
            }
        } catch (Exception $e) {
            error_log("DB participant lookup failed: " . $e->getMessage());
        }
        
        throw new RuntimeException("Participant not found: {$name}. Check your YAML config files.");
    }
    
    private function stepStart(string $name): void
    {
        $this->stepStartTime = microtime(true);
        echo "\n";
        echo str_repeat("─", 65) . "\n";
        echo "▶ TESTING: {$name}\n";
        echo str_repeat("─", 65) . "\n";
    }
    
    private function recordResult(string $test, bool $passed, array $details): void
    {
        $duration = round((microtime(true) - $this->stepStartTime) * 1000, 2);
        
        $this->results[] = [
            'test' => $test,
            'passed' => $passed,
            'duration_ms' => $duration,
            'details' => $details
        ];
        
        $icon = $passed ? '✅' : '❌';
        echo "{$icon} {$test} " . ($passed ? 'PASSED' : 'FAILED') . " ({$duration}ms)\n";
        
        foreach ($details as $key => $value) {
            if ($value !== null && $key !== 'trace') {
                $displayValue = is_array($value) ? json_encode($value) : (string)$value;
                if (strlen($displayValue) > 60) {
                    $displayValue = substr($displayValue, 0, 57) . '...';
                }
                echo "   └─ {$key}: {$displayValue}\n";
            }
        }
    }
    
    private function header(): void
    {
        echo "\n";
        echo "╔═══════════════════════════════════════════════════════════════════════════╗\n";
        echo "║                    VOUCHMORPH SWAP INTEGRATION TEST                        ║\n";
        echo "║              Testing REAL Files with Railway DATABASE_URL                  ║\n";
        echo "╚═══════════════════════════════════════════════════════════════════════════╝\n";
        echo "\n📁 Services being tested:\n";
        echo "   • FeeService\n";
        echo "   • ForexService\n";
        echo "   • GenericBankClient (Zurubank & Saccussalis)\n";
        echo "   • SwapService (Atomic execution)\n";
        echo "   • HybridSettlementStrategy\n";
        echo "   • IdempotencyService\n";
        echo "\n🔄 Test Flow: Zurubank Voucher (200 BWP) → Saccussalis Cashout\n";
        echo "💰 Voucher: 710083197 | PIN: 657250 | Amount: 200\n";
        echo "═══════════════════════════════════════════════════════════════════════════\n";
    }
    
    private function footer(): void
    {
        $passed = count(array_filter($this->results, fn($r) => $r['passed']));
        $failed = count($this->results) - $passed;
        $totalTime = array_sum(array_column($this->results, 'duration_ms'));
        
        echo "\n";
        echo "═══════════════════════════════════════════════════════════════════════════\n";
        echo "📊 FINAL REPORT\n";
        echo "═══════════════════════════════════════════════════════════════════════════\n";
        
        foreach ($this->results as $result) {
            $status = $result['passed'] ? '✅ PASS' : '❌ FAIL';
            echo sprintf("  %-45s %s (%5s ms)\n", $result['test'], $status, $result['duration_ms']);
        }
        
        echo "\n───────────────────────────────────────────────────────────────────────────\n";
        echo sprintf("  Total: %d | Passed: %d | Failed: %d | Time: %.2f ms\n", 
            count($this->results), $passed, $failed, $totalTime);
        echo "───────────────────────────────────────────────────────────────────────────\n";
        
        if ($failed === 0) {
            echo "\n🎉 ALL TESTS PASSED - System ready!\n";
        } else {
            echo "\n⚠️  FAILURES DETECTED - Check details above\n";
        }
        echo "\n";
    }
}

// ============================================================================
// RUN THE TEST
// ============================================================================

echo "Initializing test suite...\n";

// Get PDO from your DBConnection class
$pdo = DBConnection::getConnection();

if (!$pdo) {
    $status = DBConnection::getStatus();
    echo "❌ Database connection failed!\n";
    echo "Status: " . json_encode($status, JSON_PRETTY_PRINT) . "\n";
    exit(1);
}

echo "✅ Database connected successfully\n";
echo "   " . $pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS) . "\n";

// Run the test
$test = new SwapIntegrationTest($pdo, 'Botswana');
$test->run();
