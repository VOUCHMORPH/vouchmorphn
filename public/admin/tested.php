<?php

echo "<h2>Composer Diagnostic</h2>";

echo "<pre>";

echo "PHP: " . PHP_VERSION . "\n\n";

echo "Root: " . dirname(__DIR__, 3) . "\n\n";

$vendor = dirname(__DIR__,3) . '/vendor';

echo "Vendor directory:\n";
echo $vendor . "\n";

echo file_exists($vendor)
    ? "EXISTS\n\n"
    : "MISSING\n\n";

$autoload = $vendor . '/autoload.php';

echo "Autoload:\n";

if (file_exists($autoload)) {

    echo "FOUND\n";

    require_once $autoload;

} else {

    die("MISSING");
}

echo "\n";

echo "PSR Logger:\n";

if (interface_exists('Psr\\Log\\LoggerInterface')) {

    echo "FOUND\n";

} else {

    echo "MISSING\n";
}

echo "\n";

echo "Installed packages:\n";

$installed = $vendor.'/composer/installed.php';

if (file_exists($installed)) {

    $packages = require $installed;

    if (isset($packages['versions'])) {

        foreach ($packages['versions'] as $name => $info) {

            echo $name."\n";
        }

    } else {

        print_r($packages);
    }

} else {

    echo "installed.php missing\n";
}

echo "</pre>";
