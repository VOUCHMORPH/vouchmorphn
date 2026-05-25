<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('PROJECT_ROOT', dirname(__DIR__, 2));

$feesFile = PROJECT_ROOT . '/src/Core/Config/Countries/Botswana/fees.json';

echo "<h1>Check fees.json</h1>";
echo "<p>File path: " . $feesFile . "</p>";
echo "<p>File exists: " . (file_exists($feesFile) ? 'YES' : 'NO') . "</p>";

if (file_exists($feesFile)) {
    $content = file_get_contents($feesFile);
    echo "<p>File size: " . strlen($content) . " bytes</p>";
    
    $decoded = json_decode($content, true);
    echo "<p>JSON valid: " . (json_last_error() === JSON_ERROR_NONE ? 'YES' : 'NO') . "</p>";
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "<p style='color:red'>JSON Error: " . json_last_error_msg() . "</p>";
    }
    
    echo "<h2>File content:</h2>";
    echo "<pre>" . htmlspecialchars($content) . "</pre>";
}
