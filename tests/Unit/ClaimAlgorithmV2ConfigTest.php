<?php

use PHPUnit\Framework\TestCase;
use Core\Config\LoadCountry;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Config/LoadCountry.php';

/**
 * Config invariants for the claim_algorithm_v2 rollout.
 *
 * The flag is not a feature toggle in the harmless sense -- it decides where
 * money goes. Enabled, expireIdentitySwap() parks GOVERNMENT and
 * BUSINESS_OR_TRUST value in the beneficiary's reservation account at the
 * source instead of returning it to the sender, which is what Phase D
 * requires of money that "cannot be un-sent".
 *
 * But that parking depends on the reservation-account primitive. When the
 * reservation account is unsupported, still pending, or fails to create,
 * SwapService falls through to the personal release path -- back to the
 * sender -- and only logs. So an institution with claim_algorithm_v2 on and
 * reservation_accounts off looks compliant in config and behaves exactly
 * like a non-compliant one at runtime. That pairing is the bug this guards.
 */
class ClaimAlgorithmV2ConfigTest extends TestCase
{
    /** @return array<string, array<string, mixed>> */
    private function participants(): array
    {
        return LoadCountry::getConfig()['participants'] ?? [];
    }

    public function testEveryV2InstitutionAlsoHasReservationAccounts(): void
    {
        $enabled = [];

        foreach ($this->participants() as $code => $participant) {
            if (!($participant['capabilities']['claim_algorithm_v2'] ?? false)) {
                continue;
            }
            $enabled[] = $code;

            $this->assertTrue(
                (bool)($participant['capabilities']['reservation_accounts'] ?? false),
                "{$code} has claim_algorithm_v2 on but reservation_accounts off: Phase D parking "
                . 'would silently fall through to releasing the money back to the sender'
            );
        }

        $this->assertNotEmpty($enabled, 'no institution has claim_algorithm_v2 enabled at all');
    }

    /**
     * Point X writes net_positions rows and fee invoices against the
     * institutions funding a claim, and Phase D parks through the source's
     * own accounts. Neither works against an onboarding placeholder.
     */
    public function testNoV2InstitutionIsStillOnOnboardingPlaceholders(): void
    {
        foreach ($this->participants() as $code => $participant) {
            if (!($participant['capabilities']['claim_algorithm_v2'] ?? false)) {
                continue;
            }

            $identifiers = [
                'settlement_account.identifier' => $participant['settlement_account']['BWP']['identifier'] ?? null,
                'identity_accounts.receiving_identifier' => $participant['identity_accounts']['BWP']['receiving_identifier'] ?? null,
                'identity_accounts.holding_identifier' => $participant['identity_accounts']['BWP']['holding_identifier'] ?? null,
            ];

            foreach ($identifiers as $field => $value) {
                $this->assertNotNull($value, "{$code} has claim_algorithm_v2 on but no {$field}");
                $this->assertStringNotContainsStringIgnoringCase(
                    'REPLACE_WITH_REAL',
                    (string)$value,
                    "{$code} has claim_algorithm_v2 on but {$field} is still a placeholder"
                );
            }
        }
    }

    /**
     * The rollout is deliberately staged, so an institution arriving at
     * claim_algorithm_v2: true should be a decision someone made, not a
     * default that crept in.
     */
    public function testTheFlagDefaultsToOffForInstitutionsNotYetOnboarded(): void
    {
        foreach (['ABSA', 'CAZACOM', 'MTN'] as $code) {
            $participant = $this->participants()[$code] ?? null;
            $this->assertNotNull($participant, "{$code} is missing from participants.yaml");

            $this->assertFalse(
                (bool)($participant['capabilities']['claim_algorithm_v2'] ?? false),
                "{$code} is still on onboarding placeholders, so claim_algorithm_v2 must stay off"
            );
        }
    }
}
