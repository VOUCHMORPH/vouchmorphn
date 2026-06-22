<?php

echo "<pre>";

echo "PHP: " . PHP_VERSION . "\n\n";

echo "Current dir:\n";
echo __DIR__ . "\n\n";

echo "Vendor autoload:\n";

$autoload = __DIR__ . '/../../vendor/autoload.php';

echo $autoload . "\n";

echo file_exists($autoload)
    ? "FOUND\n"
    : "MISSING\n";

echo "\n";

if (file_exists($autoload)) {
    require_once $autoload;
}

echo "Composer installed:\n";

echo class_exists(\Composer\Autoload\ClassLoader::class)
    ? "YES\n"
    : "NO\n";

echo "\n";

echo "PSR logger:\n";

echo interface_exists(\Psr\Log\LoggerInterface::class)
    ? "FOUND\n"
    : "MISSING\n";

echo "\n";

echo "Installed packages:\n";

$installed = __DIR__ . '/../../vendor/composer/installed.json';

echo file_exists($installed)
    ? "installed.json exists\n"
    : "installed.json missing\n";

echo "</pre>";
