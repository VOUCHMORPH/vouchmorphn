<?php
// Handle OAuth callback from bank
$code = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';

// Exchange code for access token
$ch = curl_init('https://your-bank.com/Backend/api/v1/oauth/token.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'grant_type' => 'authorization_code',
    'code' => $code,
    'client_id' => 'VOUCHMORPH_APP_ID',
    'client_secret' => 'YOUR_BANK_SECRET'
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

$response = curl_exec($ch);
$tokenData = json_decode($response, true);

// Store access token in VouchMorph database
$stmt = $db->prepare("INSERT INTO user_bank_tokens (user_id, access_token, refresh_token, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))");
$stmt->execute([$userId, $tokenData['access_token'], $tokenData['refresh_token']]);

header('Location: user_dashboard.php?source_linked=success');
