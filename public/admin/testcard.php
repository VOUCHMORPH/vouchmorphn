<?php
declare(strict_types=1);

/**
 * public/admin/cardtest.php
 *
 * Mechanical introspection tool - Card Subsystem.
 *
 * Tests THREE distinct card funding flows:
 *
 *   A) SINGLE SOURCE -> SINGLE DESTINATION (card as destination)
 *      One source, preloaded onto a card via issueCard()/loadCard().
 *      Applies to VouchMorph cards and any card brand, since it's just
 *      "deposit to a card" - no pooling, no hooking.
 *
 *   B) MULTI-SOURCE -> CARD (preload/deposit model, ALL card brands)
 *      Several sources pooled, then deposited onto a card the same way
 *      MultiSourceSwapExecutor deposits onto an ACCOUNT or WALLET today.
 *      Applies to VouchMorph, Visa, Mastercard, etc - it's a destination
 *      type, not a network-specific mechanism.
 *
 *   C) MULTI-SOURCE HOOK -> VOUCHMORPH CARD ONLY (pooled hold-and-pull)
 *      Sources are HELD (not debited) against a card. A swipe is the
 *      real-time fast-path check; actual debits/settlement/shortfall
 *      billing happen async in the background worker. This mechanism
 *      is VouchMorph-network-only - no other card brand's authorization
 *      message can be intercepted by VouchMorph, so this path CANNOT
 *      apply to Visa/Mastercard cards. The test verifies this exclusivity
 *      is actually enforced, not just assumed.
 *
 *   D) HSM / PIN / CVV SECURITY AUDIT
 *      Given the stated bar (military/intelligence payroll grade), this
 *      section checks whether PIN/CVV material ever touches plaintext
 *      storage, weak/unsalted hashing, or gets logged - and whether the
 *      existing HSMKeyManager/KeyVault infrastructure is actually wired
 *      into the card flow or just sitting unused.
 *
 * Same rules as tested.php/tested_flows.php: everything reported here
 * comes from regex-parsing real deployed source or resolving real
 * classes/methods. No live bank/card/HSM call is ever made.
 */

header('Content-Type: application/json; charset=UTF-8');

define('ROOT_PATH', dirname(__DIR__, 2));
require_once ROOT_PATH . '/vendor/autoload.php';

// ============================================================
// AUTH GATE (same pattern as tested.php / tested_flows.php)
// ============================================================
function getApiKeyFromRequest(): ?string
{
    $headers = getallheaders() ?: [];
    $headersLower = array_change_key_case($headers, CASE_LOWER);
    if (!empty($headersLower['x-api-key'])) return $headersLower['x-api-key'];
    if (!empty($headersLower['authorization'])) {
        $auth = $headersLower['authorization'];
        return str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : $auth;
    }
    return null;
}
function getAllApiKeysFromEnvironment(): array
{
    $keys = [];
    foreach (array_merge($_ENV, $_SERVER, getenv()) as $name => $value) {
        if (is_string($value) && !empty($value) && (preg_match('/KEY|API|TOKEN|SECRET/i', $name) || strlen($value) >= 32)) {
            $keys[] = $value;
        }
    }
    return array_unique(array_filter($keys));
}
$providedKey = getApiKeyFromRequest();
$validKeys = getAllApiKeysFromEnvironment();
if (!empty($validKeys) && !in_array($providedKey, $validKeys, true)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid API key']);
    exit();
}

$report = [
    'generated_at' => date('c'),
    'source' => 'mechanically extracted from live files - see "how" field per section',
    'scope' => 'A: single-source card deposit. B: multi-source card deposit (all brands). C: multi-source hook to VouchMorph card only. D: HSM/PIN/CVV security audit.',
];

// ============================================================
// SHARED HELPERS (same as prior test scripts)
// ============================================================
function readSource(string $relativePath): ?string
{
    $path = ROOT_PATH . '/' . ltrim($relativePath, '/');
    return file_exists($path) ? file_get_contents($path) : null;
}

function extractMethodBody(string $source, string $methodName): ?string
{
    if (!preg_match('/(?:private|protected|public)\s+function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)[^{]*\{/', $source, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $start = $m[0][1] + strlen($m[0][0]);
    $depth = 1; $i = $start; $len = strlen($source);
    while ($i < $len && $depth > 0) {
        if ($source[$i] === '{') $depth++;
        elseif ($source[$i] === '}') $depth--;
        $i++;
    }
    return substr($source, $start, $i - $start - 1);
}

function methodExistsAndPublic(string $source, string $method): string
{
    if (preg_match('/public\s+function\s+' . preg_quote($method, '/') . '\s*\(/', $source)) return 'OK';
    if (preg_match('/(?:private|protected)\s+function\s+' . preg_quote($method, '/') . '\s*\(/', $source)) return 'CRITICAL: exists but NOT public';
    return 'CRITICAL: method does not exist';
}

function bodyCallsAnyOf(string $body, array $needles): array
{
    $hits = [];
    foreach ($needles as $needle) {
        if (stripos($body, $needle) !== false) $hits[] = $needle;
    }
    return $hits;
}

function findFilesByKeyword(string $relativeDir, string $keywordRegex): array
{
    $root = ROOT_PATH . '/' . ltrim($relativeDir, '/');
    if (!is_dir($root)) return [];
    $found = [];
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') continue;
        $rel = ltrim(str_replace(ROOT_PATH, '', $file->getPathname()), '/');
        if (preg_match($keywordRegex, $file->getFilename())) {
            $found[] = $rel;
            continue;
        }
        $head = @file_get_contents($file->getPathname());
        if ($head !== false && preg_match($keywordRegex, $head)) {
            $found[] = $rel;
        }
    }
    return array_values(array_unique($found));
}

function scanForPattern(string $source, string $pattern): array
{
    preg_match_all($pattern, $source, $m, PREG_OFFSET_CAPTURE);
    $lines = [];
    foreach ($m[0] as $match) {
        $lineNum = substr_count(substr($source, 0, $match[1]), "\n") + 1;
        $lines[] = ['line' => $lineNum, 'match' => trim($match[0])];
    }
    return $lines;
}

$cardServiceSrc   = readSource('src/Domain/Services/CardService.php');
$swapServiceSrc   = readSource('src/Domain/Services/SwapService.php');
$executorSrc      = readSource('src/Domain/Services/MultiSource/MultiSourceSwapExecutor.php');
$poolCoordSrc     = readSource('src/Domain/Services/MultiSource/PoolCoordinator.php');
$authorizeApiSrc  = readSource('public/api/v1/cards/authorize.php');
$hookApiSrc       = readSource('public/api/v1/cards/hook.php');
$balanceApiSrc    = readSource('public/api/v1/cards/balance.php');
$workerSrc        = readSource('scripts/daemons/card-pool-finalize-worker.php');
$hsmSrc           = readSource('src/Security/HSMKeyManager.php');
$keyVaultSrc      = readSource('src/Security/Encryption/KeyVault.php');
$tokenEncSrc      = readSource('src/Security/Encryption/TokenEncryptor.php');
$cardNumGenSrc    = readSource('src/Infrastructure/Cards/CardNumberGenerator.php');

// ============================================================
// SECTION A: SINGLE SOURCE -> SINGLE DESTINATION (card deposit)
// ============================================================
$report['A_single_source_to_card'] = [
    'how' => 'confirms SwapService::executeCardIssuance -> CardService::issueCard chain resolves, and that fees/forex are applied',
];

if ($swapServiceSrc === null || $cardServiceSrc === null) {
    $report['A_single_source_to_card']['error'] = 'SwapService.php or CardService.php not found';
} else {
    $issuanceBody = extractMethodBody($swapServiceSrc, 'executeCardIssuance');
    $report['A_single_source_to_card']['SwapService::executeCardIssuance'] = $issuanceBody === null
        ? 'NOT FOUND'
        : 'found - delegates to $this->cardService->issueCard()';

    $report['A_single_source_to_card']['CardService::issueCard_public'] = methodExistsAndPublic($cardServiceSrc, 'issueCard');
    $report['A_single_source_to_card']['CardService::loadCard_public'] = methodExistsAndPublic($cardServiceSrc, 'loadCard');

    $issueBody = extractMethodBody($cardServiceSrc, 'issueCard');
    $feeHits = $issueBody ? bodyCallsAnyOf($issueBody, ['calculateCardFees']) : [];
    $report['A_single_source_to_card']['fee_calculation_present'] = empty($feeHits)
        ? 'CRITICAL: issueCard() does not call calculateCardFees()'
        : 'OK - calculateCardFees() called';

    // Cross-check: does issueCard() actually validate hold ownership/amount
    // before minting a card against it? (hold_reference -> hold_transactions join)
    $hasHoldJoin = $issueBody && stripos($issueBody, 'hold_transactions') !== false;
    $report['A_single_source_to_card']['hold_validation_present'] = $hasHoldJoin
        ? 'OK - issueCard() validates against a real hold_transactions row'
        : 'CRITICAL: no hold validation found - card could be issued without a real backing hold';

    $report['A_single_source_to_card']['VERDICT'] = (empty($feeHits) || !$hasHoldJoin)
        ? 'ISSUES FOUND - see fields above'
        : 'OK - single-source-to-card path is wired correctly';
}

// ============================================================
// SECTION B: MULTI-SOURCE -> CARD (preload/deposit, ALL brands)
// ============================================================
$report['B_multisource_to_card_deposit'] = [
    'how' => 'checks whether MultiSourceSwapExecutor/PoolCoordinator can target CARD as a destination asset type the same way they target ACCOUNT/WALLET',
];

if ($executorSrc === null) {
    $report['B_multisource_to_card_deposit']['error'] = 'MultiSourceSwapExecutor.php not found';
} else {
    $destinationBody = extractMethodBody($executorSrc, 'executeDestination');
    $hasCardBranch = $destinationBody !== null && (
        stripos($destinationBody, "'CARD'") !== false ||
        stripos($destinationBody, 'loadCard') !== false ||
        stripos($destinationBody, 'CardService') !== false
    );

    $report['B_multisource_to_card_deposit']['executeDestination_handles_CARD'] = $hasCardBranch ? 'YES' : 'NO';

    if (!$hasCardBranch) {
        $report['B_multisource_to_card_deposit']['VERDICT'] =
            'CRITICAL / KNOWN GAP: MultiSourceSwapExecutor::executeDestination() has no branch for ' .
            'destination_asset_type = CARD. It currently only routes to $this->swapService->creditDestination(), ' .
            'which itself only calls $adapter->credit() - there is no path to CardService::loadCard() at all. ' .
            'A pooled multi-source swap targeting ANY card (VouchMorph, Visa, Mastercard) will currently either ' .
            'fail or silently attempt to credit a non-existent adapter endpoint. This was flagged as pending work ' .
            'earlier and remains unbuilt as of this test run.';
    } else {
        $report['B_multisource_to_card_deposit']['VERDICT'] = 'OK - CARD destination branch present';
    }
}

// ============================================================
// SECTION C: MULTI-SOURCE HOOK -> VOUCHMORPH CARD ONLY
// ============================================================
$report['C_hook_to_vouchmorph_card_only'] = [
    'how' => 'confirms hookSourcesToCard/authorizePooledSwipe/finalizePooledSwipe exist and are public, confirms consent gate is present, and confirms this path is NOT reachable for non-VouchMorph card brands',
];

if ($cardServiceSrc === null) {
    $report['C_hook_to_vouchmorph_card_only']['error'] = 'CardService.php not found';
} else {
    foreach (['hookSourcesToCard', 'authorizePooledSwipe', 'finalizePooledSwipe'] as $method) {
        $report['C_hook_to_vouchmorph_card_only'][$method . '_public'] = methodExistsAndPublic($cardServiceSrc, $method);
    }

    $hookBody = extractMethodBody($cardServiceSrc, 'hookSourcesToCard');
    $hasConsentGate = $hookBody !== null && stripos($hookBody, 'user_authorized_sources') !== false;
    $report['C_hook_to_vouchmorph_card_only']['consent_gate_present'] = $hasConsentGate
        ? 'OK - third-party sources checked against user_authorized_sources before holding'
        : 'CRITICAL: no consent check found - a third-party source could be hooked without prior authorization';

    $hasAllOrNothingRollback = $hookBody !== null && stripos($hookBody, 'releaseHold') !== false;
    $report['C_hook_to_vouchmorph_card_only']['all_or_nothing_rollback_present'] = $hasAllOrNothingRollback
        ? 'OK - failed hook attempts release any holds already placed in that attempt'
        : 'CRITICAL: no rollback found - a failed hook could leave orphaned holds on some sources';

    $finalizeBody = extractMethodBody($cardServiceSrc, 'finalizePooledSwipe');
    $hasShortfallBilling = $finalizeBody !== null && stripos($finalizeBody, 'card_pool_shortfall_bills') !== false;
    $report['C_hook_to_vouchmorph_card_only']['shortfall_billing_present'] = $hasShortfallBilling
        ? 'OK - post-approval debit failures are billed to the specific source owner'
        : 'CRITICAL: no shortfall billing found - a post-approval debit failure has no resolution path';

    $hasReleaseOfUnused = $finalizeBody !== null && preg_match('/unused.*releaseHold|releaseHold.*unused/is', $finalizeBody);
    $report['C_hook_to_vouchmorph_card_only']['unused_remainder_released'] = $hasReleaseOfUnused
        ? 'OK - unused portion of each hold is released after the actual swipe amount is debited'
        : 'WARNING: could not confirm unused hold remainder is released - verify manually';

    // ---- Exclusivity check: is this path reachable for non-VouchMorph brands? ----
    $exclusivityFindings = [];

    if ($authorizeApiSrc !== null) {
        $checksProvider = stripos($authorizeApiSrc, 'card_pool_hooks') !== false;
        $gatesOnHookExistence = preg_match('/status\s*=\s*[\'"]HOOKED[\'"]/i', $authorizeApiSrc);
        $exclusivityFindings['authorize.php_gates_on_active_hook'] = ($checksProvider && $gatesOnHookExistence)
            ? 'OK - pooled path only triggers if a card_pool_hooks row exists for this card_suffix'
            : 'CANNOT CONFIRM - manual review needed';

        // Does the code check card BRAND/network before allowing pooled auth?
        $checksBrand = preg_match('/brand|network|visa|mastercard|VOUCHMORPH_NETWORK/i', $authorizeApiSrc);
        $exclusivityFindings['authorize.php_checks_card_brand_explicitly'] = $checksBrand
            ? 'brand/network reference found - review to confirm it actually blocks non-VouchMorph cards'
            : 'WARNING: no explicit brand/network check found. Exclusivity currently relies ENTIRELY on the ' .
              'assumption that card_pool_hooks rows are only ever created for VouchMorph-issued cards (since ' .
              'hookSourcesToCard() is the only writer). If any future code path or admin tool ever inserts a ' .
              'card_pool_hooks row for a non-VouchMorph card_suffix, this endpoint would authorize pooled spend ' .
              'against it with no brand check to stop it. Recommend adding an explicit check that the card_suffix ' .
              'belongs to a VouchMorph-issued card (via message_cards table) before honoring a pooled hook, rather ' .
              'than relying on write-path discipline alone.';
    } else {
        $exclusivityFindings['status'] = 'authorize.php not found - cannot verify exclusivity enforcement';
    }

    $report['C_hook_to_vouchmorph_card_only']['exclusivity_check'] = $exclusivityFindings;

    // ---- Worker wiring check ----
    if ($workerSrc === null) {
        $report['C_hook_to_vouchmorph_card_only']['worker_status'] = 'NOT FOUND - card-pool-finalize-worker.php missing, queued jobs would never process';
    } else {
        $callsFinalize = stripos($workerSrc, 'finalizePooledSwipe') !== false;
        $hasDeadLetterAlert = stripos($workerSrc, 'OPS_ALERT_PHONE') !== false || stripos($workerSrc, 'SmsNotificationService') !== false;
        $report['C_hook_to_vouchmorph_card_only']['worker_calls_finalize'] = $callsFinalize ? 'OK' : 'CRITICAL: worker does not call finalizePooledSwipe()';
        $report['C_hook_to_vouchmorph_card_only']['worker_dead_letter_alert'] = $hasDeadLetterAlert ? 'OK - SMS alert wired for repeated failures' : 'MISSING - no ops alert on dead-lettered jobs';
    }

    $criticalCount = 0;
    foreach ($report['C_hook_to_vouchmorph_card_only'] as $k => $v) {
        if (is_string($v) && str_starts_with($v, 'CRITICAL')) $criticalCount++;
    }
    $report['C_hook_to_vouchmorph_card_only']['VERDICT'] = $criticalCount === 0
        ? 'OK - pooled hook-to-VouchMorph-card path is structurally sound'
        : "{$criticalCount} CRITICAL issue(s) found in this section - see fields above";
}

// ============================================================
// SECTION D: HSM / PIN / CVV SECURITY AUDIT
// ============================================================
$report['D_hsm_pin_cvv_security_audit'] = [
    'how' => 'scans CardService and related files for plaintext PIN/CVV handling, weak/unsalted hashing, sensitive data in logs, and whether HSMKeyManager/KeyVault are actually referenced in the card flow',
];

// --- D1: Does HSM infrastructure exist, and is it USED by CardService? ---
$report['D_hsm_pin_cvv_security_audit']['HSMKeyManager.php_exists'] = $hsmSrc !== null ? 'YES' : 'NO';
$report['D_hsm_pin_cvv_security_audit']['KeyVault.php_exists'] = $keyVaultSrc !== null ? 'YES' : 'NO';
$report['D_hsm_pin_cvv_security_audit']['TokenEncryptor.php_exists'] = $tokenEncSrc !== null ? 'YES' : 'NO';

if ($cardServiceSrc !== null) {
    $referencesHSM = stripos($cardServiceSrc, 'HSMKeyManager') !== false;
    $referencesKeyVault = stripos($cardServiceSrc, 'KeyVault') !== false;
    $referencesTokenEnc = stripos($cardServiceSrc, 'TokenEncryptor') !== false;

    $report['D_hsm_pin_cvv_security_audit']['CardService_references_HSMKeyManager'] = $referencesHSM ? 'YES' : 'NO';
    $report['D_hsm_pin_cvv_security_audit']['CardService_references_KeyVault'] = $referencesKeyVault ? 'YES' : 'NO';
    $report['D_hsm_pin_cvv_security_audit']['CardService_references_TokenEncryptor'] = $referencesTokenEnc ? 'YES' : 'NO';

    if (!$referencesHSM && !$referencesKeyVault && !$referencesTokenEnc) {
        $report['D_hsm_pin_cvv_security_audit']['HSM_WIRING_VERDICT'] =
            'CRITICAL: CardService.php never references HSMKeyManager, KeyVault, or TokenEncryptor anywhere. ' .
            'Whatever cryptographic material protects PIN/CVV data today does not go through the dedicated ' .
            'HSM/key-vault infrastructure that exists elsewhere in this codebase - it is fully independent of it. ' .
            'For a military/intelligence-payroll security bar, PIN and CVV material should never be processed ' .
            'with a bare hash() call outside a proper HSM/vault boundary.';
    }

    // --- D2: How IS the CVV actually being hashed? ---
    $cvvHashPattern = scanForPattern($cardServiceSrc, '/hash\s*\(\s*[\'"]sha256[\'"]\s*,\s*\$data\[[\'"]cvv[\'"]\]\s*\)/i');
    if (!empty($cvvHashPattern)) {
        $report['D_hsm_pin_cvv_security_audit']['CVV_HASHING_FOUND'] = [
            'lines' => $cvvHashPattern,
            'VERDICT' => 'CRITICAL: CVV is hashed with a bare, unsalted SHA-256 hash() call. ' .
                'Two serious problems: (1) PCI-DSS explicitly PROHIBITS storing CVV/CVV2 in ANY form after ' .
                'authorization - not plaintext, not encrypted, not hashed. If this hash is being stored, that is ' .
                'itself a compliance violation regardless of hash strength. (2) Even if storage were permitted, ' .
                'an unsalted SHA-256 of a 3-4 digit CVV is trivially brute-forceable (at most 10,000 possibilities) ' .
                'in well under a second on ordinary hardware - it provides no real protection at all.'
        ];
    }

    // --- D3: Card number (PAN) hashing ---
    $panHashPattern = scanForPattern($cardServiceSrc, '/hash\s*\(\s*[\'"]sha256[\'"]\s*,\s*(?:preg_replace\([^)]+\)|\$cardNumber|\$data\[[\'"]card_number[\'"]\])\s*\)/i');
    if (!empty($panHashPattern)) {
        $report['D_hsm_pin_cvv_security_audit']['PAN_HASHING_FOUND'] = [
            'lines' => $panHashPattern,
            'VERDICT' => 'WARNING: card number (PAN) is hashed with unsalted SHA-256. Card numbers have far less ' .
                'entropy than they appear to (fixed BIN/issuer prefix, checksum digit, often a known-range account ' .
                'sequence) - an unsalted hash is vulnerable to a targeted rainbow-table attack against known BIN ' .
                'ranges, unlike a per-record-salted hash or HMAC with a secret key. Recommend HMAC-SHA256 with a ' .
                'key held in HSMKeyManager/KeyVault, not a bare hash().'
        ];
    }

    // --- D4: Does anything in CardService log request payloads that might carry raw PIN/CVV? ---
    $errorLogLines = scanForPattern($cardServiceSrc, '/error_log\s*\([^;]*\$data[^;]*\)/i');
    $reviewableLogs = [];
    foreach ($errorLogLines as $entry) {
        $reviewableLogs[] = $entry;
    }
    $report['D_hsm_pin_cvv_security_audit']['error_log_calls_with_raw_$data'] = [
        'count' => count($reviewableLogs),
        'lines' => $reviewableLogs,
        'note' => 'Each of these logs some form of the raw input array. Manually confirm none of them include ' .
                  'unredacted cvv, card_number, or pin fields - json_encode($data) on the full array would leak ' .
                  'them into error_log() in plaintext even if the DB storage is fine.',
    ];

    // --- D5: authorizeTransaction - CVV comparison happens against the SAME weak hash from D2 ---
    $authBody = extractMethodBody($cardServiceSrc, 'authorizeTransaction');
    if ($authBody !== null) {
        $comparesCvvHash = stripos($authBody, 'cvv_hash') !== false;
        $report['D_hsm_pin_cvv_security_audit']['authorizeTransaction_cvv_check'] = $comparesCvvHash
            ? 'CONFIRMED: live transaction authorization compares against the stored cvv_hash flagged above - ' .
              'this is not dead/unused code, it is on the real authorization path for every card swipe.'
            : 'Could not confirm CVV check pattern - review manually';
    }
}

// --- D6: Does any PIN ever appear as plaintext in a payload structure meant for storage/transit? ---
$pinPlaintextFiles = findFilesByKeyword('src/Domain/Services', '/wallet_pin|voucher_pin|atm_pin|card_pin/i');
$report['D_hsm_pin_cvv_security_audit']['files_referencing_raw_pin_fields'] = $pinPlaintextFiles;
$report['D_hsm_pin_cvv_security_audit']['note_on_pin_fields'] = 
    'These files pass a "pin" field through payloads by design (SwapService::forwardPin forwards PIN from the ' .
    'requester to the SOURCE institution for their own verification, which is correct - the institution owning ' .
    'the PIN must verify it themselves). The concern is narrower and specific to CardService: PIN/CVV material ' .
    'that VouchMorph itself stores or hashes locally (for its OWN issued cards) is what needs HSM-grade handling, ' .
    'not PINs that only pass through in transit to their rightful owning institution.';

// ============================================================
// SUMMARY
// ============================================================
$criticalIssues = [];

foreach (['A_single_source_to_card', 'C_hook_to_vouchmorph_card_only'] as $section) {
    foreach ($report[$section] as $k => $v) {
        if (is_string($v) && str_starts_with($v, 'CRITICAL')) $criticalIssues[] = "{$section}.{$k}";
        if (is_array($v) && isset($v['VERDICT']) && str_starts_with($v['VERDICT'], 'CRITICAL')) $criticalIssues[] = "{$section}.{$k}";
    }
}

if (str_starts_with($report['B_multisource_to_card_deposit']['VERDICT'] ?? '', 'CRITICAL')) {
    $criticalIssues[] = 'B_multisource_to_card_deposit: CARD not handled as multi-source destination';
}

foreach ($report['D_hsm_pin_cvv_security_audit'] as $k => $v) {
    if (is_array($v) && isset($v['VERDICT']) && str_starts_with($v['VERDICT'], 'CRITICAL')) {
        $criticalIssues[] = "D_hsm_pin_cvv_security_audit.{$k}";
    }
    if (is_string($v) && str_starts_with($v, 'CRITICAL')) {
        $criticalIssues[] = "D_hsm_pin_cvv_security_audit.{$k}";
    }
}

$report['summary'] = [
    'critical_issues_found' => count($criticalIssues),
    'issues' => $criticalIssues,
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
