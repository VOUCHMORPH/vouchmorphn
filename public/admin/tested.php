<?php
/**
 * fix_vw_all_swaps.php
 *
 * Diagnoses WHY the LIMIT fix isn't sticking, then fixes it - using the
 * SAME database connection the live app uses (DBConnection::getConnection()),
 * so there is zero ambiguity about which database is actually being touched.
 *
 * "I ran the SQL and nothing changed, over and over" is almost always one
 * of these, in order of likelihood on Railway specifically:
 *   1. The SQL was run against a different Postgres instance/URL than the
 *      one this app connects to (very common when a project has both an
 *      internal and public DB URL, or multiple Postgres services).
 *   2. The DB user running the manual SQL doesn't have DROP/CREATE
 *      privileges, and the tool used to run it swallowed the error.
 *   3. vw_all_swaps isn't actually a plain view (e.g. it's a table, or a
     * materialized view that was never refreshed), so "CREATE VIEW" fails
 *      silently against a same-named object.
 *   4. Something else depends on vw_all_swaps, so a DROP...CASCADE is
 *      quietly taking out other objects too (or being blocked).
 *
 * USAGE:
 *   Step 1 - visit this file with NO query string. It only reads, never
 *            writes. It reports which database it's actually connected
 *            to, what vw_all_swaps currently IS (view/table/matview),
 *            its live row count and definition, permission check, and
 *            any objects that depend on it.
 *   Step 2 - once you've confirmed the diagnosis, visit again with
 *            ?confirm=1 to actually run the DROP + CREATE, inside a
 *            transaction, with the real Postgres error surfaced if it
 *            fails (instead of being swallowed).
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

define('PROJECT_ROOT', dirname(__DIR__, 2));
require_once PROJECT_ROOT . '/src/Core/Database/DBConnection.php';
require_once PROJECT_ROOT . '/src/Application/Utils/SessionManager.php';

use Core\Database\DBConnection;
use Application\Utils\SessionManager;

if (!SessionManager::isAdminLoggedIn()) {
    die("Not logged in as admin. Log in via admin_login.php first, then run this in the same browser session.");
}

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

try {
    $db = DBConnection::getConnection();
    if (!$db) throw new Exception("no connection object returned");
    $db->query("SELECT 1");
} catch (Throwable $e) {
    die("<pre style='color:#dc3545;font-family:monospace;'>DATABASE CONNECTION FAILED: " . h($e->getMessage()) . "</pre>");
}

$confirm = isset($_GET['confirm']) && $_GET['confirm'] === '1';

$report = [];
function add(&$report, $label, $value, $ok = null) {
    $report[] = ['label' => $label, 'value' => $value, 'ok' => $ok];
}

// ============================================================
// 1. WHICH DATABASE IS THIS, EXACTLY?
// This is the number one thing to compare against whatever tool you've
// been running the manual SQL through.
// ============================================================
try {
    $identity = $db->query("
        SELECT current_database() as db,
               current_user as usr,
               inet_server_addr() as host,
               inet_server_port() as port,
               version() as pg_version
    ")->fetch(PDO::FETCH_ASSOC);
    add($report, 'Connected database', $identity['db']);
    add($report, 'Connected as user', $identity['usr']);
    add($report, 'Server address', ($identity['host'] ?: 'local socket / not exposed') . ':' . $identity['port']);
    add($report, 'Postgres version', $identity['pg_version']);
} catch (Throwable $e) {
    add($report, 'Connection identity check failed', $e->getMessage(), false);
}

// ============================================================
// 2. WHAT IS vw_all_swaps, ACTUALLY? (view / table / matview / missing)
// ============================================================
$relKind = null;
try {
    $stmt = $db->query("
        SELECT c.relkind, n.nspname as schema
        FROM pg_class c
        JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE c.relname = 'vw_all_swaps'
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        add($report, 'vw_all_swaps object type', 'DOES NOT EXIST in any schema', false);
    } else {
        foreach ($rows as $r) {
            $kindMap = ['v' => 'VIEW', 'r' => 'TABLE', 'm' => 'MATERIALIZED VIEW', 'f' => 'FOREIGN TABLE'];
            $kind = $kindMap[$r['relkind']] ?? $r['relkind'];
            $relKind = $r['relkind'];
            add($report, "vw_all_swaps found in schema \"{$r['schema']}\"", "Type: {$kind}" . ($kind !== 'VIEW' ? '  <-- THIS IS LIKELY THE PROBLEM: a CREATE VIEW cannot silently replace a ' . $kind : ''), $kind === 'VIEW');
        }
    }
} catch (Throwable $e) {
    add($report, 'Object type check failed', $e->getMessage(), false);
}

// ============================================================
// 3. CURRENT ROW COUNT AND DEFINITION, RIGHT NOW, ON THIS CONNECTION
// ============================================================
try {
    $count = (int)$db->query("SELECT COUNT(*) FROM vw_all_swaps")->fetchColumn();
    add($report, 'Current row count (this connection, right now)', $count, $count > 100 ? true : null);
} catch (Throwable $e) {
    add($report, 'Row count query failed', $e->getMessage(), false);
}

try {
    $def = $db->query("SELECT pg_get_viewdef('vw_all_swaps', true)")->fetchColumn();
    $hasLimit = stripos($def, 'limit') !== false;
    add($report, 'Has LIMIT clause right now', $hasLimit ? 'YES - still present' : 'No', !$hasLimit);
    if ($hasLimit) {
        preg_match_all('/.{0,60}LIMIT.{0,60}/i', $def, $m);
        add($report, 'LIMIT context', implode(' | ', $m[0]));
    }
} catch (Throwable $e) {
    add($report, 'View definition check failed (expected if it is not a view)', $e->getMessage(), null);
}

// ============================================================
// 4. WHAT DEPENDS ON IT? (a CASCADE drop would take these down too)
// ============================================================
$dependents = [];
try {
    $stmt = $db->query("
        SELECT DISTINCT dependent_ns.nspname as dependent_schema,
               dependent_view.relname as dependent_object,
               dependent_view.relkind
        FROM pg_depend
        JOIN pg_rewrite ON pg_depend.objid = pg_rewrite.oid
        JOIN pg_class as dependent_view ON pg_rewrite.ev_class = dependent_view.oid
        JOIN pg_class as source_table ON pg_depend.refobjid = source_table.oid
        JOIN pg_namespace dependent_ns ON dependent_ns.oid = dependent_view.relnamespace
        WHERE source_table.relname = 'vw_all_swaps'
          AND dependent_view.relname != 'vw_all_swaps'
    ");
    $dependents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($dependents)) {
        add($report, 'Dependent objects (would be affected by CASCADE)', 'None found - safe to drop', true);
    } else {
        foreach ($dependents as $d) {
            add($report, 'DEPENDENT OBJECT FOUND', "{$d['dependent_schema']}.{$d['dependent_object']} (kind: {$d['relkind']}) - CASCADE will drop this too", false);
        }
    }
} catch (Throwable $e) {
    add($report, 'Dependency check failed', $e->getMessage(), null);
}

// ============================================================
// 5. CAN THIS USER ACTUALLY CREATE/DROP OBJECTS HERE?
// ============================================================
try {
    $canCreate = $db->query("SELECT has_schema_privilege(current_user, 'public', 'CREATE')")->fetchColumn();
    add($report, 'Current user has CREATE privilege on schema "public"', $canCreate === 't' || $canCreate === true ? 'Yes' : 'NO - this is likely why nothing sticks', $canCreate === 't' || $canCreate === true);
} catch (Throwable $e) {
    add($report, 'Privilege check failed', $e->getMessage(), null);
}

// ============================================================
// 6. IF ?confirm=1, ACTUALLY RUN THE FIX - with real errors surfaced.
// Only proceeds if it's a plain VIEW or missing (won't blindly nuke a
// TABLE named vw_all_swaps).
// ============================================================
$fixOutcome = null;
if ($confirm) {
    if ($relKind !== null && $relKind !== 'v') {
        $fixOutcome = ['status' => 'ABORTED', 'message' => 'vw_all_swaps is not a plain view (it is a ' . ($relKind === 'r' ? 'TABLE' : $relKind) . '). Refusing to auto-drop it - this needs a manual decision, not an automated CASCADE.'];
    } else {
        try {
            $db->beginTransaction();

            $db->exec("DROP VIEW IF EXISTS vw_all_swaps CASCADE");

            $db->exec("
                CREATE VIEW vw_all_swaps AS
                SELECT
                    hold_transactions.hold_reference AS reference,
                    hold_transactions.swap_reference,
                    'HOLD'::text AS swap_type,
                    hold_transactions.source_institution,
                    hold_transactions.destination_institution,
                    hold_transactions.amount,
                    hold_transactions.currency,
                    hold_transactions.status,
                    NULL::numeric AS fee_amount,
                    hold_transactions.created_at,
                    hold_transactions.updated_at
                FROM hold_transactions

                UNION ALL

                SELECT
                    NULL::character varying AS reference,
                    multi_destination_swaps.reference AS swap_reference,
                    'MULTI_DESTINATION'::text AS swap_type,
                    multi_destination_swaps.source_institution,
                    NULL::character varying AS destination_institution,
                    multi_destination_swaps.total_amount AS amount,
                    COALESCE((multi_destination_swaps.destinations_payload -> 0) ->> 'currency'::text, 'BWP'::text) AS currency,
                    multi_destination_swaps.status,
                    multi_destination_swaps.total_fees AS fee_amount,
                    multi_destination_swaps.created_at,
                    multi_destination_swaps.updated_at
                FROM multi_destination_swaps

                UNION ALL

                SELECT
                    identity_swap_holds.hold_reference AS reference,
                    identity_swap_holds.swap_reference,
                    'IDENTITY'::text AS swap_type,
                    identity_swap_holds.source_institution,
                    NULL::character varying AS destination_institution,
                    identity_swap_holds.amount,
                    identity_swap_holds.currency,
                    identity_swap_holds.status,
                    NULL::numeric AS fee_amount,
                    identity_swap_holds.created_at,
                    identity_swap_holds.created_at AS updated_at
                FROM identity_swap_holds

                UNION ALL

                SELECT
                    cashout_authorizations.swap_code AS reference,
                    cashout_authorizations.swap_reference,
                    'CASHOUT'::text AS swap_type,
                    cashout_authorizations.source_institution,
                    cashout_authorizations.cashout_provider AS destination_institution,
                    cashout_authorizations.amount,
                    cashout_authorizations.currency,
                    cashout_authorizations.status,
                    cashout_authorizations.fee_amount,
                    cashout_authorizations.created_at,
                    cashout_authorizations.updated_at
                FROM cashout_authorizations
            ");

            $db->commit();

            $newCount = (int)$db->query("SELECT COUNT(*) FROM vw_all_swaps")->fetchColumn();
            $newDef = $db->query("SELECT pg_get_viewdef('vw_all_swaps', true)")->fetchColumn();
            $stillHasLimit = stripos($newDef, 'limit') !== false;

            $fixOutcome = [
                'status' => $stillHasLimit ? 'RAN BUT STILL HAS LIMIT - something is very wrong, see below' : 'SUCCESS',
                'message' => "New row count: {$newCount}" . ($stillHasLimit ? '. LIMIT is STILL present after recreation - this points to a second view/proxy layer, or the app is not actually reading this schema/database at all.' : '. LIMIT clause confirmed gone.'),
            ];

        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $fixOutcome = ['status' => 'FAILED', 'message' => 'Postgres error (this is the real reason, not a guess): ' . $e->getMessage()];
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>vw_all_swaps Fix</title>
<style>
    body { font-family:'IBM Plex Mono',monospace; background:#f7f9fc; color:#001B44; padding:24px; max-width:1000px; margin:0 auto; }
    .row { display:flex; gap:12px; padding:8px 0; border-bottom:1px solid #eee; font-size:0.8rem; align-items:flex-start; }
    .label { min-width:340px; font-weight:600; }
    .value { color:#333; word-break:break-word; }
    .ok { color:#28a745; }
    .bad { color:#dc3545; font-weight:700; }
    .section { background:#fff; border:2px solid #001B44; border-radius:6px; padding:16px; margin-bottom:16px; }
    .btn { display:inline-block; padding:10px 20px; background:#dc3545; color:#fff; text-decoration:none; border-radius:4px; font-weight:700; margin-top:12px; }
    .outcome { padding:16px; border-radius:6px; margin-bottom:16px; font-weight:600; }
    .outcome.success { background:#d4edda; color:#155724; }
    .outcome.failed { background:#f8d7da; color:#721c24; }
</style>
</head>
<body>
<h1>🔧 vw_all_swaps Diagnosis <?php echo $confirm ? '+ Fix Attempt' : '(read-only)'; ?></h1>

<?php if ($fixOutcome): ?>
<div class="outcome <?php echo $fixOutcome['status'] === 'SUCCESS' ? 'success' : 'failed'; ?>">
    <?php echo h($fixOutcome['status']); ?><br>
    <?php echo h($fixOutcome['message']); ?>
</div>
<?php endif; ?>

<div class="section">
<?php foreach ($report as $r): ?>
    <div class="row">
        <span class="label"><?php echo h($r['label']); ?></span>
        <span class="value <?php echo $r['ok'] === true ? 'ok' : ($r['ok'] === false ? 'bad' : ''); ?>">
            <?php echo h(is_bool($r['value']) ? ($r['value'] ? 'true' : 'false') : $r['value']); ?>
        </span>
    </div>
<?php endforeach; ?>
</div>

<?php if (!$confirm): ?>
<p>Review the rows above marked in red first - especially "object type" and "dependent objects." If everything looks clear, run the fix:</p>
<a href="?confirm=1" class="btn">RUN THE FIX NOW</a>
<?php endif; ?>

</body>
</html>
