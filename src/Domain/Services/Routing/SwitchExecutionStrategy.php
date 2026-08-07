<?php
declare(strict_types=1);

namespace Domain\Services\Routing;

use Infrastructure\Adapters\InstitutionAdapterFactory;

final class SwitchExecutionStrategy implements ExecutionStrategyInterface
{
    public function __construct(
        private string $railName,                      // e.g. 'CENTRALSWITCH'
        private InstitutionAdapterFactory $adapterFactory,
        private array $countryParticipants,             // from participants.yaml for this country
    ) {}

    public function execute(array $payload, ExecutionPlan $plan): array
    {
        // Resolve the switch's own adapter the SAME way any bank/MNO
        // adapter is resolved — no special-cased HTTP client, no
        // hardcoded URL. The adapter for CENTRALSWITCH reads its
        // base_url/auth from endpoints.yaml, exactly like ZURUBANK's does.
        $switchAdapter = $this->adapterFactory->getAdapter($this->railName);

        $originCode = $payload['from_institution'];
        $destCode = $payload['to_institution'] ?? $payload['destination_institution'];

        $originSwitchId = $this->countryParticipants[$originCode]['switch_participant_ids'][$this->railName] ?? null;
        $destSwitchId = $this->countryParticipants[$destCode]['switch_participant_ids'][$this->railName] ?? null;

        if (!$originSwitchId || !$destSwitchId) {
            throw new \RuntimeException("{$originCode} or {$destCode} has no switch_participant_ids entry for rail {$this->railName}");
        }

        $vouchmorphSwitchId = $this->countryParticipants['VOUCHMORPH']['switch_participant_ids'][$this->railName] ?? null;

        $result = $switchAdapter->submitTransfer([
            'method' => 'PUSH',
            // WHO IS CALLING (VouchMorph, the orchestrator) - distinct from
            // origin_participant_id below, which is just data describing
            // whose money this is. CENTRALSWITCH authenticates the caller,
            // not the institution named inside the payload - see
            // submit_transfer.php's verifyParticipantRequest() call.
            'requester_participant_id' => $vouchmorphSwitchId,
            'origin_participant_id' => $originSwitchId,
            'destination_participant_id' => $destSwitchId,
            'origin_account_number' => $payload['source_identifier'] ?? $payload['source_account'] ?? '',
            'destination_account_number' => $payload['destination_identifier'] ?? '',
            'amount' => $payload['amount'],
            'currency' => $payload['currency'] ?? 'BWP',
            'idempotency_key' => $payload['idempotency_key'] ?? null,
        ]);

        if (!($result['success'] ?? false)) {
            throw new \RuntimeException("Switch rejected transfer: " . ($result['message'] ?? 'unknown error'));
        }

        return [
            'status' => $result['data']['status'] ?? 'COMPLETED',
            'reference' => $result['data']['transaction_reference'] ?? null,
            'rail' => $this->railName,
        ];
    }
}
