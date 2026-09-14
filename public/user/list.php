<?php
declare(strict_types=1);

/**
 * Lists files in a GitHub repository via the git/trees API.
 *
 * Query params:
 *   ?path=public/user/   filter results to a path prefix
 *   ?branch=some-branch  override the branch
 *   ?debug=1             include upstream HTTP code + rate limit info
 *
 * Token: set GITHUB_TOKEN as a Railway environment variable.
 * Never hardcode it -- this file is served publicly.
 */

$owner  = 'VOUCHMORPH';
$repo   = 'vouchmorphn';
$branch = $_GET['branch'] ?? 'main';

$token = getenv('GITHUB_TOKEN') ?: (getenv('GH_TOKEN') ?: '');
$debug = isset($_GET['debug']);

// ---------------------------------------------------------------- transport

/**
 * Performs a GitHub API request. Does NOT throw on HTTP errors -- returns the
 * status so the caller can react differently to 404 vs 403 vs 401.
 *
 * @return array{code:int, json:mixed, raw:string, headers:array<string,string>}
 */
function ghRequest(string $url, string $token): array
{
    $headers = [
        'User-Agent: VouchMorph-App',
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
    ];
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $respHeaders = [];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);

        $raw     = curl_exec($ch);
        $code    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Network error contacting GitHub: ' . $curlErr);
        }
    } else {
        // Fallback if the PHP image was built without ext-curl.
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => implode("\r\n", $headers),
                'timeout'       => 30,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            throw new RuntimeException('Network error contacting GitHub (stream wrapper).');
        }
        $code = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $code = (int) $m[1];
            } elseif (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $respHeaders[strtolower(trim($k))] = trim($v);
            }
        }
    }

    return [
        'code'    => $code,
        'json'    => json_decode($raw, true),
        'raw'     => $raw,
        'headers' => $respHeaders,
    ];
}

function respond(int $status, array $payload): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// ------------------------------------------------------------------- request

try {
    $base = "https://api.github.com/repos/{$owner}/{$repo}";
    $res  = ghRequest("{$base}/git/trees/" . rawurlencode($branch) . '?recursive=1', $token);

    // A 404 here is ambiguous: wrong branch, private repo, or no such repo.
    // Ask for the repo itself to find out which.
    if ($res['code'] === 404) {
        $meta = ghRequest($base, $token);

        if ($meta['code'] === 200 && !empty($meta['json']['default_branch'])) {
            $branch = $meta['json']['default_branch'];
            $res    = ghRequest("{$base}/git/trees/" . rawurlencode($branch) . '?recursive=1', $token);
        } elseif ($meta['code'] === 404) {
            respond(502, [
                'status'  => 'error',
                'reason'  => 'repo_not_visible',
                'message' => $token === ''
                    ? "GitHub returned 404 for {$owner}/{$repo}. If the repository is private this is expected: "
                      . 'set a GITHUB_TOKEN environment variable with repo read access.'
                    : "GitHub returned 404 for {$owner}/{$repo}. Check the owner/repo spelling and that the "
                      . 'token has access to this repository.',
                'token_present' => $token !== '',
            ]);
        }
    }

    if ($res['code'] === 401) {
        respond(502, [
            'status'  => 'error',
            'reason'  => 'bad_credentials',
            'message' => 'GitHub rejected the token (401). It is missing, expired, or malformed.',
        ]);
    }

    if ($res['code'] === 403 || $res['code'] === 429) {
        $remaining = $res['headers']['x-ratelimit-remaining'] ?? null;
        $resetAt   = isset($res['headers']['x-ratelimit-reset'])
            ? gmdate('c', (int) $res['headers']['x-ratelimit-reset'])
            : null;

        respond(502, [
            'status'  => 'error',
            'reason'  => $remaining === '0' ? 'rate_limited' : 'forbidden',
            'message' => $remaining === '0'
                ? 'GitHub rate limit exhausted. Unauthenticated requests are capped at 60/hour per IP; '
                  . 'set GITHUB_TOKEN to raise this to 5000/hour.'
                : ($res['json']['message'] ?? 'GitHub returned 403.'),
            'rate_limit_remaining' => $remaining,
            'rate_limit_resets_at' => $resetAt,
        ]);
    }

    if ($res['code'] !== 200) {
        respond(502, [
            'status'    => 'error',
            'reason'    => 'upstream_error',
            'http_code' => $res['code'],
            'message'   => $res['json']['message'] ?? 'Unexpected response from GitHub.',
        ]);
    }

    if (!isset($res['json']['tree']) || !is_array($res['json']['tree'])) {
        respond(502, [
            'status'  => 'error',
            'reason'  => 'malformed_tree',
            'message' => 'GitHub returned 200 but no tree array.',
        ]);
    }

    // ---------------------------------------------------------------- filter

    $prefix = isset($_GET['path']) ? ltrim((string) $_GET['path'], '/') : '';
    $files  = [];

    foreach ($res['json']['tree'] as $item) {
        if (($item['type'] ?? '') !== 'blob') {
            continue;
        }
        $path = $item['path'] ?? '';
        if ($prefix !== '' && !str_starts_with($path, $prefix)) {
            continue;
        }
        $files[] = $path;
    }

    sort($files, SORT_NATURAL | SORT_FLAG_CASE);

    $payload = [
        'status'    => 'success',
        'branch'    => $branch,
        'path'      => $prefix !== '' ? $prefix : null,
        'count'     => count($files),
        'truncated' => (bool) ($res['json']['truncated'] ?? false),
        'files'     => $files,
    ];

    if ($payload['truncated']) {
        $payload['warning'] = 'GitHub truncated this tree (repo exceeds the API limit). '
            . 'Some files are missing from this list.';
    }

    if ($debug) {
        $payload['debug'] = [
            'token_present'        => $token !== '',
            'curl_available'       => function_exists('curl_init'),
            'total_blobs_in_tree'  => count(array_filter(
                $res['json']['tree'],
                static fn ($i) => ($i['type'] ?? '') === 'blob'
            )),
            'rate_limit_remaining' => $res['headers']['x-ratelimit-remaining'] ?? null,
        ];
    }

    respond(200, $payload);

} catch (Throwable $e) {
    respond(500, [
        'status'  => 'error',
        'reason'  => 'internal',
        'message' => $e->getMessage(),
    ]);
}
