<?php
require_once __DIR__ . '/../../vendor/autoload.php';

$db = new PDO(getenv('DATABASE_URL'));

$test = new SwapServiceFullTestSuite($db);
$test->run();
