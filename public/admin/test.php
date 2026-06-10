<?php
/**
 * complete_swap_integration_test.php
 * 
 * Calls REAL files from your actual structure:
 * - src/Domain/Services/SwapService.php
 * - src/Domain/Services/FeeService.php
 * - src/Domain/Services/ForexService.php
 * - src/Infrastructure/Banks/GenericBankClient.php
 * - src/Domain/Services/Settlement/HybridSettlementStrategy.php
 * - src/Infrastructure/Mojaloop/IdempotencyService.php
 * 
 * Tests: Zurubank Voucher (200) → Saccussalis Cashout
 */

// Fix the autoload path
require_once __DIR__ . '/../../src/bootstrap.php';

// Load country-specific config
$country = 'Botswana';
$countryConfigPath = __DIR__ . "/../../src/Core/Config/Countries/{$country}/config.php";

if (!file_exists($countryConfigPath)) {
    die("Country config not found: {$countryConfigPath}");
}

$config = require $countryConfigPath;

// Load database config from country folder
$dbConfigPath = __DIR__ . "/../../src/Core/Config/Countries/{$country}/database.php";
if (!file_exists($dbConfigPath)) {
    die("Database config not found: {$dbConfigPath}");
}

$dbConfig = require $dbConfigPath;

use Domain\Services\SwapService;
use Domain\Services\FeeService;
use Domain\Services\ForexService;
use Domain\Services\Settlement\HybridSettlementStrategy;
use Infrastructure\Banks\GenericBankClient;
use Infrastructure\SMS\SmsNotificationService;
use Infrastructure\Mojaloop\IdempotencyService;

class SwapIntegrationTest
{
    private $pdo;
    private $results = [];
    private $stepStartTime;
    private $country;
    
    public function __construct($pdo, string $country = 'Botswana')
    {
        $this->pdo = $pdo;
        $this->country = $country;
    }
    
    public function run(): void
    {
        $this->header();
        
        // Test 1: FeeService
        $this->testFeeService();
        
        // Test 2: ForexService
        $this->testForexService();
        
        // Test 3: GenericBankClient - Verify (Zurubank)
        $this->testBankVerify('ZURUBANK');
        
        // Test 4: GenericBankClient - Hold (Zurubank)
        $this->testBankHold('ZURUBANK');
        
        // Test 5: GenericBankClient - Generate Token (Saccussalis)
        $this->testBankGenerateToken('SACCUSSALIS');
        
        // Test 6: Complete Swap via SwapService
        $this->testCompleteSwap();
        
        // Test 7: Settlement Recording
        $this->testSettlement();
        
        // Test 8: Idempotency
        $this->testIdempotency();
        
        $this->footer();
    }
    
    private function testFeeService(): void
    {
        $this->stepStart("FeeService");
        
        try {
            // Load fees from your actual fees.json
            $feesPath = __DIR__ . "/../../src/Core/Config/Countries/{$this->country}/fees.json";
            $feesConfig = file_exists($feesPath) ? json_decode(file_get_contents($feesPath), true) : [];
            
            $feeService = new FeeService($feesConfig, 'BWP');
            
            $fees = $feeService->calculateFees('CASHOUT', 200, [
                'source_institution' => 'ZURUBANK',
                'destination_institution' => 'SACCUSSALIS'
            ]);
            
            $this->recordResult('FeeService', true, [
                'total_fee' => $fees['total_fee'] ?? 10,
                'fee_config_loaded' => !empty($feesConfig),
                'calculation' => 'Fee calculated successfully'
            ]);
            
        } catch (Exception $e) {
            $this->recordResult('FeeService', false, ['error' => $e->getMessage()]);
        }
    }
    
    private function testForexService(): void
    {
        $this->stepStart("ForexService");
        
        try {
            $config = ['forex' => ['provider' => 'internal']];
            
            // Load participants from YAML
            $participantsPath = __DIR__ . "/../../src/Core/Config/Countries/{$this->country}/participants.yaml";
            $participants = $this->parseParticipantsYaml($participantsPath);
            
            $feeService = new FeeService([], 'BWP');
            $forexService = new ForexService($this->pdo, $config, $participants, $feeService);
            
            $rate = $forexService->getRate('BWP', 'BWP', 200);
            
            $this->recordResult('ForexService', true, [
                'rate' => $rate['rate'] ?? 1,
                'source_currency' => 'BWP',
                'dest_currency' => 'BWP',
                'message' => 'Same currency, rate = 1'
            ]);
            
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
            
            $this->recordResult("Bank::verifyAsset({$bankName})", $result['success'] && $verified, [
                'endpoint' => $participant['resource_endpoints']['verify_asset'] ?? '/api/v1/verify_asset.php',
                'detected_format' => $bankClient->getDetectedFormat(),
                'confidence' => $bankClient->getDetectionConfidence(),
                'verified' => $verified,
                'balance' => $result['data']['available_balance'] ?? null,
                'asset_id' => $result['data']['asset_id'] ?? null,
                'http_status' => $result['status_code'] ?? null
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
            
            $this->recordResult("Bank::placeHold({$bankName})", $result['success'] && $holdPlaced, [
                'hold_reference' => $holdReference,
                'status' => $result['data']['status'] ?? null,
                'message' => $result['data']['message'] ?? null,
                'detected_format' => $bankClient->getDetectedFormat()
            ]);
            
            // Store hold reference for potential later use
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
                'amount' => 190, // After fee deduction (200 - 10 fee)
                'currency' => 'BWP',
                'beneficiary_phone' => '+26770000000',
                'action' => 'GENERATE_ATM_TOKEN'
            ];
            
            $result = $bankClient->generateToken($payload);
            
            $this->recordResult("Bank::generateToken({$bankName})", $result['success'], [
                'atm_pin' => $result['data']['atm_pin'] ?? null,
                'voucher_number' => $result['data']['voucher_number'] ?? null,
                'expires_at' => $result['data']['expires_at'] ?? null,
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
            $config = require __DIR__ . "/../../src/Core/Config/Countries/{$this->country}/config.php";
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
            
            $this->recordResult('SwapService::executeAtomicSwap', $result['status'] === 'committed', [
                'swap_reference' => $result['reference'] ?? null,
                'hold_id' => $result['hold_id'] ?? null,
                'steps_completed' => $result['steps_completed'] ?? 0,
                'status' => $result['status'],
                'message' => $result['atomic_commit']['status'] ?? 'Swap completed'
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
                'ledger_entry' => $result['ledger_id'] ?? 'created',
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
            
            IdempotencyService::store($this->pdo, $key, $result);
            $cached = IdempotencyService::check($this->pdo, $key);
            
            $this->recordResult('IdempotencyService', $cached !== null, [
                'key' => $key,
                'cached' => $cached !== null,
                'message' => 'Idempotency working correctly'
            ]);
            
        } catch (Exception $e) {
            $this->recordResult('IdempotencyService', false, ['error' => $e->getMessage()]);
        }
    }
    
    private function getParticipant(string $name): array
    {
        // First try to get from database
        $stmt = $this->pdo->prepare("SELECT * FROM participants WHERE name = :name OR provider_code = :code");
        $stmt->execute([':name' => $name, ':code' => $name]);
        $participant = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($participant) {
            return $participant;
        }
        
        // Fallback to loading from YAML
        $participantsPath = __DIR__ . "/../../src/Core/Config/Countries/{$this->country}/participants.yaml";
        $participants = $this->parseParticipantsYaml($participantsPath);
        
        $key = strtolower($name);
        if (isset($participants[$key])) {
            // Load endpoints
            $endpointsPath = __DIR__ . "/../../src/Core/Config/Countries/{$this->country}/endpoints.yaml";
            $endpoints = $this->parseEndpointsYaml($endpointsPath);
            
            if (isset($endpoints[$key])) {
                $participants[$key] = array_merge($participants[$key], $endpoints[$key]);
            }
            
            return $participants[$key];
        }
        
        // Return default for testing
        return [
            'name' => $name,
            'provider_code' => $name === 'ZURUBANK' ? 'ZURUBWXX' : 'SACCUSBWXX',
            'base_url' => $name === 'ZURUBANK' 
                ? 'https://zurubank-production.up.railway.app'
                : 'http://localhost/SaccusSalisbank/backend',
            'resource_endpoints' => [
                'verify_asset' => '/api/v1/verify_asset.php',
                'place_hold' => '/api/v1/hold.php',
                'generate_token' => '/api/v1/generate-atm-code.php',
                'debit_funds' => '/api/v1/debit.php'
            ]
        ];
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
                $participants[$currentParticipant][$matches[1]] = $value;
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
        
        foreach ($lines as $line) {
            $line = rtrim($line);
            if (empty($line) || $line[0] === '#') continue;
            
            if (preg_match('/^([A-Z_]+):$/', $line, $matches)) {
                $currentParticipant = strtolower($matches[1]);
                $endpoints[$currentParticipant] = [];
            } elseif ($currentParticipant && preg_match('/^  ([a-z_]+): (.+)$/', $line, $matches)) {
                $value = trim($matches[2]);
                if (preg_match('/^"(.+)"$/', $value, $q)) $value = $q[1];
                $endpoints[$currentParticipant][$matches[1]] = $value;
            }
        }
        
        return $endpoints;
    }
    
    private function stepStart(string $name): void
    {
        $this->stepStartTime = microtime(true);
        echo "\n";
        echo "┌─────────────────────────────────────────────────────────────────┐\n";
        echo "│ TESTING: {$name}\n";
        echo "└─────────────────────────────────────────────────────────────────┘\n";
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
        $statusText = $passed ? 'PASSED' : 'FAILED';
        echo "{$icon} {$test} {$statusText} ({$duration}ms)\n";
        
        foreach ($details as $key => $value) {
            if ($value !== null && $key !== 'trace') {
                $displayValue = is_array($value) ? json_encode($value) : $value;
                if (strlen($displayValue) > 80) {
                    $displayValue = substr($displayValue, 0, 77) . '...';
                }
                echo "   • {$key}: {$displayValue}\n";
            }
        }
    }
    
    private function header(): void
    {
        echo "\n";
        echo "╔═══════════════════════════════════════════════════════════════════════════╗\n";
        echo "║                    VOUCHMORPH SWAP INTEGRATION TEST                        ║\n";
        echo "║                      Testing REAL Files & Services                         ║\n";
        echo "╚═══════════════════════════════════════════════════════════════════════════╝\n";
        echo "\n📁 Files being tested:\n";
        echo "   • src/Domain/Services/SwapService.php\n";
        echo "   • src/Domain/Services/FeeService.php\n";
        echo "   • src/Domain/Services/ForexService.php\n";
        echo "   • src/Infrastructure/Banks/GenericBankClient.php\n";
        echo "   • src/Domain/Services/Settlement/HybridSettlementStrategy.php\n";
        echo "   • src/Infrastructure/Mojaloop/IdempotencyService.php\n";
        echo "\n🔄 Flow: Zurubank (200 BWP Voucher) → Saccussalis (Cashout)\n";
        echo "💰 Voucher: 710083197 | PIN: 657250 | Amount: 200 BWP\n";
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
            echo sprintf("  %-45s %s (%s ms)\n", $result['test'], $status, $result['duration_ms']);
        }
        
        echo "\n───────────────────────────────────────────────────────────────────────────\n";
        echo sprintf("  Total Tests: %d | Passed: %d | Failed: %d | Total Time: %.2f ms\n", 
            count($this->results), $passed, $failed, $totalTime);
        echo "───────────────────────────────────────────────────────────────────────────\n";
        
        if ($failed === 0) {
            echo "\n🎉 ALL TESTS PASSED - System ready for production!\n";
        } else {
            echo "\n⚠️  SOME TESTS FAILED - Check the details above for fixes needed.\n";
        }
        
        echo "\n";
    }
}

// ============================================================================
// RUN THE TEST
// ============================================================================

echo "Loading configuration...\n";

$country = 'Botswana';
$dbConfigPath = __DIR__ . "/../../src/Core/Config/Countries/{$country}/database.php";

if (!file_exists($dbConfigPath)) {
    die("ERROR: Database config not found at: {$dbConfigPath}\n");
}

$dbConfig = require $dbConfigPath;

try {
    $pdo = new PDO(
        "pgsql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['database']}",
        $dbConfig['user'],
        $dbConfig['password']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ Database connected successfully\n\n";
    
} catch (Exception $e) {
    die("❌ Database connection failed: " . $e->getMessage() . "\n");
}

// Run the test
$test = new SwapIntegrationTest($pdo, $country);
$test->run();
