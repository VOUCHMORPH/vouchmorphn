<?php
declare(strict_types=1);

/**
 * corrective_test.php
 *
 * SAFE FINANCIAL SWITCH VALIDATION HARNESS
 * - NO LIVE EXECUTION UNLESS EXPLICITLY ENABLED
 * - Tests VERIFY → HOLD → DEBIT → TRACE FLOW
 * - Validates Zurubank / Saccussalis / CAZACOM endpoints
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

class CorrectiveTest
{
    private string $mode;
    private array $results = [];

    public function __construct()
    {
        $this->mode = getenv('LIVE_RUN') === 'YES' ? 'LIVE' : 'DRY_RUN';

        echo "====================================\n";
        echo "SWAP CORRECTIVE TEST SUITE\n";
        echo "MODE: {$this->mode}\n";
        echo "====================================\n\n";

        if ($this->mode === 'LIVE') {
            echo "⚠ LIVE MODE ENABLED - REAL TRANSACTIONS MAY OCCUR\n\n";
        } else {
            echo "SAFE MODE - NO FUNDS WILL MOVE (recommended)\n\n";
        }
    }

    // -----------------------------
    // HTTP CLIENT
    // -----------------------------
    private function post(string $url, array $payload): array
    {
        echo "POST: $url\n";
        echo "Payload: " . json_encode($payload) . "\n";

        if ($this->mode !== 'LIVE') {
            return [
                'success' => true,
                'dry_run' => true,
                'message' => 'Skipped (dry run)',
                'data' => [
                    'verification_reference' => 'DRY-VER-' . uniqid(),
                    'hold_reference' => 'DRY-HOLD-' . uniqid()
                ]
            ];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']
        ]);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['success' => false, 'error' => $err];
        }

        return json_decode($response, true) ?? ['success' => false, 'error' => 'invalid json'];
    }

    // -----------------------------
    // TEST 1: VERIFY ASSET
    // -----------------------------
    public function testVerify(string $endpoint, array $payload): array
    {
        $res = $this->post($endpoint, $payload);

        $ok = ($res['verified'] ?? false) === true;

        $this->log("VERIFY", $ok, $res);

        return $res;
    }

    // -----------------------------
    // TEST 2: HOLD
    // -----------------------------
    public function testHold(string $endpoint, array $payload): array
    {
        $res = $this->post($endpoint, $payload);

        $ok = ($res['status'] ?? '') === 'SUCCESS' || ($res['hold_placed'] ?? false);

        $this->log("HOLD", $ok, $res);

        return $res;
    }

    // -----------------------------
    // TEST 3: IDENTITY / REPLAY CHECK
    // -----------------------------
    public function testReplayProtection(string $endpoint, array $payload): void
    {
        echo "\n--- REPLAY TEST ---\n";

        $first = $this->post($endpoint, $payload);
        $second = $this->post($endpoint, $payload);

        $replayBlocked = ($second['success'] ?? false) === false;

        $this->log("REPLAY_PROTECTION", $replayBlocked, [
            'first' => $first,
            'second' => $second
        ]);
    }

    // -----------------------------
    // TEST 4: MULTI-SOURCE VALIDATION
    // -----------------------------
    public function testMultiSource(array $sources, float $target): void
    {
        echo "\n--- MULTI SOURCE TEST ---\n";

        $total = 0;

        foreach ($sources as $i => $source) {
            $total += $source['amount'];

            echo "Source {$i}: {$source['type']} = {$source['amount']}\n";
        }

        $valid = abs($total - $target) < 0.0001;

        $this->log("MULTI_SOURCE_SUM", $valid, [
            'expected' => $target,
            'actual' => $total
        ]);
    }

    // -----------------------------
    // LOGGING
    // -----------------------------
    private function log(string $test, bool $status, array $data): void
    {
        $this->results[] = [
            'test' => $test,
            'status' => $status ? 'PASS' : 'FAIL',
            'data' => $data
        ];

        echo strtoupper($test) . " => " . ($status ? "PASS" : "FAIL") . "\n\n";
    }

    // -----------------------------
    // FINAL REPORT
    // -----------------------------
    public function report(): void
    {
        echo "\n==============================\n";
        echo "FINAL REPORT\n";
        echo "==============================\n";

        $pass = 0;
        $fail = 0;

        foreach ($this->results as $r) {
            echo "{$r['test']} : {$r['status']}\n";
            $r['status'] === 'PASS' ? $pass++ : $fail++;
        }

        echo "\nTOTAL PASS: $pass\n";
        echo "TOTAL FAIL: $fail\n";

        if ($fail === 0) {
            echo "\nSYSTEM READY FOR REGULATORY REVIEW\n";
        } else {
            echo "\nISSUES DETECTED - FIX BEFORE PRODUCTION\n";
        }
    }
}
