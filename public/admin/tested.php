<?php

echo "<pre>";

$vendor = '/var/www/html/vendor';

echo "Vendor exists: ";
echo is_dir($vendor) ? "YES\n" : "NO\n";

echo "\nContents:\n";

print_r(scandir($vendor));

echo "\nComposer folder:\n";

if (is_dir($vendor.'/composer')) {
    print_r(scandir($vendor.'/composer'));
} else {
    echo "composer folder missing";
}

echo "</pre>";
