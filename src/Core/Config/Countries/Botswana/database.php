<?php
// src/Core/Config/Countries/Botswana/database.php

return [
    // NO connection settings here - DATABASE_URL is the only source of truth
    // These are only for application-level database behavior
    'charset' => 'utf8',
    'collation' => 'utf8_unicode_ci',
    'prefix' => '',
    'pool_size' => 10,
    'timeout' => 30,
    'retry_attempts' => 3,
];
