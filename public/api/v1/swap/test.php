<?php
// test_participants.php - Place in /var/www/html/public/api/v1/swap/test_participants.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<pre>";
echo "========================================\n";
echo "PARTICIPANTS LOADING TEST\n";
echo "========================================\n\n";

// Path to participants.yaml
$participantsPath = __DIR__ . '/../../../../src/Core/Config/Countries/Botswana/participants.yaml';

echo "Looking for participants at: " . $participantsPath . "\n";
echo "File exists: " . (file_exists($participantsPath) ? 'YES' : 'NO') . "\n\n";

if (!file_exists($participantsPath)) {
    die("File not found!\n");
}

// Read raw content
$content = file_get_contents($participantsPath);
echo "Raw content length: " . strlen($content) . " bytes\n\n";

// Method 1: Simple regex to find participant names
echo "Method 1 - Simple regex (^  ([A-Z_]+):)\n";
echo "----------------------------------------\n";
preg_match_all('/^  ([A-Z_]+):/m', $content, $matches);
print_r($matches[1]);

echo "\nMethod 2 - Parse full YAML structure\n";
echo "----------------------------------------\n";

function parseParticipantsYaml($content) {
    $participants = [];
    $lines = explode("\n", $content);
    $currentParticipant = null;
    $inParticipants = false;
    
    foreach ($lines as $line) {
        $line = rtrim($line);
        if (empty($line) || $line[0] === '#') continue;
        
        // Find participants section
        if (preg_match('/^participants:$/', $line)) {
            $inParticipants = true;
            echo "Found participants section\n";
            continue;
        }
        
        if (!$inParticipants) continue;
        
        // Match participant name (2 spaces, then uppercase letters/underscore, then colon)
        if (preg_match('/^  ([A-Z_]+):$/', $line, $matches)) {
            $currentParticipant = $matches[1];
            $participants[$currentParticipant] = [];
            echo "Found participant: {$currentParticipant}\n";
            continue;
        }
        
        // Match properties (4 spaces)
        if ($currentParticipant && preg_match('/^    ([a-z_]+): (.+)$/', $line, $matches)) {
            $key = $matches[1];
            $value = trim($matches[2]);
            // Remove quotes
            if (preg_match('/^"(.+)"$/', $value, $q)) $value = $q[1];
            if (preg_match("/^'(.+)'$/", $value, $q)) $value = $q[1];
            $participants[$currentParticipant][$key] = $value;
            echo "  {$key}: {$value}\n";
            continue;
        }
        
        // Match asset_types list
        if ($currentParticipant && preg_match('/^    asset_types:$/', $line)) {
            $participants[$currentParticipant]['asset_types'] = [];
            continue;
        }
        
        // Match items in asset_types list
        if ($currentParticipant && isset($participants[$currentParticipant]['asset_types']) && preg_match('/^      - (.+)$/', $line, $matches)) {
            $participants[$currentParticipant]['asset_types'][] = trim($matches[1]);
            echo "  asset_type: " . trim($matches[1]) . "\n";
        }
    }
    
    return $participants;
}

$participants = parseParticipantsYaml($content);

echo "\nMethod 2 Result:\n";
echo "----------------------------------------\n";
print_r($participants);

echo "\nMethod 3 - Using existing SwapService (if available)\n";
echo "----------------------------------------\n";

// Try to load using the actual SwapService
require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../../../../src/Domain/Services/SwapService.php';

// Create a mock SwapService just to test participant loading
$mockSwapService = new ReflectionClass('Domain\Services\SwapService');
$loadConfigMethod = $mockSwapService->getMethod('loadConfiguration');
$loadConfigMethod->setAccessible(true);

// We need a PDO object, but we can't create one without DB
// Just test the parseYaml method directly
$parseYamlMethod = $mockSwapService->getMethod('parseYaml');
$parseYamlMethod->setAccessible(true);

// Create minimal instance
$swapService = new \Domain\Services\SwapService(null, [], 'Botswana');

$parsed = $parseYamlMethod->invoke($swapService, $participantsPath);
echo "Parsed participants count: " . count($parsed) . "\n";
echo "Keys: " . implode(', ', array_keys($parsed)) . "\n";

echo "\n========================================\n";
echo "TEST COMPLETE\n";
echo "========================================\n";
echo "</pre>";
