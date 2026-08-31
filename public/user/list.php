<?php

$owner  = "VOUCHMORPH";
$repo   = "vouchmorphn";
$branch = "main";

// Put your token here if the repo is private or you hit rate limits
$token = "";

function githubRequest(string $url): array
{
    global $token;

    $headers = [
        "User-Agent: VouchMorph-App",
        "Accept: application/vnd.github+json"
    ];

    if (!empty($token)) {
        $headers[] = "Authorization: Bearer " . $token;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);

    curl_close($ch);

    if ($response === false || $curlErr) {
        throw new Exception("cURL error: " . $curlErr);
    }

    $data = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("JSON decode failed: " . json_last_error_msg());
    }

    if ($httpCode >= 400) {
        $message = is_array($data) && isset($data['message']) ? $data['message'] : 'GitHub API error';
        throw new Exception("GitHub returned HTTP {$httpCode}: {$message}");
    }

    return $data;
}

try {
    // Faster and safer than recursive /contents calls
    $url = "https://api.github.com/repos/{$owner}/{$repo}/git/trees/{$branch}?recursive=1";
    $data = githubRequest($url);

    if (!isset($data['tree']) || !is_array($data['tree'])) {
        throw new Exception("Invalid tree response from GitHub.");
    }

    $files = [];

    foreach ($data['tree'] as $item) {
        if (is_array($item) && ($item['type'] ?? '') === 'blob') {
            $files[] = $item['path'];
        }
    }

    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success',
        'count'  => count($files),
        'files'  => $files
    ], JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
