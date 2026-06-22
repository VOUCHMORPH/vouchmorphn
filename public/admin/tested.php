<?php

echo "<pre>";

require_once '/var/www/html/vendor/autoload.php';

echo "Loaded classes:\n\n";

print_r(scandir('/var/www/html/vendor'));

echo "\n\nPSR exists: ";

echo is_dir('/var/www/html/vendor/psr')
    ? "YES\n"
    : "NO\n";

echo "vlucas exists: ";

echo is_dir('/var/www/html/vendor/vlucas')
    ? "YES\n"
    : "NO\n";

echo "</pre>";
