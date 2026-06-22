<?php

echo "<pre>";

echo "CURRENT FILE:\n";
echo __FILE__ . "\n\n";

echo "CURRENT DIR:\n";
echo __DIR__ . "\n\n";

echo "Searching...\n\n";

$path = __DIR__;

while ($path !== '/') {

    echo $path . "\n";

    if (file_exists($path . '/composer.json')) {

        echo "\nPROJECT ROOT FOUND:\n";

        echo $path . "\n\n";

        echo "composer.json: YES\n";

        echo "composer.lock: ";

        echo file_exists($path.'/composer.lock')
            ? "YES\n"
            : "NO\n";

        echo "vendor: ";

        echo file_exists($path.'/vendor')
            ? "YES\n"
            : "NO\n";

        echo "autoload: ";

        echo file_exists($path.'/vendor/autoload.php')
            ? "YES\n"
            : "NO\n";

        echo "psr/log: ";

        echo interface_exists('Psr\\Log\\LoggerInterface')
            ? "YES\n"
            : "NOT LOADED\n";

        break;
    }

    $path = dirname($path);
}

echo "</pre>";
