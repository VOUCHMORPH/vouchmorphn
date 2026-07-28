<?php

declare(strict_types=1);

namespace Infrastructure\Mojaloop\Dto;

class PartyLookupRequest
{
    public string $partyIdType;
    public string $partyIdentifier;
    public ?string $fspId;

    public function __construct(array $data)
    {
        $this->partyIdType = $data['partyIdType'] ?? '';
        $this->partyIdentifier = $data['partyIdentifier'] ?? '';
        $this->fspId = $data['fspId'] ?? null;
    }
}
