<?php
declare(strict_types=1);

/**
 * VouchMorph - Swap History Diagnostic
 *
 * TEMPORARY DEBUG TOOL — delete this file once history is fixed.
 * Do not leave it deployed; it dumps session/config details that
 * shouldn't be exposed in a regulated environment.
 *
 * Drop this in the same folder as history.php (api/v1/swap/) and
 * hit it directly in the browser while logged into the dashboard,
 * in the SAME browser session. It walks through each link in the
 * chain independently so we can see exactly where it breaks:
 *
 *   1. Does the session have a logged-in user, and what does
 *      SessionManager::getUser() actually return?
 *   2. Is VOUCHMORPH_API_KEY configured server-side?
 *   3. Does the DB connection work?
 *   4. What does hold_transactions.source_details actually look
 *      like for the most recent rows?
 *   5. Does the exact LIKE query history.php uses match anything
 *      for this session's user id?
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/Application/Utils/SessionManager.php';

use Application\Utils\SessionManager;
use Core\Database\DBConnection;

header("Content-Type: text/plain; charset=UTF-8");

echo "=== VouchMorph Swap History Diagnostic ===\n\n";

// ------------------------------------------------------------
// 1. SESSION / USER
// ------------------------------------------------------------
echo "--- 1. SESSION ---\n";
SessionManager::start();

if (!SessionManager::isLoggedIn()) {
    echo "NOT LOGGED IN in this browser session.\n";
    echo "Open this URL in the SAME browser tab/session as the dashboard (after logging in), then reload.\n";
    exit;
}

$userData = SessionManager::getUser();
echo "Logged in: YES\n";
echo "Session user data keys: " . implode(', ', array_keys($userData ?? [])) . "\n";
echo "Full session user data:\n" . json_encode($userData, JSON_PRETTY_PRINT) . "\n";

$userId = $userData['id'] ?? 0;
echo "\nResolved \$userId (what the dashboard uses): " . var_export($userId, true) . "\n";
if (empty($userId)) {
    echo "!!! PROBLEM FOUND: session has no usable 'id' field. This is very likely why\n";
    echo "    history always returns empty — the app is searching for \"user_id\":0\n";
    echo "    which won't match any real swap. Check what key SessionManager actually\n";
    echo "    stores the user's ID under (look at the keys printed above) and either\n";
    echo "    fix SessionManager/login to set 'id', or update user_dashboard.php's\n";
    echo "    \$userId = \$userData['id'] ?? 0; line to read the correct key.\n";
}
echo "\n";

// ------------------------------------------------------------
// 2. API KEY CONFIG
// ------------------------------------------------------------
echo "--- 2. API KEY ---\n";
$apiKey = getenv('VOUCHMORPH_API_KEY') ?: '';
if ($apiKey === '') {
    echo "!!! VOUCHMORPH_API_KEY is NOT set in this environment (getenv() returned empty).\n";
    echo "    This means the dashboard runs in test mode and sends NO X-API-Key header.\n";
} else {
    echo "VOUCHMORPH_API_KEY is set (length " . strlen($apiKey) . " chars). Not printing the value.\n";
}
echo "\n";

// ------------------------------------------------------------
// 3. DB CONNECTION
// ------------------------------------------------------------
echo "--- 3. DATABASE CONNECTION ---\n";
try {
    $db = DBConnection::getConnection();
    if (!$db) {
        echo "!!! DBConnection::getConnection() returned null/false.\n";
        exit;
    }
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Connected OK.\n";
} catch (\Throwable $e) {
    echo "!!! DB connection failed: " . $e->getMessage() . "\n";
    exit;
}
echo "\n";

// ------------------------------------------------------------
// 4. RAW ROWS — what does source_details actually contain?
// ------------------------------------------------------------
echo "--- 4. RECENT hold_transactions ROWS (raw) ---\n";
try {
    $stmt = $db->prepare("SELECT swap_reference, source_details, currency, amount, created_at FROM hold_transactions ORDER BY created_at DESC LIMIT 5");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        echo "!!! No rows at all in hold_transactions. Either no swaps have ever been\n";
        echo "    executed, or this table is empty for another reason (wrong DB/schema?).\n";
    } else {
        foreach ($rows as $r) {
            echo "reference: " . $r['swap_reference'] . "\n";
            echo "  currency: " . var_export($r['currency'], true) . "\n";
            echo "  amount: " . $r['amount'] . "\n";
            echo "  created_at: " . $r['created_at'] . "\n";
            echo "  source_details (raw): " . $r['source_details'] . "\n";
            $decoded = json_decode($r['source_details'] ?? '', true);
            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                echo "  !!! source_details is not valid JSON: " . json_last_error_msg() . "\n";
            } else {
                echo "  source_details decoded keys: " . implode(', ', array_keys($decoded ?? [])) . "\n";
                if (isset($decoded['user_id'])) {
                    echo "  source_details.user_id: " . var_export($decoded['user_id'], true) . " (type: " . gettype($decoded['user_id']) . ")\n";
                } else {
                    echo "  !!! source_details has no 'user_id' key at all.\n";
                }
            }
            echo "\n";
        }
    }
} catch (\Throwable $e) {
    echo "!!! Query failed: " . $e->getMessage() . "\n";
}
echo "\n";

// ------------------------------------------------------------
// 5. EXACT QUERY history.php RUNS — does it match?
// ------------------------------------------------------------
echo "--- 5. EXACT history.php QUERY FOR THIS SESSION'S USER ---\n";
if (empty($userId)) {
    echo "Skipped — no usable \$userId from session (see section 1).\n";
} else {
    try {
        $sql = "
            SELECT swap_reference, source_details, currency, amount
            FROM hold_transactions
            WHERE source_details::text LIKE :user_search
            ORDER BY created_at DESC
            LIMIT 10
        ";
        $pattern = '%"user_id":' . $userId . '%';
        echo "Search pattern: " . $pattern . "\n";

        $stmt = $db->prepare($sql);
        $stmt->bindValue(':user_search', $pattern, PDO::PARAM_STR);
        $stmt->execute();
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo "Rows matched: " . count($matches) . "\n";
        if (count($matches) === 0) {
            echo "!!! PROBLEM CONFIRMED: the LIKE pattern above matches ZERO rows.\n";
            echo "    Compare this pattern against the actual source_details values printed\n";
            echo "    in section 4 above. Common causes:\n";
            echo "    - user_id is stored as a string (\"user_id\":\"" . $userId . "\") not a bare number\n";
            echo "    - the key is nested inside another object\n";
            echo "    - the key is named differently (e.g. client_id, userId)\n";
            echo "    - this user genuinely has no swaps yet\n";
        } else {
            foreach ($matches as $m) {
                echo "  MATCHED: " . $m['swap_reference'] . " — " . $m['amount'] . " " . $m['currency'] . "\n";
            }
        }
    } catch (\Throwable $e) {
        echo "!!! Query failed: " . $e->getMessage() . "\n";
    }
}

echo "\n=== END DIAGNOSTIC ===\n";
