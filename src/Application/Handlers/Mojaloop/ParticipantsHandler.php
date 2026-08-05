<?php

declare(strict_types=1);

namespace Application\Handlers\Mojaloop;

use Core\Config\LoadCountry;
use Infrastructure\Mojaloop\Dto\PartyLookupRequest;

/**
 * Previous version read $this->swapService->participants as a public
 * property. That's never been confirmed to actually be public on the
 * real SwapService - given how carefully this class guards its
 * internal state everywhere else (private $currentHoldId,
 * $currentSwapRef, etc.), assuming a public property here is risky.
 * This reads the same LoadCountry config PartiesHandler uses instead,
 * so it doesn't depend on SwapService's internals at all.
 */
class ParticipantsHandler
{
    private array $participants;

    public function __construct(string $country = 'Botswana')
    {
        $countryConfig = LoadCountry::getConfig($country);
        $this->participants = $countryConfig['participants'] ?? $countryConfig ?? [];
    }

    public function lookup(PartyLookupRequest $request): array
    {
        $id = strtoupper($request->partyIdentifier);

        if (!isset($this->participants[$id])) {
            return ['status' => 'error', 'message' => 'Participant not found'];
        }

        return ['status' => 'success', 'participant' => $this->participants[$id]];
    }
}
