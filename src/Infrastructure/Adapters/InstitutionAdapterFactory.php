<?php
declare(strict_types=1);

namespace Infrastructure\Adapters;

use Infrastructure\Banks\GenericBankClient;
use RuntimeException;

/**
 * Dispatches to the correct InstitutionAdapterInterface implementation for
 * a given institution. This is the "which car is on the line" decision that
 * was previously left to endpoints.yaml's unused `adapter_class` field.
 *
 * Every institution gets GenericInstitutionAdapter by default (same crew,
 * same stations, config-driven via GenericBankClient underneath - this
 * covers ZURUBANK, SACCUSSALIS, and any future JSON-shim institution with
 * zero new code). Only an institution that genuinely cannot implement the
 * JSON contract gets a bespoke adapter_class here.
 */
class InstitutionAdapterFactory
{
    /** @var array<string, InstitutionAdapterInterface> */
    private array $instances = [];

    public function __construct(
        private array $participants,
        private $logger
    ) {}

   public function getAdapter(string $institution): InstitutionAdapterInterface
{
    if (isset($this->instances[$institution])) {
        return $this->instances[$institution];
    }
    $participant = $this->getParticipant($institution);

    $adapterClass = $participant['adapter_class'] ?? null;
    if ($adapterClass === null
        || !class_exists($adapterClass)
        || !is_subclass_of($adapterClass, InstitutionAdapterInterface::class)
    ) {
        $adapterClass = GenericInstitutionAdapter::class;
    }

    // FIX: bank client class was hardcoded to GenericBankClient for every
    // institution, with no way to select a bespoke BankAPIInterface
    // implementation like CardAcquirerBankClient. This left every bit of
    // FNBB's card-specific logic (pre_auth_check bypass, CVV handling,
    // destination_enabled gating) completely unreachable regardless of
    // config, since the class that would run that logic was never
    // instantiated.
    $bankClientClass = $participant['bank_client_class'] ?? GenericBankClient::class;
    if (!class_exists($bankClientClass) || !is_subclass_of($bankClientClass, \Infrastructure\Banks\Contracts\BankAPIInterface::class)) {
        $bankClientClass = GenericBankClient::class;
    }
    $bankClient = new $bankClientClass($participant);

    $adapter = new $adapterClass($bankClient, $this->logger, $institution, $participant);
    $this->instances[$institution] = $adapter;
    return $adapter;
}

    private function getParticipant(string $institution): array
    {
        if (isset($this->participants[$institution])) {
            return $this->participants[$institution];
        }

        foreach ($this->participants as $code => $participant) {
            if (strtolower($code) === strtolower($institution)) {
                return $participant;
            }
        }

        throw new RuntimeException("Participant not found: {$institution}");
    }
}
