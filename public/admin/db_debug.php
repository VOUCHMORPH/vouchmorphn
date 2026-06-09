<?php
header('Content-Type: application/json');

echo json_encode([
    'drivers' => PDO::getAvailableDrivers(),
    'env_database_url' => getenv('DATABASE_URL') ? 'set' : 'missing',
    'extensions' => get_loaded_extensions()
], JSON_PRETTY_PRINT);
