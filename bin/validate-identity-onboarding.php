#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Can the institutions we actually run identity swaps between take part, and
 * what is missing from the ones that cannot.
 *
 * participants.yaml ships every institution scaffolded with literal
 * "REPLACE_WITH_REAL_..." identifiers. Those are non-empty strings, so they
 * satisfy a plain empty() check and only announce themselves when a real swap
 * hits them -- which is how a claim came to fail at the payout step with
 * "SACCUSSALIS's settlement_account.BWP.identifier is still a placeholder".
 * This prints the same judgement the code makes, for every institution at
 * once, before anyone's money is involved.
 *
 * Two capabilities, and they are NOT the same thing:
 *
 *   SEND     -- settlement_account.<CUR>.identifier. Every delivery path out
 *               of a hold names this account as the funding counterparty in
 *               the credit instruction, so without it no claim against a
 *               hold at this institution can ever be paid out.
 *   RECEIVE  -- capabilities.identity_holding plus identity_accounts.<CUR>
 *               receiving_identifier and holding_identifier. This is what a
 *               claimant picks as the destination.
 *
 * An institution can be fine for one and useless for the other.
 *
 * Only the institutions in IN_SCOPE decide the exit code. The others are
 * printed for visibility but are not being onboarded yet, and failing on them
 * would make this check useless as a gate.
 *
 * Reads through LoadCountry so the report reflects what the application
 * actually parses, not what the YAML appears to say.
 *
 * Usage:   php bin/validate-identity-onboarding.php [--only=A,B] [--country=X]
 * Exit:    0 = every in-scope institution usable both ways, 1 = gaps
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Core/Config/LoadCountry.php';

/** The institutions live enough to matter right now. */
const IN_SCOPE = ['ZURUBANK', 'SACCUSSALIS'];

const PLACEHOLDER_PREFIX = 'REPLACE_WITH_REAL';

function isPlaceholder(?string $value): bool
{
    return $value !== null && stripos($value, PLACEHOLDER_PREFIX) !== false;
}

/** @return string[] reasons this identifier is unusable; empty = usable */
function checkIdentifier(?string $value, string $label): array
{
    if ($value === null || $value === '') {
        return ["{$label} is not set"];
    }
    if (isPlaceholder($value)) {
        return ["{$label} is still the onboarding placeholder ({$value})"];
    }
    return [];
}

/** @return array{send: string[], receive: string[], scaffolded: bool} */
function inspect(array $participant): array
{
    $settlement = $participant['settlement_account'] ?? [];
    $identityAccounts = $participant['identity_accounts'] ?? [];
    $holdingEnabled = (bool)($participant['capabilities']['identity_holding'] ?? false);

    // Never scaffolded for identity at all -- e.g. a card acquirer that is a
    // card source and nothing else. A deliberate shape, not a half-finished
    // onboarding.
    if ($settlement === [] && $identityAccounts === [] && !$holdingEnabled) {
        return ['send' => [], 'receive' => [], 'scaffolded' => false];
    }

    $send = $settlement === [] ? ['no settlement_account block at all'] : [];
    foreach ($settlement as $currency => $account) {
        $send = array_merge(
            $send,
            checkIdentifier($account['identifier'] ?? null, "settlement_account.{$currency}.identifier")
        );
    }

    $receive = [];
    if (!$holdingEnabled) {
        $receive[] = 'capabilities.identity_holding is not enabled';
    }
    if ($identityAccounts === []) {
        $receive[] = 'no identity_accounts block at all';
    }
    foreach ($identityAccounts as $currency => $accounts) {
        foreach (['receiving_identifier', 'holding_identifier'] as $field) {
            $receive = array_merge(
                $receive,
                checkIdentifier($accounts[$field] ?? null, "identity_accounts.{$currency}.{$field}")
            );
        }
    }

    return ['send' => $send, 'receive' => $receive, 'scaffolded' => true];
}

function report(string $code, array $findings): void
{
    if (!$findings['scaffolded']) {
        printf("%-16s not an identity participant (no identity config, by design)\n", $code);
        return;
    }

    printf(
        "%-16s send: %-3s   receive: %-3s\n",
        $code,
        $findings['send'] === [] ? 'ok' : 'NO',
        $findings['receive'] === [] ? 'ok' : 'NO'
    );

    foreach (array_unique(array_merge($findings['send'], $findings['receive'])) as $problem) {
        echo "                 - {$problem}\n";
    }
}

// ---------------------------------------------------------------------------

$country = null;
$inScope = IN_SCOPE;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--only=')) {
        $inScope = array_values(array_filter(array_map(
            'trim',
            explode(',', strtoupper(substr($arg, 7)))
        )));
    } elseif (str_starts_with($arg, '--country=')) {
        $country = substr($arg, 10);
    }
}

$config = \Core\Config\LoadCountry::getConfig($country);
$participants = $config['participants'] ?? [];

if ($participants === []) {
    fwrite(STDERR, "No participants parsed for " . ($country ?? 'the default country') . ".\n");
    exit(1);
}

echo "=== Identity-swap onboarding: " . ($config['country'] ?? 'unknown') . " ===\n";
echo "In scope: " . implode(', ', $inScope) . "\n\n";

$blocked = [];
$parked = [];

foreach ($participants as $code => $participant) {
    // Rails and VouchMorph itself are not endpoints for identity money.
    if ($code === 'VOUCHMORPH' || strtoupper((string)($participant['type'] ?? '')) === 'SWITCH') {
        continue;
    }

    $findings = inspect($participant);

    if (!in_array($code, $inScope, true)) {
        $parked[$code] = $findings;
        continue;
    }

    report($code, $findings);

    if ($findings['scaffolded'] && ($findings['send'] !== [] || $findings['receive'] !== [])) {
        $blocked[] = $code;
    }
}

foreach ($inScope as $code) {
    if (!isset($participants[$code])) {
        echo "{$code}: not present in participants.yaml at all\n";
        $blocked[] = $code;
    }
}

if ($parked !== []) {
    echo "\n--- not in scope yet (not judged) ---\n";
    foreach ($parked as $code => $findings) {
        report($code, $findings);
    }
}

echo "\n";

if ($blocked === []) {
    echo "Every in-scope institution is usable as both a source and a destination.\n";
    exit(0);
}

echo count($blocked) . " in-scope institution(s) not fully onboarded: " . implode(', ', $blocked) . "\n";
echo "Each needs its real account/wallet numbers filled into participants.yaml.\n";
echo "Those numbers have to come from the institution -- they cannot be guessed.\n";
exit(1);
