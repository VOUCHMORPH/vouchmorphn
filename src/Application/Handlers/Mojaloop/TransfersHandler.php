<?php

declare(strict_types=1);

namespace Application\Handlers\Mojaloop;

use Domain\Services\SwapService;
use Infrastructure\Mojaloop\Dto\TransferRequest;
use Infrastructure\Mojaloop\IdempotencyService;
use Infrastructure\Mojaloop\MojaloopErrorMapper;
use PDO;

/**
 * Previous version called:
 *   $this->swapService->executeSwap($payerFsp, $payeeFsp, $amount,
 *       'wallet', 'wallet', null, $metadata, false)
 * That method does not exist on the real SwapService - every
 * confirmed entry point this session takes ONE array payload:
 *   executeAtomicSwap(array $payload): array
 *
 * Also added: this endpoint is reachable by an external switch/TTK
 * that may legitimately retry a request (network timeout, no
 * response received). Without protection, a retried transferId could
 * execute the underlying swap twice. IdempotencyService (already
 * correct elsewhere in this codebase) is used here exactly the way
 * SwapService itself already uses it internally - reserve the key
 * before executing, return the cached result on a repeat, store the
 * real result once it completes.
 */
class TransfersHandler
{
    private SwapService $swapService;
    private PDO $db;

    public function __construct(SwapService $swapService, PDO $db)
    {
        $this->swapService = $swapService;
        $this->db = $db;
    }

    public function executeTransfer(TransferRequest $request): array
    {
        $idempotencyKey = 'MOJALOOP_TRANSFER_' . $request->transferId;

        $existing = IdempotencyService::resolve($this->db, $idempotencyKey);
        if ($existing !== null) {
            return $existing;
        }

        if (!IdempotencyService::reserve($this->db, $idempotencyKey)) {
            // Another request for this exact transferId is already in
            // flight (race between two near-simultaneous retries) -
            // don't execute a second time.
            return [
                'status' => 'error',
                'errorInformation' => [
                    'errorCode' => '2001',
                    'errorDescription' => 'Transfer already in progress for this transferId',
                ],
            ];
        }

        $payload = [
            'swap_type' => 'STANDARD',
            'reference' => $request->transferId,
            'idempotency_key' => $idempotencyKey,
            'amount' => $request->amount,
            'currency' => $request->currency,
            'asset_type' => 'WALLET',
            'from_institution' => $request->payerFsp,
            'source_institution' => $request->payerFsp,
            'to_institution' => $request->payeeFsp,
            'destination_institution' => $request->payeeFsp,
            'source_identifier' => $request->metadata['source_account'] ?? null,
            'destination_identifier' => $request->metadata['recipient_account'] ?? null,
        ];

        try {
            $swapResult = $this->swapService->executeAtomicSwap($payload);
        } catch (\Throwable $e) {
            $errorResult = [
                'status' => 'error',
                'transferId' => $request->transferId,
                'errorInformation' => MojaloopErrorMapper::map([
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ])['errorInformation'],
            ];
            IdempotencyService::store($this->db, $idempotencyKey, $errorResult);
            return $errorResult;
        }

        $isSuccess = in_array($swapResult['status'] ?? '', ['success', 'pending', 'pending_settlement'], true);

        $result = $isSuccess
            ? [
                'status' => 'success',
                'transferId' => $request->transferId,
                'transferState' => 'COMMITTED',
                'completedTimestamp' => date('c'),
                'settlementAmount' => [
                    'amount' => $swapResult['amount'] ?? $request->amount,
                    'currency' => $request->currency,
                ],
            ]
            : [
                'status' => 'error',
                'transferId' => $request->transferId,
                'errorInformation' => MojaloopErrorMapper::map($swapResult)['errorInformation'],
            ];

        IdempotencyService::store($this->db, $idempotencyKey, $result);
        return $result;
    }
}
