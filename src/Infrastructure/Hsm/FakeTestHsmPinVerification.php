<?php
declare(strict_types=1);

namespace Infrastructure\Hsm;

/**
 * ==========================================================================
 * TEST-ONLY FAKE HSM — NEVER DEPLOY THIS TO ANY ENVIRONMENT THAT TOUCHES
 * REAL CARD TRAFFIC OR REAL MONEY.
 * ==========================================================================
 *
 * Verifies a PIN block against a single fixed TEST PIN in plaintext — this
 * is the exact anti-pattern the real interface's header warns against,
 * done here ONLY because it's explicitly scoped to internal
 * SwapService-flow testing while a real HSM vendor integration isn't
 * available yet.
 *
 * Guard rails so this can't leak into production by accident:
 *   - Refuses to run at all unless APP_ENV is explicitly 'test' or 'dev'.
 *   - Only ever "verifies" a single hardcoded test PIN block value — a
 *     real 4-digit PIN encrypted under nothing (plaintext-in-hex, for
 *     test-harness convenience only), never a real ISO 9564 PIN block.
 *   - Logs loudly on every call, so it is impossible to miss in logs
 *     that a test-mode PIN path was used.
 */
class FakeTestHsmPinVerification implements PinVerificationInterface
{
    // Test PIN "0000" represented as plaintext hex for the fake — a real
    // PIN block is never plaintext, this is a test-harness shortcut only.
    private const TEST_PIN_BLOCK_HEX = '30303030';

    public function __construct()
    {
        $appEnv = getenv('APP_ENV') ?: '';
        if (!in_array($appEnv, ['test', 'dev'], true)) {
            throw new \RuntimeException(
                'FakeTestHsmPinVerification refused to initialize — APP_ENV is ' .
                "'{$appEnv}', not 'test' or 'dev'. This class must never run " .
                'against real traffic. If this is genuinely a test/dev environment, ' .
                'set APP_ENV accordingly.'
            );
        }
    }

    public function verifyPin(string $encryptedPinBlock, string $pan, string $keyZoneId): bool
    {
        error_log("[FAKE HSM - TEST MODE ONLY] verifyPin called for PAN ending " .
            substr($pan, -4) . ", keyZoneId={$keyZoneId} — this is NOT a real HSM check.");

        return hash_equals(self::TEST_PIN_BLOCK_HEX, $encryptedPinBlock);
    }
}
