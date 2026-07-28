<?php

declare(strict_types=1);

namespace Application\Handlers\Mojaloop;

use Domain\Services\SwapService;
use Infrastructure\Mojaloop\Dto\QuoteRequest;

/**
 * Previous version called $this->swapService->calculateFeesAndFinalAmount(...)
 * - that method does not exist on the real SwapService. The real
 * method is calculateFeesWithDetails(string $swapType, float $amount,
 * array $payload): array, confirmed against SwapService.php earlier
 * this session. Also fixed: SwapService was type-hinted as
 * BUSINESS_LOGIC_LAYER\services\SwapService, a class that doesn't
 * exist - the real class is Domain\Services\SwapService.
 */
class QuotesHandler
{
    private SwapService $swapService;

    public function __construct(SwapService $swapService)
    {
        $this->swapService = $swapService;
    }

    public function createQuote(QuoteRequest $request): array
    {
        $payload = [
            'swap_type' => 'STANDARD',
            'amount' => $request->amount,
            'currency' => $request->currency,
            'from_institution' => $request->payerFsp,
            'to_institution' => $request->payeeFsp,
        ];

        $fees = $this->swapService->calculateFeesWithDetails('SWAP', $request->amount, $payload);

        return [
            'status' => 'success',
            'transactionId' => $request->transactionId,
            'transferAmount' => [
                'amount' => $fees['final_amount'] ?? $fees['net_amount'] ?? $request->amount,
                'currency' => $request->currency,
            ],
            'fees' => $fees['fee_details'] ?? $fees['fee_breakdown'] ?? null,
            'expiration' => date('c', strtotime('+30 seconds')),
        ];
    }
}
