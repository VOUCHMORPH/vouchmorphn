<?php
header('Content-Type: application/json');
$keys = [];
$possible = ['API_KEY_SYSTEM', 'VOUCHMORPH_API_KEY', 'UPSTREAM_ZURUBANK_KEY', 'UPSTREAM_CAZACOM_KEY', 'UPSTREAM_SACCUSSALIS_KEY', 'API_KEY_ZURUBANK', 'API_KEY_SACCUSSALIS', 'API_KEY_CAZACOM', 'SYSTEM_API_KEY'];
foreach ($possible as $key) {
    $val = getenv($key);
    if ($val) {
        $keys[$key] = substr($val, 0, 10) . '...' . substr($val, -5);
    }
}
echo json_encode(['available_keys' => $keys, 'all_env_keys' => array_keys(array_filter($_SERVER, function($k) { return preg_match('/KEY|API|TOKEN/i', $k); }, ARRAY_FILTER_USE_KEY))]);
