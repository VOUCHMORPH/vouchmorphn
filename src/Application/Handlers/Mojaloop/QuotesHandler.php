<?php
declare(strict_types=1);

namespace Application\Handlers\Mojaloop;

use Domain\Services\SwapService;
use Infrastructure\Mojaloop\Dto\QuoteRequest;

/**
 * Uses SwapService::getFeeService() to reach the real, already-wired
 * FeeService instance rather than calling calculateFeesWithDetails()
 * directly — that method is private on SwapService and would fatal.
 * FeeService::calculateFees() is public and returns the exact shape
 * this handler needs (total_fee, net_amount, breakdown, forex, etc.)
 * — confirmed against the real FeeService.php.
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

        try {
            $fees = $this->swapService->getFeeService()->calculateFees('SWAP', $request->amount, $payload);
        } catch (\Throwable $e) {
            error_log("[QuotesHandler] Fee calculation failed: " . $e->getMessage());
            return [
                'status' => 'error',
                'errorInformation' => [
                    'errorCode' => '2001',
                    'errorDescription' => 'Could not calculate fees for this quote: ' . $e->getMessage(),
                ],
            ];
        }

        // FeeService::calculateFees() confirmed return shape:
        // total_fee, net_amount, gross_amount, breakdown, forex,
        // fee_currency_conversion, distribution, destination_split.
        return [
            'status' => 'success',
            'transactionId' => $request->transactionId,
            'transferAmount' => [
                'amount' => $fees['net_amount'] ?? $request->amount,
                'currency' => $fees['forex']['to_currency'] ?? $request->currency,
            ],
            'fees' => [
                'total_fee' => $fees['total_fee'] ?? 0,
                'currency' => $fees['gross_amount_currency'] ?? $request->currency,
                'breakdown' => $fees['breakdown'] ?? [],
            ],
            'expiration' => date('c', strtotime('+30 seconds')),
        ];
    }
}
