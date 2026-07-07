<?php
declare(strict_types=1);

namespace Domain\Services\MultiSource;

use PDO;
use Exception;
use RuntimeException;
use Domain\Services\SwapService;
use Domain\Services\Settlement\HybridSettlementStrategy;
use Domain\Services\ContributionCalculator;
use Domain\Services\MultiSourceFeeCalculator;
use Domain\Services\CardService;
use Domain\Repositories\FundingPoolRepository;
use Domain\Repositories\PoolContributionRepository;
use Domain\Models\PoolContribution;
use Domain\ValueObjects\ContributionStatus;
use Infrastructure\Crypto\CertificateManager;
use Infrastructure\Crypto\SignatureVerifier;

/**
 * MULTI-SOURCE SWAP EXECUTOR
 *
 * Combines N funding sources to cover ONE destination amount - used when
 * a single balance is insufficient to complete a payment.
 *
 * Flow: create pool -> calculate & persist contributions -> verify sources
 * -> place holds -> sign aggregate -> credit destination once -> debit all
 * sources -> settle & invoice -> mark complete.
 *
 * NOW SUPPORTS CARD DESTINATIONS:
 * - destination_asset_type = 'CARD' routes to CardService::loadCard()
 * - Works for ANY card brand (VouchMorph, Visa, Mastercard) since it's
 *   just a deposit to a card, not a network-specific hook
 *
 * Built against the REAL, verified APIs of SwapService, FundingPoolRepository,
 * PoolContributionRepository, ContributionCalculator, MultiSourceFeeCalculator,
 * HybridSettlementStrategy, CertificateManager, and SignatureVerifier.
 */
class MultiSourceSwapExecutor
{
    private PDO $db;
    private SwapService $swapService;
    private HybridSettlementStrategy $settlement;
    private ContributionCalculator $contributionCalculator;
    private MultiSourceFeeCalculator $feeCalculator;
    private FundingPoolRepository $poolRepository;
    private PoolContributionRepository $contributionRepository;
    private CertificateManager $certManager;
    private SignatureVerifier $signatureVerifier;
    private ?CardService $cardService = null;
    private $logger;
    private array $config;
    private string $countryCode;

    public function __construct(
        PDO $db,
        SwapService $swapService,
        HybridSettlementStrategy $settlement,
        array $config,
        string $countryCode,
        ?CardService $cardService = null,
        $logger = null
    ) {
        $this->db = $db;
        $this->swapService = $swapService;
        $this->settlement = $settlement;
        $this->config = $config;
        $this->countryCode = $countryCode;
        $this->cardService = $cardService;

        $this->contributionCalculator = new ContributionCalculator();
        $this->feeCalculator = new MultiSourceFeeCalculator($config, $countryCode);
        $this->poolRepository = new FundingPoolRepository($db);
        $this->contributionRepository = new PoolContributionRepository($db);
        $this->certManager = new CertificateManager('VOUCHMORPH');
        $this->signatureVerifier = new SignatureVerifier($db);

        if ($logger === null) {
            $this->logger = new class {
                public function info($m, array $c = []) { error_log("[MS_EXECUTOR][INFO] {$m} " . json_encode($c)); }
                public function error($m, array $c = []) { error_log("[MS_EXECUTOR][ERROR] {$m} " . json_encode($c)); }
                public function warning($m, array $c = []) { error_log("[MS_EXECUTOR][WARNING] {$m} " . json_encode($c)); }
                public function debug($m, array $c = []) { error_log("[MS_EXECUTOR][DEBUG] {$m} " . json_encode($c)); }
                public function log($l, $m, array $c = []) { error_log("[MS_EXECUTOR][{$l}] {$m} " . json_encode($c)); }
            };
        } else {
            $this->logger = $logger;
        }
    }

    public function execute(array $payload): array
    {
        $this->db->beginTransaction();
        $poolId = null;
        $heldContributions = [];

        try {
            $pool = $this->createPool($payload);
            $poolId = $pool['id'];
            $this->logger->info('Pool created', ['pool_id' => $poolId]);

            $contributions = $this->calculateAndPersistContributions($pool, $payload);
            $this->logger->info('Contributions calculated & persisted', ['count' => count($contributions)]);

            $verifications = $this->verifyAllSources($contributions);
            $this->logger->info('Sources verified', ['count' => count($verifications)]);

            $holds = $this->placeHoldsOnAllSources($pool, $contributions, $verifications, $heldContributions);
            $this->logger->info('Holds placed', ['count' => count($holds)]);

            $masterSignature = $this->generateMasterSignature($pool, $holds);
            $this->logger->info('Master signature generated');

            // ============================================================
            // DESTINATION: Route based on destination_asset_type
            // ============================================================
            $destinationAssetType = strtoupper($pool['destination_asset_type'] ?? 'WALLET');
            $destinationResult = $this->executeDestination($pool, $masterSignature, $destinationAssetType);
            $this->logger->info('Destination executed', [
                'success' => $destinationResult['success'] ?? false,
                'asset_type' => $destinationAssetType
            ]);

            $debits = $this->debitAllSources($pool, $holds);
            $this->logger->info('Sources debited', ['count' => count($debits)]);

            $settlementResult = $this->settleAndInvoice($pool, $contributions, $destinationResult);
            $this->logger->info('Settlement & invoicing complete');

            $this->poolRepository->updateStatus($poolId, 'COMPLETED');
            $this->markContributionsCompleted($contributions);

            $this->db->commit();

            return $this->buildResponse($pool, $contributions, $destinationResult, $settlementResult);

        } catch (Exception $e) {
            $this->db->rollBack();
            $this->logger->error('Multi-source pool execution failed', ['error' => $e->getMessage()]);

            $this->rollbackHeldContributions($heldContributions);

            if ($poolId) {
                $this->poolRepository->updateStatus($poolId, 'FAILED', ['error' => $e->getMessage()]);
            }

            throw new RuntimeException('Multi-source swap failed: ' . $e->getMessage(), 0, $e);
        }
    }

    // ============================================================
    // POOL CREATION
    // ============================================================

    private function createPool(array $payload): array
    {
        $poolId = $payload['pool_id'] ?? 'POOL_' . bin2hex(random_bytes(8));
        $destinationIdentifier = $this->swapService->extractDestinationIdentifier($payload);
        $destinationAssetType = $this->swapService->extractDestinationAssetType($payload);

        $pool = [
            'id' => $poolId,
            'sources' => $payload['sources'] ?? [],
            'amount' => (float)($payload['amount'] ?? 0),
            'currency' => $payload['currency'] ?? 'BWP',
            'destination_institution' => $payload['to_institution'] ?? $payload['destination_institution'] ?? null,
            'destination_identifier' => $destinationIdentifier['identifier'] ?? null,
            'destination_identifier_type' => $destinationIdentifier['type'] ?? null,
            'destination_asset_type' => $destinationAssetType,
            'delivery_mode' => strtolower($payload['delivery_mode'] ?? 'deposit'),
            'contribution_strategy' => $payload['contribution_strategy'] ?? 'RATIO',
            'reference' => $payload['reference'] ?? ('SWAP_' . bin2hex(random_bytes(8))),
            'status' => 'CREATED',
        ];

        if (empty($pool['destination_institution'])) {
            throw new RuntimeException('destination_institution is required for a multi-source pool');
        }

        $this->poolRepository->saveFromArray($pool);
        return $pool;
    }

    // ============================================================
    // CONTRIBUTIONS
    // ============================================================

    private function calculateAndPersistContributions(array $pool, array $payload): array
    {
        if (count($pool['sources']) < 2) {
            throw new RuntimeException('At least 2 sources required for a multi-source pool');
        }

        $sourcesWithBalances = [];
        foreach ($pool['sources'] as $source) {
            $balance = $this->swapService->getSourceAvailableBalance($source);
            $sourcesWithBalances[] = array_merge($source, ['available_balance' => $balance]);
        }

        $contributions = $this->contributionCalculator->calculateContributions(
            $pool['amount'],
            $sourcesWithBalances,
            $pool['contribution_strategy'],
            $payload['user_amounts'] ?? null,
            $payload['priority_order'] ?? null
        );

        $persisted = [];
        foreach ($contributions as $index => $contribution) {
            $sourceInfo = $contribution['source'];

            $model = new PoolContribution(
                $pool['id'],
                $pool['reference'] . '-' . str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT),
                $index + 1,
                $sourceInfo['institution'],
                $contribution['asset_type'] ?? $sourceInfo['asset_type'] ?? 'ACCOUNT',
                $sourceInfo['identifier'] ?? '',
                (float)($contribution['requested_amount'] ?? $contribution['actual_amount']),
                (float)$contribution['actual_amount'],
                $pool['currency']
            );

            $saved = $this->contributionRepository->save($model);

            $contribution['_contribution_id'] = $saved->getId();
            $contribution['_sub_reference'] = $model->getSubReference();
            $contribution['institution'] = $sourceInfo['institution'];
            $contribution['amount'] = $contribution['actual_amount'];

            $persisted[] = $contribution;
        }

        return $persisted;
    }

    // ============================================================
    // VERIFICATION
    // ============================================================

    private function verifyAllSources(array $contributions): array
    {
        $verifications = [];

        foreach ($contributions as $index => $contribution) {
            $source = $contribution['source'];
            $institution = $source['institution'];

            $verifyPayload = [
                'amount' => $contribution['amount'],
                'currency' => $contribution['currency'] ?? 'BWP',
                'asset_type' => $contribution['asset_type'] ?? 'ACCOUNT',
                'source_identifier' => $source['identifier'] ?? null,
                'from_institution' => $institution,
                'source_institution' => $institution,
            ];

            $result = $this->swapService->verifyAssetSigned($verifyPayload, $institution);

            if (!($result['verified'] ?? false)) {
                throw new RuntimeException(
                    "Verification failed for source: {$institution} - " . ($result['message'] ?? 'Unknown error')
                );
            }

            if (isset($contribution['_contribution_id'])) {
                $this->contributionRepository->updateStatus(
                    $contribution['_contribution_id'],
                    ContributionStatus::VERIFIED
                );
            }

            $verifications[$index] = [
                'institution' => $institution,
                'verified' => true,
                'result' => $result,
            ];
        }

        return $verifications;
    }

    // ============================================================
    // HOLDS
    // ============================================================

    private function placeHoldsOnAllSources(array $pool, array $contributions, array $verifications, array &$heldContributions): array
    {
        $holds = [];

        foreach ($contributions as $index => $contribution) {
            $source = $contribution['source'];
            $institution = $source['institution'];
            $verificationResult = $verifications[$index]['result'] ?? [];

            $holdPayload = [
                'amount' => $contribution['amount'],
                'currency' => $contribution['currency'] ?? 'BWP',
                'asset_type' => $contribution['asset_type'] ?? 'ACCOUNT',
                'source_identifier' => $source['identifier'] ?? null,
                'hold_reason' => 'MULTI_SOURCE_POOL_' . $pool['id'],
                'reference' => $contribution['_sub_reference'] ?? $pool['reference'],
                'from_institution' => $institution,
                'source_institution' => $institution,
            ];

            $result = $this->swapService->placeHoldSigned($holdPayload, $institution, $verificationResult);

            if (!($result['hold_placed'] ?? false)) {
                throw new RuntimeException(
                    "Hold failed for source: {$institution} - " . ($result['message'] ?? 'Unknown error')
                );
            }

            $holdData = [
                'institution' => $institution,
                'hold_id' => $result['local_hold_id'] ?? null,
                'hold_reference' => $result['hold_reference'] ?? null,
                'amount' => $contribution['amount'],
                'source_payload' => $contribution,
            ];

            if (isset($contribution['_contribution_id']) && !empty($holdData['hold_reference'])) {
                $this->contributionRepository->updateHoldReference(
                    $contribution['_contribution_id'],
                    $holdData['hold_reference']
                );
                $this->contributionRepository->updateStatus(
                    $contribution['_contribution_id'],
                    ContributionStatus::HELD
                );
            }

            $holds[] = $holdData;
            $heldContributions[] = $holdData;
        }

        return $holds;
    }

    // ============================================================
    // MASTER SIGNATURE
    // ============================================================

    private function generateMasterSignature(array $pool, array $holds): array
    {
        $aggregatePayload = [
            'pool_id' => $pool['id'],
            'swap_reference' => $pool['reference'],
            'total_amount' => $pool['amount'],
            'currency' => $pool['currency'],
            'destination_institution' => $pool['destination_institution'],
            'contributors' => array_map(function ($hold) {
                return [
                    'institution' => $hold['institution'],
                    'amount' => $hold['amount'],
                    'hold_reference' => $hold['hold_reference'],
                ];
            }, $holds),
        ];
        ksort($aggregatePayload);

        $signedEnvelope = $this->certManager->createSignedRequest($aggregatePayload, 'VOUCHMORPH');

        return [
            'aggregate_signature' => $signedEnvelope['signature'] ?? null,
            'aggregate_certificate' => $signedEnvelope['certificate'] ?? null,
            'payload' => $aggregatePayload,
            'signature_timestamp' => $signedEnvelope['timestamp'] ?? time(),
        ];
    }

    // ============================================================
    // DESTINATION - NOW SUPPORTS ACCOUNT, WALLET, AND CARD
    // ============================================================

    private function executeDestination(array $pool, array $masterSignature, string $destinationAssetType): array
    {
        $destinationAssetType = strtoupper($destinationAssetType);

        // ============================================================
        // BRANCH: CARD DESTINATION
        // ============================================================
        if ($destinationAssetType === 'CARD') {
            if ($this->cardService === null) {
                throw new RuntimeException('CardService not available for CARD destination');
            }

            $cardSuffix = $pool['destination_identifier'] ?? null;
            if (empty($cardSuffix)) {
                throw new RuntimeException('destination_identifier (card_suffix) required for CARD destination');
            }

            $this->logger->info('Processing CARD destination', ['card_suffix' => $cardSuffix]);

            // Load funds onto the card using CardService::loadCard()
            // This works for ANY card brand - it's just a deposit
            $loadResult = $this->cardService->loadCard([
                'card_suffix' => $cardSuffix,
                'hold_reference' => $pool['reference'] . '-CARD_LOAD',
                'amount' => $pool['amount'],
                'swap_reference' => $pool['reference'],
                'currency' => $pool['currency'],
                'metadata' => [
                    'pool_id' => $pool['id'],
                    'source_count' => count($pool['sources']),
                    'master_signature' => $masterSignature['aggregate_signature']
                ]
            ]);

            if (!$loadResult['success']) {
                throw new RuntimeException('Card load failed: ' . ($loadResult['message'] ?? 'Unknown error'));
            }

            return [
                'success' => true,
                'type' => 'CARD',
                'card_suffix' => $cardSuffix,
                'amount_loaded' => $loadResult['amount_loaded'] ?? $pool['amount'],
                'new_balance' => $loadResult['new_balance'] ?? 0,
                'transaction_reference' => $loadResult['transaction_reference'] ?? null,
                'message' => 'Card loaded successfully from pool'
            ];
        }

        // ============================================================
        // BRANCH: ACCOUNT / WALLET (existing path)
        // ============================================================
        $destinationPayload = [
            'reference' => $pool['reference'],
            'amount' => $pool['amount'],
            'currency' => $pool['currency'],
            'destination_identifier' => $pool['destination_identifier'],
            'destination_identifier_type' => $pool['destination_identifier_type'] ?? 'account',
            'destination_asset_type' => $destinationAssetType,
            'destination_institution' => $pool['destination_institution'],
            'to_institution' => $pool['destination_institution'],
            'source_type' => 'VIRTUAL_POOL',
            'pool_id' => $pool['id'],
            'master_signature' => $masterSignature['aggregate_signature'],
            'master_certificate' => $masterSignature['aggregate_certificate'],
        ];

        $result = $this->swapService->creditDestination($destinationPayload, $pool['destination_institution']);

        if (!($result['success'] ?? false)) {
            throw new RuntimeException('Destination credit failed: ' . ($result['message'] ?? 'Unknown error'));
        }

        return $result;
    }

    // ============================================================
    // DEBIT SOURCES
    // ============================================================

    private function debitAllSources(array $pool, array $holds): array
    {
        $debits = [];

        foreach ($holds as $hold) {
            $institution = $hold['institution'];

            $debitPayload = [
                'reference' => $pool['reference'],
                'hold_reference' => $hold['hold_reference'],
                'amount' => $hold['amount'],
                'reason' => 'Multi-source pool completed',
                'from_institution' => $institution,
                'source_institution' => $institution,
            ];

            $result = $this->swapService->debitSource($debitPayload, $institution);

            if (!($result['debited'] ?? false)) {
                throw new RuntimeException(
                    "Debit failed for source: {$institution} - " . ($result['message'] ?? 'Unknown error')
                );
            }

            $contribution = $hold['source_payload'] ?? null;
            if ($contribution && isset($contribution['_contribution_id'])) {
                $this->contributionRepository->updateDebitReference(
                    $contribution['_contribution_id'],
                    $result['transaction_reference'] ?? ''
                );
                $this->contributionRepository->updateStatus(
                    $contribution['_contribution_id'],
                    ContributionStatus::DEBITED
                );
            }

            $debits[] = [
                'institution' => $institution,
                'debited' => true,
                'transaction_reference' => $result['transaction_reference'] ?? null,
            ];
        }

        return $debits;
    }

    // ============================================================
    // SETTLEMENT & FEE INVOICING
    // ============================================================

    private function settleAndInvoice(array $pool, array $contributions, array $destinationResult): array
    {
        $feeResult = $this->feeCalculator->calculateFees(
            count($contributions),
            $pool['delivery_mode'],
            $pool['amount'],
            $pool['currency'],
            $pool['currency']
        );

        $settlements = [];
        foreach ($contributions as $index => $contribution) {
            $institution = $contribution['source']['institution'];
            $amount = $contribution['amount'];
            $sourceFee = $feeResult['per_source_fees'][$index] ?? 0;

            $settlement = $this->settlement->updateNetPosition(
                $pool['reference'],
                $institution,
                $pool['destination_institution'],
                $amount,
                'MULTI_SOURCE_POOL_COMPLETED',
                $pool['currency']
            );

            if ($sourceFee > 0) {
                $this->settlement->invoiceFee(
                    $pool['reference'],
                    $institution,
                    0,
                    'MULTI_SOURCE_FEE',
                    $sourceFee,
                    $pool['currency']
                );
            }

            $settlements[] = [
                'institution' => $institution,
                'amount' => $amount,
                'fee' => $sourceFee,
                'settlement' => $settlement,
            ];
        }

        return [
            'total_fees' => $feeResult['total_fees'] ?? 0,
            'settlements' => $settlements,
            'destination_result' => $destinationResult,
        ];
    }

    private function markContributionsCompleted(array $contributions): void
    {
        foreach ($contributions as $contribution) {
            if (isset($contribution['_contribution_id'])) {
                $this->contributionRepository->updateStatus(
                    $contribution['_contribution_id'],
                    ContributionStatus::COMPLETED
                );
            }
        }
    }

    // ============================================================
    // ROLLBACK
    // ============================================================

    private function rollbackHeldContributions(array $heldContributions): void
    {
        foreach ($heldContributions as $held) {
            try {
                $this->swapService->releaseHold(
                    $held['source_payload'] ?? [],
                    $held['institution'],
                    $held['hold_id'] ?? null,
                    $held['hold_reference'] ?? null
                );

                $contribution = $held['source_payload'] ?? null;
                if ($contribution && isset($contribution['_contribution_id'])) {
                    $this->contributionRepository->updateStatus(
                        $contribution['_contribution_id'],
                        ContributionStatus::FAILED
                    );
                }
            } catch (Exception $e) {
                $this->logger->error('Failed to release hold during rollback', [
                    'institution' => $held['institution'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    // ============================================================
    // RESPONSE
    // ============================================================

    private function buildResponse(array $pool, array $contributions, array $destinationResult, array $settlementResult): array
    {
        return [
            'success' => true,
            'pool_id' => $pool['id'],
            'reference' => $pool['reference'],
            'total_amount' => $pool['amount'],
            'currency' => $pool['currency'],
            'source_count' => count($contributions),
            'total_fees' => $settlementResult['total_fees'] ?? 0,
            'destination_result' => $destinationResult,
            'settlement' => $settlementResult,
            'completed_at' => date('Y-m-d H:i:s'),
        ];
    }
}
