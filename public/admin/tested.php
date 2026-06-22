<?php

echo "<pre>";

$root = '/var/www/html';

require_once $root . '/vendor/autoload.php';

echo "Autoload loaded\n\n";

echo "Logger interface: ";

echo interface_exists('Psr\\Log\\LoggerInterface')
    ? "YES\n"
    : "NO\n";

echo "\n";

echo "Installed packages:\n";

if (file_exists($root.'/vendor/composer/installed.php')) {

    $packages = require $root.'/vendor/composer/installed.php';

    print_r(array_keys($packages['versions']));

} else {

    echo "installed.php missing";
}

echo "</pre>";
