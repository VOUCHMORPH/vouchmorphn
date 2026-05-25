<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('PROJECT_ROOT', dirname(__DIR__, 2));

require_once PROJECT_ROOT . '/src/Core/Config/SystemCountry.php';
require_once PROJECT_ROOT . '/src/Core/Config/LoadCountry.php';

echo "<h1>Configuration Test</h1>";

// Test SystemCountry
echo "<h2>SystemCountry.php output:</h2>";
$countryMeta = require PROJECT_ROOT . '/src/Core/Config/SystemCountry.php';
echo "<pre>";
print_r($countryMeta);
echo "</pre>";

// Test LoadCountry
echo "<h2>LoadCountry::getConfig() output:</h2>";
$config = \Core\Config\LoadCountry::getConfig();

echo "<h3>Basic Info:</h3>";
echo "Country: " . ($config['country'] ?? 'NOT SET') . "<br>";
echo "Country Code: " . ($config['country_code'] ?? 'NOT SET') . "<br>";

echo "<h3>Fees:</h3>";
echo "Fees present: " . (isset($config['fees']) ? 'YES' : 'NO') . "<br>";
if (isset($config['fees'])) {
    echo "<pre>";
    print_r(array_keys($config['fees']));
    echo "</pre>";
}

echo "<h3>ATM Notes:</h3>";
echo "ATM Notes present: " . (isset($config['atm_notes']) ? 'YES' : 'NO') . "<br>";
if (isset($config['atm_notes'])) {
    echo "<pre>";
    print_r(array_keys($config['atm_notes']));
    echo "</pre>";
}

echo "<h3>Card Config:</h3>";
echo "Card Config present: " . (isset($config['card_config']) ? 'YES' : 'NO') . "<br>";

echo "<h3>Participants:</h3>";
echo "Participants present: " . (isset($config['participants']) ? 'YES' : 'NO') . "<br>";
if (isset($config['participants'])) {
    echo "<pre>";
    print_r(array_keys($config['participants']));
    echo "</pre>";
}

echo "<h3>Database Config:</h3>";
echo "Database swap config present: " . (isset($config['db']['swap']) ? 'YES' : 'NO') . "<br>";
