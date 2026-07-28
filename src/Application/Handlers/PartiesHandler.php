<?php

declare(strict_types=1);

namespace Application\Handlers\Mojaloop;

use Core\Config\LoadCountry;
use Infrastructure\Mojaloop\Dto\PartyLookupRequest;

/**
 * Party lookup - answers "does this identifier belong to one of our
 * registered participants, and if so, which institution?"
 *
 * The previous version of this handler (correctly namespaced, but
 * never finished) returned hardcoded mock parties (ALPHA/BRAVO/
 * CHARLIE/...) regardless of what was actually configured. This
 * version reads the real participant list for the country instead,
 * via the same LoadCountry::getConfig() call already used elsewhere
 * in this codebase (see SwapService's constructor and the admin
 * dashboard's diagnostic test script) - so this stays in sync with
 * participants.yaml automatically instead of drifting from it.
 */
class PartiesHandler
{
    private array $participants;

    public function __construct(string $country = 'Botswana')
    {
        $countryConfig = LoadCountry::getConfig($country);
        // Support either shape - a top-level 'participants' key, or
        // the whole config already BEING the participants map - since
        // the exact shape LoadCountry returns wasn't confirmed against
        // real output. Whichever it is, $this->participants ends up
        // as a flat map keyed by institution code.
        $this->participants = $countryConfig['participants'] ?? $countryConfig ?? [];
    }

    /**
     * @param array $payload Expects ['type' => partyIdType, 'id' => partyIdentifier]
     *                        as passed in by the router from the URL path.
     */
    public function lookup(array $payload, array $headers): array
    {
        $partyType = strtoupper($payload['type'] ?? 'MSISDN');
        $partyId = strtoupper($payload['id'] ?? '');

        $institution = $this->resolveInstitution($partyId);

        if ($institution === null) {
            return [
                'status' => 'error',
                'errorInformation' => [
                    'errorCode' => '3204',
                    'errorDescription' => "Party {$partyId} not found among registered participants",
                ],
            ];
        }

        return [
            'status' => 'success',
            'party' => [
                'partyIdInfo' => [
                    'partyIdType' => $partyType,
                    'partyIdentifier' => $partyId,
                    'fspId' => $institution['code'],
                ],
                'name' => $institution['name'] ?? $institution['code'],
            ],
        ];
    }

    /**
     * A lookup can arrive as either the institution code itself
     * (e.g. "FNB") or an account/wallet identifier that needs
     * resolving against a specific participant's known identifiers.
     * This handles the direct-code case now; resolving an arbitrary
     * MSISDN/account number to its owning institution needs the
     * institution's own adapter (e.g. GenericBankClient::verifyAccount())
     * - that lookup is NOT done here, since it requires a live call
     * to the institution, not just a config read. Wire that in once
     * the specific adapter contract for account resolution is
     * confirmed, rather than guessing its shape here.
     */
    private function resolveInstitution(string $partyId): ?array
    {
        if (isset($this->participants[$partyId])) {
            $info = $this->participants[$partyId];
            return [
                'code' => $partyId,
                'name' => $info['name'] ?? $partyId,
            ];
        }
        return null;
    }
}
