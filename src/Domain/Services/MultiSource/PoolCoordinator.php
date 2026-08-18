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
use Domain\Repositories\FundingPoolRepository;
use Domain\Repositories\PoolContributionRepository;
use Domain\ValueObjects\PoolStatus;
use Domain\ValueObjects\ContributionStatus;
use Domain\Models\PoolContribution;
use Infrastructure\Crypto\AggregateSigner;
use Infrastructure\Banks\GenericBankClient;

class PoolCoordinator
{
    private PDO $db;
    private SwapService $swapService;
    private HybridSettlementStrategy $settlement;
    private AggregateSigner $aggregateSigner;
    private ContributionCalculator $contributionCalculator;
    private MultiSourceFeeCalculator $feeCalculator;
    private FundingPoolRepository $poolRepository;
    private PoolContributionRepository $contributionRepository;
    private PoolStateMachine $stateMachine;
    private $logger;
    private array $config;
    private string $countryCode;
    private ?array $forexRateSnapshot = null;

    public function __construct(
        PDO $db,
        SwapService $swapService,
        HybridSettlementStrategy $settlement,
        AggregateSigner $aggregateSigner,
        array $config,
        string $countryCode,
        $logger = null
    ) {
        $this->db = $db;
        $this->swapService = $swapService;
        $this->settlement = $settlement;
        $this->aggregateSigner = $aggregateSigner;
        $this->config = $config;
        $this->countryCode = $countryCode;
        
        // Use default logger if none provided
        if ($logger === null) {
            $this->logger = new class {
                public function info($message, array $context = []) {
                    error_log("[POOL] INFO: " . $message . " " . json_encode($context));
                }
                public function error($message, array $context = []) {
                    error_log("[POOL] ERROR: " . $message . " " . json_encode($context));
                }
                public function warning($message, array $context = []) {
                    error_log("[POOL] WARNING: " . $message . " " . json_encode($context));
                }
                public function debug($message, array $context = []) {
                    error_log("[POOL] DEBUG: " . $message . " " . json_encode($context));
                }
                public function log($level, $message, array $context = []) {
                    error_log("[POOL] {$level}: " . $message . " " . json_encode($context));
                }
            };
        } else {
            $this->logger = $logger;
        }
        
        // Initialize dependencies
        $this->contributionCalculator = new ContributionCalculator();
        $this->feeCalculator = new MultiSourceFeeCalculator($config, $countryCode);
        $this->poolRepository = new FundingPoolRepository($db);
        $this->contributionRepository = new PoolContributionRepository($db);
        $this->stateMachine = new PoolStateMachine();
    }

    /**
     * FIX: Threads SwapService's savepoint pattern into PoolCoordinator.
     * 
     * In PostgreSQL, once ANY statement inside a transaction throws a
     * PDOException, the entire transaction is aborted (25P02) until a
     * ROLLBACK or ROLLBACK TO SAVEPOINT. PoolCoordinator had several
     * plain try/catch blocks that swallowed exceptions but left the
     * transaction poisoned, causing subsequent writes (including real
     * hold creation that succeeded at external institutions) to fail
     * with "current transaction is aborted".
     * 
     * This method wraps any non-critical DB write in a savepoint so
     * that a failed insert/update only rolls back that one operation,
     * not the entire pool transaction.
     */
    private function runInSavepoint(callable $callback, string $savepointName = null): mixed
    {
        if ($savepointName === null) {
            $savepointName = 'sp_' . substr(md5(microtime(true) . mt_rand()), 0, 8);
        }
        
        $inTransaction = $this->db->inTransaction();
        $savepointCreated = false;
        
        try {
            if ($inTransaction) {
                $this->db->exec("SAVEPOINT {$savepointName}");
                $savepointCreated = true;
                $this->logger->debug("Created savepoint: {$savepointName}");
            }
            
            $result = $callback();
            
            if ($savepointCreated) {
                $this->db->exec("RELEASE SAVEPOINT {$savepointName}");
                $this->logger->debug("Released savepoint: {$savepointName}");
            }
            
            return $result;
            
        } catch (Exception $e) {
            if ($savepointCreated) {
                try {
                    $this->db->exec("ROLLBACK TO SAVEPOINT {$savepointName}");
                    $this->logger->warning("Rolled back to savepoint: {$savepointName} - " . $e->getMessage());
                } catch (Exception $rollbackError) {
                    $this->logger->error("Failed to rollback savepoint: {$savepointName} - " . $rollbackError->getMessage());
                }
            }
            throw $e;
        }
    }

    public function execute(array $payload): array
    {
        $this->logger->info('PoolCoordinator executing multi-source swap', [
            'sources' => count($payload['sources'] ?? []),
            'amount' => $payload['amount'] ?? 0
        ]);

        // FIX: Check if a transaction is already open before starting one
        $transactionStartedHere = !$this->db->inTransaction();
        
        if ($transactionStartedHere) {
            $this->db->beginTransaction();
            $this->logger->debug('Started new transaction in PoolCoordinator');
        } else {
            $this->logger->debug('Using existing transaction from caller');
        }
        
        $pool = null;
        $heldSources = [];
        
        try {
            // 1. Create pool
            $pool = $this->createPool($payload);
            $this->logger->info('Pool created', ['pool_id' => $pool['id']]);
            
            // 2. Calculate contributions
            $contributions = $this->calculateContributions($pool, $payload);
            $this->logger->info('Contributions calculated', ['count' => count($contributions)]);
            
            // NEW: persist contributions immediately so they exist in the DB
            $contributions = $this->persistContributions($pool, $contributions);

            $skipped = [];
            $contributions = array_values(array_filter($contributions, function ($c) use (&$skipped) {
                $keep = (float)($c['amount'] ?? 0) > 0;
                if (!$keep) {
                    $skipped[] = $c;
                }
                return $keep;
            }));

            foreach ($skipped as $c) {
                $this->logger->info('Skipping zero-amount contribution', ['institution' => $c['institution'] ?? 'unknown']);
            }
            // 3. Transition to VERIFYING
            $this->stateMachine->transition($pool, PoolStatus::VERIFYING->value);
            
            // 4. Verify sources
            $verifications = $this->verifySources($contributions, $payload);
            $this->logger->info('Sources verified', ['verified' => count($verifications)]);
            
            // 5. Transition to HOLDING
            $this->stateMachine->transition($pool, PoolStatus::HOLDING->value);
            
            // 6. Place holds
            $holds = $this->placeHolds($pool, $contributions, $verifications, $heldSources);
            $this->logger->info('Holds placed', ['holds' => count($holds)]);
            
            // 7. Transition to FUNDED
            $this->stateMachine->transition($pool, PoolStatus::FUNDED->value);
            
            // 8. Generate master signature
            $masterSignature = $this->aggregateSigner->signAggregate($pool, $holds, $verifications);
            $this->logger->info('Master signature generated');
            
            // 9. Transition to DESTINATION_PENDING
            $this->stateMachine->transition($pool, PoolStatus::DESTINATION_PENDING->value);
            
            // 10. Execute destination
            $destinationResult = $this->executeDestination($pool, $contributions, $masterSignature, $holds);
            $this->logger->info('Destination executed', ['success' => $destinationResult['success'] ?? false]);

            if (!($destinationResult['success'] ?? false)) {
                throw new RuntimeException(
                    "Destination credit failed: " . ($destinationResult['message'] ?? 'Unknown error')
                );
            }

            $isDeferred = ($destinationResult['_defer_debit'] ?? false) === true;

            if ($isDeferred) {
                // CASHOUT or IDENTITY: destination has NOT actually delivered value
                // yet (code not redeemed / claim not confirmed) — do NOT debit
                // sources now. Persist pool state and stop; a separate confirm
                // call finishes debit/settle/invoice later.
                $deferredStatus = $destinationResult['_defer_status'] ?? PoolStatus::PENDING_CASHOUT->value;
                $this->stateMachine->transition($pool, $deferredStatus);
                $this->poolRepository->updateStatus($pool['id'], $deferredStatus);

                if ($transactionStartedHere) {
                    $this->db->commit();
                    $this->logger->debug('Committed transaction in PoolCoordinator (deferred pool, awaiting confirmation)');
                } else {
                    $this->logger->debug('Leaving transaction open for caller to commit (deferred pool)');
                }

                return [
                    'success' => true,
                    'pool_id' => $pool['id'],
                    'reference' => $pool['reference'],
                    'status' => $deferredStatus,
                    'total_amount' => $pool['amount'],
                    'currency' => $pool['currency'] ?? 'BWP',
                    'source_count' => count($contributions),
                    'destination_result' => $destinationResult,
                    'message' => $deferredStatus === PoolStatus::PENDING_CASHOUT->value
                        ? 'Cashout code generated. Sources will be debited once the code is redeemed.'
                        : 'Identity claim pending. Sources will be debited once the claim is confirmed.',
                ];
            }

            // 11. Transition to DESTINATION_COMPLETED (ordinary deposit path only)
            $this->stateMachine->transition($pool, PoolStatus::DESTINATION_COMPLETED->value);

            $completion = $this->completeDeferredPool($pool, $holds, $contributions);

            if ($transactionStartedHere) {
                $this->db->commit();
                $this->logger->debug('Committed transaction in PoolCoordinator');
            } else {
                $this->logger->debug('Leaving transaction open for caller to commit');
            }

            return $this->buildResponse($pool, $contributions, $destinationResult, $completion['settlement'], $completion['invoices']);
            
        } catch (Exception $e) {
            // FIX: Only rollback if we started the transaction
            if ($transactionStartedHere && $this->db->inTransaction()) {
                $this->db->rollBack();
                $this->logger->debug('Rolled back transaction in PoolCoordinator');
            } else {
                $this->logger->debug('Not rolling back - caller owns the transaction');
            }
            
            $this->logger->error('Multi-source swap failed', ['error' => $e->getMessage()]);
            
            // Bug 2 fixed: Now releases holds properly with real institution calls
            $this->rollbackHolds($heldSources);
            
            $this->rollback($pool ?? null);
            throw new RuntimeException("Multi-source swap failed: " . $e->getMessage());
        }
    }

    private function completeDeferredPool(array $pool, array $holds, array $contributions): array
    {
        $this->stateMachine->transition($pool, PoolStatus::DEBITING->value);
        $debits = $this->debitSources($pool, $holds, $contributions);
        $this->logger->info('Sources debited', ['debits' => count($debits)]);

        $this->stateMachine->transition($pool, PoolStatus::SETTLING->value);
        $settlementResult = $this->settle($pool, $contributions);
        $this->logger->info('Settlement completed');

        $this->stateMachine->transition($pool, PoolStatus::INVOICING->value);
        $invoiceResult = $this->invoice($pool, $contributions);
        $this->logger->info('Invoicing completed');

        $this->stateMachine->transition($pool, PoolStatus::COMPLETED->value);
        $this->poolRepository->updateStatus($pool['id'], PoolStatus::COMPLETED->value);

        return ['debits' => $debits, 'settlement' => $settlementResult, 'invoices' => $invoiceResult];
    }

    private function createPool(array $payload): array
    {
        $poolId = $payload['pool_id'] ?? 'POOL_' . uniqid();
        
        try {
            if (isset($this->swapService->forexService)) {
                $forexService = $this->swapService->forexService;
                $rate = $forexService->getExchangeRate(
                    $payload['currency'] ?? 'BWP',
                    $payload['destination_currency'] ?? 'BWP',
                    'internal'
                );
                $this->forexRateSnapshot = [
                    'rate' => $rate,
                    'from' => $payload['currency'] ?? 'BWP',
                    'to' => $payload['destination_currency'] ?? 'BWP',
                    'applied' => true,
                    'timestamp' => time()
                ];
            } else {
                $this->forexRateSnapshot = [
                    'rate' => 1.0,
                    'from' => $payload['currency'] ?? 'BWP',
                    'to' => $payload['destination_currency'] ?? 'BWP',
                    'applied' => false,
                    'timestamp' => time()
                ];
                $this->logger->info('ForexService not available, using default rate 1.0');
            }
        } catch (Exception $e) {
            $this->logger->warning('Forex rate not available, using default', [
                'error' => $e->getMessage()
            ]);
            $this->forexRateSnapshot = [
                'rate' => 1.0,
                'from' => $payload['currency'] ?? 'BWP',
                'to' => $payload['destination_currency'] ?? 'BWP',
                'applied' => false,
                'timestamp' => time()
            ];
        }
        
        $isIdentityDestination = isset($payload['identity_type']) && !empty($payload['identity_value']);
        $deliveryMethod = strtoupper($payload['delivery_method'] ?? ($isIdentityDestination ? 'IDENTITY' : 'DEPOSIT'));

        $destinationIdentifier = $isIdentityDestination
            ? ['identifier' => null, 'type' => null]
            : $this->swapService->extractDestinationIdentifier($payload);
        $destinationAssetType = $isIdentityDestination
            ? null
            : $this->swapService->extractDestinationAssetType($payload);
        $destinationInstitution = $isIdentityDestination
            ? null
            : ($payload['to_institution'] ?? $payload['destination_institution'] ?? null);

        if (!$isIdentityDestination && empty($destinationInstitution)) {
            throw new RuntimeException("Multi-source swap requires either a destination institution or identity_type/identity_value");
        }

        $pool = [
            'id' => $poolId,
            'sources' => $payload['sources'] ?? [],
            'amount' => $payload['amount'] ?? 0,
            'currency' => $payload['currency'] ?? 'BWP',
            'destination_currency' => $payload['destination_currency'] ?? 'BWP',
            'status' => PoolStatus::CREATED->value,
            'source_institution' => $payload['from_institution'] ?? $payload['source_institution'] ?? null,
            'destination_institution' => $destinationInstitution,
            'destination_identifier' => $destinationIdentifier['identifier'] ?? null,
            'destination_identifier_type' => $destinationIdentifier['type'] ?? null,
            'destination_asset_type' => $destinationAssetType,
            'delivery_method' => $deliveryMethod,
            'identity_type' => $isIdentityDestination ? strtolower($payload['identity_type']) : null,
            'identity_value' => $isIdentityDestination ? $payload['identity_value'] : null,
            'beneficiary_phone' => $payload['beneficiary_phone'] ?? null,
            'user_id' => $payload['user_id'] ?? null,
            'reference' => $payload['reference'] ?? uniqid(),
            'forex_rate' => $this->forexRateSnapshot,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            // Persisted copy — top-level keys above are for THIS request's
            // in-memory flow only and are lost once this process ends.
            // A later confirmPoolCashout()/confirmPoolIdentityClaim() call
            // (a fresh HTTP request, possibly minutes/hours later) reloads
            // the pool from the DB and can only see what's in metadata.
            'metadata' => [
                'delivery_method' => $deliveryMethod,
                'identity_type' => $isIdentityDestination ? strtolower($payload['identity_type']) : null,
                'identity_value' => $isIdentityDestination ? $payload['identity_value'] : null,
                'beneficiary_phone' => $payload['beneficiary_phone'] ?? null,
                'destination_identifier_type' => $destinationIdentifier['type'] ?? null,
                'destination_asset_type' => $destinationAssetType,
                'user_id' => $payload['user_id'] ?? null,
            ],
        ];
        
        $this->poolRepository->saveFromArray($pool);
        
        return $pool;
    }

    /**
     * Reconstructs the in-memory $pool / $holds / $contributions shape
     * that execute() built originally, from persisted rows only. Used by
     * confirmPoolCashout() and confirmPoolIdentityClaim(), which run in a
     * separate request from the one that created the pool.
     */
    private function reloadPoolForConfirmation(string $poolId): array
    {
        $row = $this->poolRepository->findByIdAsArray($poolId);
        if (!$row) {
            throw new RuntimeException("Pool not found: {$poolId}");
        }

        $metadata = json_decode($row['metadata'] ?? '{}', true) ?: [];

        $pool = [
            'id' => $row['pool_id'],
            'reference' => $row['swap_reference'],
            'amount' => (float)$row['requested_amount'],
            'currency' => $row['currency'] ?? 'BWP',
            'status' => $row['status'],
            'destination_institution' => $row['destination_institution'],
            'destination_identifier' => $row['destination_identifier'],
            'destination_identifier_type' => $metadata['destination_identifier_type'] ?? null,
            'destination_asset_type' => $metadata['destination_asset_type'] ?? ($row['destination_type'] ?? null),
            'delivery_method' => $metadata['delivery_method'] ?? 'DEPOSIT',
            'identity_type' => $metadata['identity_type'] ?? null,
            'identity_value' => $metadata['identity_value'] ?? null,
            'beneficiary_phone' => $metadata['beneficiary_phone'] ?? null,
            'user_id' => $metadata['user_id'] ?? null,
        ];

        $contributionRows = $this->contributionRepository->getAllByPoolIdAsArray($poolId);
        if (empty($contributionRows)) {
            throw new RuntimeException("No contributions found for pool: {$poolId}");
        }

        $holds = [];
        $contributions = [];
        foreach ($contributionRows as $row) {
            if (empty($row['hold_reference'])) {
                throw new RuntimeException("Contribution {$row['id']} for pool {$poolId} has no hold_reference — cannot debit");
            }
            $contribution = [
                '_contribution_id' => (int)$row['id'],
                'institution' => $row['institution'],
                'asset_type' => $row['asset_type'] ?? 'ACCOUNT',
                'source_identifier' => $row['source_identifier'],
                'amount' => (float)$row['contribution_amount'],
                'currency' => $row['currency'] ?? $pool['currency'],
            ];
            $contributions[] = $contribution;
            $holds[] = [
                'institution' => $row['institution'],
                'hold_reference' => $row['hold_reference'],
                'amount' => (float)$row['contribution_amount'],
                'source_payload' => $contribution,
            ];
        }

        return [$pool, $holds, $contributions];
    }

    /**
     * Called once the client has redeemed the cashout code at an ATM/agent.
     * Confirms with the destination bank, then debits every source hold
     * and completes the pool. Mirrors SwapService::confirmCashout()'s
     * two-phase shape, but for a pool of N source holds instead of one.
     */
    public function confirmPoolCashout(string $poolId, array $confirmationPayload = []): array
    {
        [$pool, $holds, $contributions] = $this->reloadPoolForConfirmation($poolId);

        if ($pool['status'] !== PoolStatus::PENDING_CASHOUT->value) {
            throw new RuntimeException(
                "Pool {$poolId} is not awaiting cashout confirmation (status: {$pool['status']})"
            );
        }

        $transactionStartedHere = !$this->db->inTransaction();
        if ($transactionStartedHere) {
            $this->db->beginTransaction();
        }

        try {
            $destinationInstitution = $pool['destination_institution'];
            $adapter = $this->swapService->getAdapterFactory()->getAdapter($destinationInstitution);

            $confirmResult = $adapter->confirmCashout(array_merge([
                'reference' => $pool['reference'],
                'pool_id' => $pool['id'],
                'to_institution' => $destinationInstitution,
                'destination_institution' => $destinationInstitution,
                'action' => 'CONFIRM_CASHOUT',
            ], $confirmationPayload), [
                'swap_reference' => $pool['reference'],
                'destination_institution' => $destinationInstitution,
            ]);

            if (!($confirmResult['confirmed'] ?? false)) {
                throw new RuntimeException(
                    "Cashout not confirmed by destination: " . ($confirmResult['message'] ?? 'Unknown error')
                );
            }

            $this->stateMachine->transition($pool, PoolStatus::DESTINATION_COMPLETED->value);
            $completion = $this->completeDeferredPool($pool, $holds, $contributions);

            if ($transactionStartedHere) {
                $this->db->commit();
            }

            return $this->buildResponse($pool, $contributions, $confirmResult, $completion['settlement'], $completion['invoices']);

        } catch (\Throwable $e) {
            if ($transactionStartedHere && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('confirmPoolCashout failed', ['pool_id' => $poolId, 'error' => $e->getMessage()]);
            throw new RuntimeException("Cashout confirmation failed: " . $e->getMessage());
        }
    }

    /**
     * Called once the recipient (or agent, for document-based identity
     * types) has finalized their identity claim via SwapService's normal
     * single-hold flow. Debits every pooled source hold and completes
     * the pool. The recipient's chosen destination_type/institution for
     * THIS claim is separate from the pool's own destination fields (the
     * pool never had one — see executeIdentityDestination()).
     */
    public function confirmPoolIdentityClaim(string $poolId): array
    {
        [$pool, $holds, $contributions] = $this->reloadPoolForConfirmation($poolId);

        if ($pool['status'] !== PoolStatus::PENDING_IDENTITY_CLAIM->value) {
            throw new RuntimeException(
                "Pool {$poolId} is not awaiting identity claim confirmation (status: {$pool['status']})"
            );
        }

        $transactionStartedHere = !$this->db->inTransaction();
        if ($transactionStartedHere) {
            $this->db->beginTransaction();
        }

        try {
            $this->stateMachine->transition($pool, PoolStatus::DESTINATION_COMPLETED->value);
            $completion = $this->completeDeferredPool($pool, $holds, $contributions);

            if ($transactionStartedHere) {
                $this->db->commit();
            }

            return $this->buildResponse($pool, $contributions, ['success' => true], $completion['settlement'], $completion['invoices']);

        } catch (\Throwable $e) {
            if ($transactionStartedHere && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('confirmPoolIdentityClaim failed', ['pool_id' => $poolId, 'error' => $e->getMessage()]);
            throw new RuntimeException("Identity claim confirmation failed: " . $e->getMessage());
        }
    }
    
    private function persistContributions(array $pool, array $contributions): array
    {
        $persisted = [];
        foreach ($contributions as $index => $contribution) {
            $source = $contribution['source'] ?? [];
            $identifier = $source['source_identifier'] ?? 
                          $source['identifier'] ?? 
                          $source['account_id'] ?? 
                          $contribution['source_identifier'] ?? 
                          $contribution['identifier'] ?? 
                          $contribution['account_id'] ?? 
                          '';
            $identifierType = $source['source_identifier_type'] ?? 
                              $source['identifier_type'] ?? 
                              $contribution['source_identifier_type'] ?? 
                              $contribution['identifier_type'] ?? 
                              'auto';
            $institution = $source['institution'] ?? $contribution['institution'] ?? '';
            $assetType = $contribution['asset_type'] ?? $source['asset_type'] ?? 'ACCOUNT';
            $amount = (float)($contribution['actual_amount'] ?? $contribution['amount'] ?? 0);
            $requestedAmount = (float)($contribution['requested_amount'] ?? $contribution['amount'] ?? 0);
            $currency = $contribution['currency'] ?? $pool['currency'] ?? 'BWP';
            
            $this->logger->debug('Persisting contribution', [
                'index' => $index,
                'institution' => $institution,
                'identifier' => $identifier,
                'identifier_type' => $identifierType,
                'amount' => $amount,
                'asset_type' => $assetType
            ]);
            
            $model = new PoolContribution(
                $pool['id'],
                $pool['reference'] . '-' . str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT),
                $index + 1,
                $institution,
                $assetType,
                $identifier,
                $identifierType,
                $requestedAmount,
                $amount,
                $currency
            );
            
            // FIX: Wrap save in savepoint to prevent transaction poisoning
            $saved = $this->runInSavepoint(function() use ($model) {
                return $this->contributionRepository->save($model);
            }, 'sp_persist_contribution_' . $index);
            
            $contribution['_contribution_id'] = $saved->getId();
            $contribution['_sub_reference'] = $pool['reference'] . '-' . str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT);
            $contribution['source_identifier'] = $identifier;
            $contribution['source_identifier_type'] = $identifierType;
            $contribution['institution'] = $institution;
            $contribution['asset_type'] = $assetType;
            $contribution['amount'] = $amount;
            
            $persisted[] = $contribution;
        }
        return $persisted;
    }

    private function calculateContributions(array $pool, array $payload): array
    {
        $sourcesWithBalances = [];
        foreach ($pool['sources'] as $source) {
            $balance = $this->swapService->getSourceAvailableBalance($source);
            $sourcesWithBalances[] = array_merge($source, ['available_balance' => $balance]);
        }

        return $this->contributionCalculator->calculateContributions(
            $pool['amount'],
            $sourcesWithBalances,
            $payload['contribution_strategy'] ?? 'RATIO',
            $payload['user_amounts'] ?? null,
            $payload['priority_order'] ?? null
        );
    }

    private function verifySources(array $contributions, array $payload): array
    {
        $verifications = [];
        
        foreach ($contributions as $index => $contribution) {
            $institution = $contribution['institution'];
            $amount = $contribution['amount'];

            $this->swapService->assertCanBeSourcePublic($institution);
            
            $sourceIdentifier = $contribution['source_identifier'] ?? 
                               $contribution['identifier'] ?? 
                               $contribution['account_id'] ?? 
                               null;
            
            $sourceIdentifierType = $contribution['source_identifier_type'] ?? 
                                    $contribution['identifier_type'] ?? 
                                    'auto';
            
            // FIX: Get the original source data to include certificate and signature
            $originalSource = null;
            foreach ($payload['sources'] ?? [] as $source) {
                $sourceId = $source['source_identifier'] ?? $source['identifier'] ?? $source['account_id'] ?? null;
                if ($sourceId === $sourceIdentifier) {
                    $originalSource = $source;
                    break;
                }
            }
            
            // If not found by identifier, try matching by institution
            if (!$originalSource) {
                foreach ($payload['sources'] ?? [] as $source) {
                    if (($source['source_type'] ?? '') === $institution || 
                        ($source['institution'] ?? '') === $institution) {
                        $originalSource = $source;
                        break;
                    }
                }
            }
            
            $verifyPayload = [
                'action' => 'VERIFY_ASSET',
                'reference' => $payload['reference'] ?? uniqid(),
                'asset_type' => $contribution['asset_type'] ?? 'ACCOUNT',
                'amount' => $amount,
                'currency' => $contribution['currency'] ?? 'BWP',
                'institution' => $institution,
                'timestamp' => time(),
                'swap_type' => 'MULTI_SOURCE',
                'requester' => 'VOUCHMORPH',
                'from_institution' => $institution,
                'source_institution' => $institution,
                'source_identifier' => $sourceIdentifier,
                'source_identifier_type' => $sourceIdentifierType,
            ];
            
            // FIX: Include certificate and signature from original source if available
            if ($originalSource) {
                if (isset($originalSource['certificate'])) {
                    $verifyPayload['certificate'] = $originalSource['certificate'];
                }
                if (isset($originalSource['signature'])) {
                    $verifyPayload['signature'] = $originalSource['signature'];
                }
                // Also pass any other relevant verification data
                if (isset($originalSource['verification_data'])) {
                    $verifyPayload['verification_data'] = $originalSource['verification_data'];
                }
                
                $this->logger->debug('Including certificate/signature for verification', [
                    'institution' => $institution,
                    'has_certificate' => isset($originalSource['certificate']),
                    'has_signature' => isset($originalSource['signature'])
                ]);
            } else {
                $this->logger->warning('No original source found for verification', [
                    'institution' => $institution,
                    'source_identifier' => $sourceIdentifier
                ]);
            }
            
            $result = $this->swapService->verifyAssetSigned($verifyPayload, $institution);
            
            if (!($result['verified'] ?? false)) {
                throw new RuntimeException("Verification failed for source: {$institution} - " . ($result['message'] ?? 'Unknown error'));
            }
            
            $verifications[] = [
                'index' => $index,
                'institution' => $institution,
                'verified' => true,
                'asset_id' => $result['asset_id'] ?? null,
                'balance' => $result['balance'] ?? 0,
                'payload' => $result['original_payload'] ?? $verifyPayload ?? null,
            ];
            
            // FIX: Wrap updateStatus in savepoint to prevent transaction poisoning
            if (isset($contribution['_contribution_id'])) {
                $this->runInSavepoint(function() use ($contribution) {
                    $this->contributionRepository->updateStatus(
                        $contribution['_contribution_id'],
                        ContributionStatus::VERIFIED
                    );
                }, 'sp_verify_status_' . $index);
            }
        }
        
        return $verifications;
    }

   private function placeHolds(array $pool, array $contributions, array $verifications, ?array &$heldSources = null): array
{
    $holds = [];
    $heldSources = [];
    
    foreach ($contributions as $index => $contribution) {
        $institution = $contribution['institution'];
        $amount = $contribution['amount'];
        
        $sourceIdentifier = $contribution['source_identifier'] ?? 
                           $contribution['identifier'] ?? 
                           $contribution['account_id'] ?? 
                           null;
        
        $sourceIdentifierType = $contribution['source_identifier_type'] ?? 
                                $contribution['identifier_type'] ?? 
                                'auto';
        
        // FIX: Get original source data for certificate/signature
        $originalSource = null;
        foreach ($pool['sources'] ?? [] as $source) {
            $sourceId = $source['source_identifier'] ?? $source['identifier'] ?? $source['account_id'] ?? null;
            if ($sourceId === $sourceIdentifier) {
                $originalSource = $source;
                break;
            }
        }
        
        // If not found by identifier, try matching by institution
        if (!$originalSource) {
            foreach ($pool['sources'] ?? [] as $source) {
                if (($source['source_type'] ?? '') === $institution || 
                    ($source['institution'] ?? '') === $institution) {
                    $originalSource = $source;
                    break;
                }
            }
        }
        
        // FIX: Use unique sub_reference per source, not shared pool reference
        $uniqueReference = $contribution['_sub_reference'] ?? $pool['reference'] . '_' . $index;
        
        $holdPayload = [
            'action' => 'PLACE_HOLD',
            'reference' => $uniqueReference,  // <-- FIXED: unique per source
            'asset_type' => $contribution['asset_type'] ?? 'ACCOUNT',
            'amount' => $amount,
            'currency' => $contribution['currency'] ?? 'BWP',
            'hold_reason' => 'MULTI_SOURCE_SWAP',
            'expiry' => date('Y-m-d H:i:s', strtotime('+1 hour')),
            'timestamp' => time(),
            'from_institution' => $institution,
            'source_institution' => $institution,
            'source_identifier' => $sourceIdentifier,
            'source_identifier_type' => $sourceIdentifierType,
            'user_id' => $pool['user_id'] ?? 0,
            'destination_institution' => $pool['destination_institution'] ?? null,
        ];
        
        // FIX: Include certificate and signature from original source
        if ($originalSource) {
            if (isset($originalSource['certificate'])) {
                $holdPayload['certificate'] = $originalSource['certificate'];
            }
            if (isset($originalSource['signature'])) {
                $holdPayload['signature'] = $originalSource['signature'];
            }
            
            $this->logger->debug('Including certificate/signature for hold', [
                'institution' => $institution,
                'has_certificate' => isset($originalSource['certificate']),
                'has_signature' => isset($originalSource['signature'])
            ]);
        } else {
            $this->logger->warning('No original source found for hold', [
                'institution' => $institution,
                'source_identifier' => $sourceIdentifier
            ]);
        }
        
        $verificationResult = $verifications[$index] ?? [];
        $result = $this->swapService->placeHoldSigned($holdPayload, $institution, $verificationResult);
        
        if (!$result['hold_placed']) {
            $this->rollbackHolds($heldSources);
            throw new RuntimeException("Hold failed for source: {$institution} - " . ($result['message'] ?? 'Unknown error'));
        }
        
        $holdData = [
            'index' => $index,
            'institution' => $institution,
            'hold_id' => $result['hold_id'] ?? null,
            'hold_reference' => $result['hold_reference'] ?? null,
            'amount' => $amount,
            'signature' => $result['signature'] ?? null,
            'certificate' => $result['certificate'] ?? null,
            'original_payload' => $result['original_payload'] ?? $holdPayload,   
            'source_payload' => $contribution
        ];
        
        // FIX: Wrap DB updates in savepoint to prevent transaction poisoning
        if (isset($contribution['_contribution_id']) && !empty($holdData['hold_reference'])) {
            $this->runInSavepoint(function() use ($contribution, $holdData) {
                $this->contributionRepository->updateHoldReference(
                    $contribution['_contribution_id'],
                    $holdData['hold_reference']
                );
                $this->contributionRepository->updateStatus(
                    $contribution['_contribution_id'],
                    ContributionStatus::HELD
                );
            }, 'sp_hold_update_' . $index);
        }
        
        $holds[] = $holdData;
        $heldSources[] = $holdData;
    }
    
    return $holds;
}

    private function rollbackHolds(array $heldSources): void
    {
        if (empty($heldSources)) {
            return;
        }
        
        $this->logger->warning('Rolling back holds', ['count' => count($heldSources)]);
        
        foreach ($heldSources as $held) {
            try {
                $releaseResult = $this->swapService->releaseHold(
                    $held['source_payload'] ?? [],
                    $held['institution'],
                    isset($held['hold_id']) ? (string)$held['hold_id'] : null,
                    $held['hold_reference'] ?? null
                );
                
                $this->logger->info('Released real hold', [
                    'institution' => $held['institution'],
                    'hold_id' => $held['hold_id'] ?? 'unknown',
                    'success' => $releaseResult['success'] ?? false
                ]);
                
                $this->releaseLocalHold(isset($held['hold_id']) ? (string)$held['hold_id'] : null);
                
                $contribution = $held['source_payload'] ?? null;
                if ($contribution && isset($contribution['_contribution_id'])) {
                    try {
                        $this->runInSavepoint(function() use ($contribution) {
                            $this->contributionRepository->updateStatus(
                                $contribution['_contribution_id'],
                                ContributionStatus::FAILED
                            );
                        }, 'sp_rollback_status_' . ($contribution['_contribution_id'] ?? 'unknown'));
                    } catch (Exception $e) {
                        $this->logger->warning('Failed to update contribution rollback status', [
                            'contribution_id' => $contribution['_contribution_id'],
                            'error' => $e->getMessage()
                        ]);
                    }
                }
                
            } catch (Exception $e) {
                $this->logger->error('Failed to release hold', [
                    'institution' => $held['institution'],
                    'hold_id' => $held['hold_id'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    private function releaseLocalHold(?string $holdId): void
    {
        if ($holdId) {
            try {
                $stmt = $this->db->prepare("
                    UPDATE hold_transactions 
                    SET status = 'RELEASED', 
                        released_at = NOW(),
                        updated_at = NOW()
                    WHERE hold_id = ? AND status = 'HELD'
                ");
                $stmt->execute([$holdId]);
            } catch (Exception $e) {
                $this->logger->error('Failed to release local hold', [
                    'hold_id' => $holdId,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    private function executeDestination(array $pool, array $contributions, array $masterSignature, array $holds): array
    {
        if (!empty($pool['identity_type']) && !empty($pool['identity_value'])) {
            $result = $this->executeIdentityDestination($pool, $contributions, $masterSignature, $holds);
            $result['_defer_debit'] = $result['success'] ?? false;
            $result['_defer_status'] = PoolStatus::PENDING_IDENTITY_CLAIM->value;
            return $result;
        }

        $deliveryMethod = strtoupper($pool['delivery_method'] ?? $pool['destination_asset_type'] ?? 'DEPOSIT');
        if (in_array($deliveryMethod, ['CASHOUT', 'ATM', 'AGENT', 'VOUCHER'], true)) {
            $result = $this->executeCashoutDestination($pool, $contributions, $masterSignature, $holds);
            $result['_defer_debit'] = $result['success'] ?? false;
            $result['_defer_status'] = PoolStatus::PENDING_CASHOUT->value;
            return $result;
        }

        $destinationInstitution = $pool['destination_institution'];
        $totalAmount = $pool['amount'];
        $currency = $pool['currency'] ?? 'BWP';

        $destinationPayload = [
            'destination_identifier' => $pool['destination_identifier'] ?? null,
            'destination_identifier_type' => $pool['destination_identifier_type'] ?? 'account',
            'destination_asset_type' => $pool['destination_asset_type'] ?? 'WALLET',
            'destination_institution' => $destinationInstitution,
            'to_institution' => $destinationInstitution,
            'amount' => $totalAmount,
            'currency' => $currency,
            'reference' => $pool['reference'] ?? uniqid(),
            'master_signature' => $masterSignature['signature'] ?? null,
            'master_certificate' => $masterSignature['certificate'] ?? null,
            'pool_id' => $pool['id'],
            'sources' => $contributions
        ];

        return $this->swapService->creditDestination($destinationPayload, $destinationInstitution);
    }

    /**
     * CASHOUT destination: generates an ATM/agent code at the destination
     * institution. Does NOT credit/deposit anything and does NOT debit any
     * source — the client hasn't redeemed the code yet. Mirrors
     * SwapService::generateCashoutToken()'s single-source shape, sourced
     * from the pool's aggregate amount instead of a single hold.
     */
    private function executeCashoutDestination(array $pool, array $contributions, array $masterSignature, array $holds): array
    {
        $destinationInstitution = $pool['destination_institution'];
        if (empty($destinationInstitution)) {
            return ['success' => false, 'message' => 'Cashout requires a destination institution'];
        }

        $tokenPayload = [
            'reference' => $pool['reference'] ?? uniqid(),
            'amount' => $pool['amount'],
            'currency' => $pool['currency'] ?? 'BWP',
            'action' => 'GENERATE_TOKEN',
            'to_institution' => $destinationInstitution,
            'destination_institution' => $destinationInstitution,
            'destination_identifier' => $pool['destination_identifier'] ?? null,
            'destination_identifier_type' => $pool['destination_identifier_type'] ?? null,
            'beneficiary_phone' => $pool['beneficiary_phone'] ?? null,
            'master_signature' => $masterSignature['signature'] ?? null,
            'master_certificate' => $masterSignature['certificate'] ?? null,
            'pool_id' => $pool['id'],
            'source_type' => 'VIRTUAL_POOL',
            'from_institution' => 'VM_POOL',
            'source_institution' => 'VM_POOL',
        ];

        $adapter = $this->swapService->getAdapterFactory()->getAdapter($destinationInstitution);
        $result = $adapter->generateCashoutToken($tokenPayload, [
            'swap_reference' => $pool['reference'] ?? null,
            'destination_institution' => $destinationInstitution,
            'pool_id' => $pool['id'],
        ]);

        if (!($result['success'] ?? false)) {
            return ['success' => false, 'message' => $result['message'] ?? 'Cashout code generation failed'];
        }

        $swapCode = $result['voucher_number'] ?? $result['swap_code'] ?? null;
        $pinCode = $result['atm_pin'] ?? '';
        $expiresAt = $result['expires_at'] ?? date('Y-m-d H:i:s', strtotime('+24 hours'));

        if ($swapCode) {
            try {
                $this->swapService->storePoolCashoutAuthorization(
                    $pool['id'],
                    $pool['reference'],
                    $destinationInstitution,
                    $pool['amount'],
                    $swapCode,
                    $pinCode,
                    $expiresAt,
                    $pool['beneficiary_phone'] ?? null
                );
            } catch (\Throwable $e) {
                $this->logger->error('Failed to store pool cashout authorization — ATM callback will not find this pool', [
                    'pool_id' => $pool['id'],
                    'error' => $e->getMessage(),
                ]);
                return ['success' => false, 'message' => 'Cashout code generated but authorization record failed: ' . $e->getMessage()];
            }
        } else {
            $this->logger->warning('Cashout token generated with no swap_code/voucher_number — cannot record authorization', ['pool_id' => $pool['id']]);
        }

        return [
            'success' => true,
            'atm_pin' => $pinCode,
            'voucher_number' => $swapCode,
            'expires_at' => $expiresAt,
            'message' => $result['message'] ?? 'Cashout code generated. Debit deferred until redemption.',
        ];
    }

    /**
     * Identity destinations have no institution to credit — the pooled
     * amount is placed into an identity_swap_holds record for the
     * recipient to claim later, exactly like a single-source identity
     * swap.
     *
     * LIMITATION: initiateSwapToIdentity()'s _skip_hold path only tracks
     * ONE hold_reference/hold_id per identity record. For a multi-source
     * pool, N separate per-source holds were placed by placeHolds() above
     * (one per contributing institution) — this anchors the identity
     * claim to the LAST hold placed only. All N holds get correctly
     * debited later by debitSources(), but only the last hold's
     * institution/reference is linked in identity_swap_holds. This is
     * sufficient for the claim/redemption flow to work end-to-end, but
     * NOT sufficient for per-source reconciliation against the identity
     * record — that would require identity_swap_holds (or a join table)
     * to support multiple hold references per claim, which is a schema
     * change, not a code fix. Flagging rather than silently masking it.
     */
    private function executeIdentityDestination(array $pool, array $contributions, array $masterSignature, array $holds): array
    {
        if (empty($holds)) {
            throw new RuntimeException("No holds available to attach identity swap to");
        }

        $anchorHold = end($holds);

        if (empty($anchorHold['hold_reference'])) {
            throw new RuntimeException("Anchor hold has no hold_reference to attach identity swap to");
        }

        $identityPayload = [
            'swap_type' => 'IDENTITY',
            'reference' => $pool['reference'] ?? uniqid(),
            'amount' => $pool['amount'],
            'currency' => $pool['currency'] ?? 'BWP',
            'identity_type' => $pool['identity_type'],
            'identity_value' => $pool['identity_value'],
            'beneficiary_phone' => $pool['beneficiary_phone'] ?? null,
            'notification_phone' => $pool['beneficiary_phone'] ?? null,
            'from_institution' => $anchorHold['institution'],
            'source_institution' => $anchorHold['institution'],
            'source_identifier' => $anchorHold['source_payload']['source_identifier'] ?? null,
            'asset_type' => $anchorHold['source_payload']['asset_type'] ?? 'ACCOUNT',
            'user_id' => $pool['user_id'] ?? null,
            '_skip_hold' => true,
            'hold_reference' => $anchorHold['hold_reference'],
        ];

        $result = $this->swapService->initiateSwapToIdentity($identityPayload);

        return [
            'success' => ($result['status'] ?? null) === 'pending_identity_confirmation',
            'status' => $result['status'] ?? 'unknown',
            'swap_reference' => $result['swap_reference'] ?? null,
            'hold_reference' => $result['hold_reference'] ?? null,
            'message' => $result['message'] ?? null,
            'result' => $result,
        ];
    }

    private function debitSources(array $pool, array $holds, array $contributions): array
    {
        $debits = [];
        
        foreach ($holds as $hold) {
            $institution = $hold['institution'];
            $holdReference = $hold['hold_reference'];
            $amount = $hold['amount'];
            
            $debitPayload = [
                'hold_reference' => $holdReference,
                'amount' => $amount,
                'reason' => 'Multi-source swap completed',
                'reference' => $pool['reference'] ?? uniqid(),
                'from_institution' => $institution,
                'source_institution' => $institution
            ];
            
            $result = $this->swapService->debitSource($debitPayload, $institution);
            
            if (!$result['debited']) {
                throw new RuntimeException("Debit failed for source: {$institution} - " . ($result['message'] ?? 'Unknown error'));
            }
            
            $debits[] = [
                'institution' => $institution,
                'debited' => true,
                'transaction_reference' => $result['transaction_reference'] ?? null
            ];
            
            $matchingContribution = $hold['source_payload'] ?? null;
            if ($matchingContribution && isset($matchingContribution['_contribution_id']) && !empty($result['transaction_reference'])) {
                // FIX: Wrap DB updates in savepoint to prevent transaction poisoning
                $this->runInSavepoint(function() use ($matchingContribution, $result) {
                    $this->contributionRepository->updateDebitReference(
                        $matchingContribution['_contribution_id'],
                        $result['transaction_reference']
                    );
                    $this->contributionRepository->updateStatus(
                        $matchingContribution['_contribution_id'],
                        ContributionStatus::DEBITED
                    );
                }, 'sp_debit_update_' . ($matchingContribution['_contribution_id'] ?? 'unknown'));
            }
        }
        
        return $debits;
    }

    private function settle(array $pool, array $contributions): array
    {
        $sourceInstitutions = array_column($contributions, 'institution');
        $totalAmount = $pool['amount'];
        $currency = $pool['currency'] ?? 'BWP';

        $destinationInstitution = $pool['destination_institution']
            ?? 'IDENTITY_CLAIM_' . strtoupper($pool['identity_type'] ?? 'UNKNOWN');

        return $this->settlement->updateNetPosition(
            $pool['reference'] ?? uniqid(),
            implode(',', $sourceInstitutions),
            $destinationInstitution,
            $totalAmount,
            'MULTI_SOURCE_COMPLETED',
            $currency
        );
    }

    private function invoice(array $pool, array $contributions): array
    {
        // Derive delivery mode from how the pool was destined
        $deliveryMode = match (true) {
            isset($pool['identity_type'], $pool['identity_value']) => 'deposit', // identity swaps settle as deposits internally
            strtoupper($pool['destination_asset_type'] ?? '') === 'CASHOUT' => 'cashout',
            default => 'deposit',
        };
        // If the caller flagged this pool as a cashout explicitly, honor that instead
        if (!empty($pool['delivery_mode'])) {
            $deliveryMode = $pool['delivery_mode'];
        }

        $feeResult = $this->feeCalculator->calculateFees(
            count($contributions),
            $deliveryMode,
            $pool['amount'] ?? 0,
            $pool['currency'] ?? 'BWP',
            $pool['destination_currency'] ?? $pool['currency'] ?? 'BWP'
        );

        $invoiceResults = [];

        $platformShare = $feeResult['split_distribution']['platform_share'] ?? 0;
        if ($platformShare > 0) {
            $result = $this->settlement->invoiceFee(
                $pool['reference'] ?? uniqid(),
                'VOUCHMORPH',
                1,
                'PLATFORM_FEE',
                $platformShare,
                $feeResult['currency'] ?? $pool['currency'] ?? 'BWP'
            );
            $invoiceResults[] = $result;
        }

        // Invoice each source's individual share, using per_source_fees
        // (keyed by contribution index, matching $contributions' own indexing)
        foreach ($feeResult['per_source_fees'] ?? [] as $index => $amount) {
            if ($amount <= 0 || !isset($contributions[$index])) {
                continue;
            }
            $result = $this->settlement->invoiceFee(
                $pool['reference'] ?? uniqid(),
                $contributions[$index]['institution'],
                0,
                'SOURCE_FEE',
                $amount,
                $feeResult['currency'] ?? $pool['currency'] ?? 'BWP'
            );
            $invoiceResults[] = $result;
        }

        return $invoiceResults;
    }

    private function rollback(?array $pool): void
    {
        if ($pool && isset($pool['id'])) {
            try {
                $this->poolRepository->updateStatus($pool['id'], PoolStatus::FAILED->value);
                $this->logger->warning('Pool rolled back', ['pool_id' => $pool['id']]);
            } catch (Exception $e) {
                $this->logger->error('Rollback failed', ['error' => $e->getMessage()]);
            }
        }
    }

    private function buildResponse(array $pool, array $contributions, array $destinationResult, array $settlementResult, array $invoiceResult): array
    {
        return [
            'success' => true,
            'pool_id' => $pool['id'],
            'reference' => $pool['reference'],
            'status' => PoolStatus::COMPLETED->value,
            'total_amount' => $pool['amount'],
            'currency' => $pool['currency'] ?? 'BWP',
            'forex_rate_used' => $this->forexRateSnapshot,
            'source_count' => count($contributions),
            'destination_result' => $destinationResult,
            'settlement' => $settlementResult,
            'invoices' => $invoiceResult,
            'completed_at' => date('Y-m-d H:i:s')
        ];
    }

    public function getStatus(string $poolId): array
    {
        $pool = $this->poolRepository->findByIdAsArray($poolId);
        
        if (!$pool) {
            return [
                'success' => false,
                'message' => "Pool not found: {$poolId}"
            ];
        }
        
        return [
            'success' => true,
            'pool_id' => $pool['id'] ?? $poolId,
            'status' => $pool['status'] ?? 'UNKNOWN',
            'amount' => $pool['amount'] ?? 0,
            'currency' => $pool['currency'] ?? 'BWP',
            'created_at' => $pool['created_at'] ?? null,
            'updated_at' => $pool['updated_at'] ?? null
        ];
    }

    public function cancel(string $poolId, string $reason): array
    {
        $pool = $this->poolRepository->findByIdAsArray($poolId);
        
        if (!$pool) {
            return [
                'success' => false,
                'message' => "Pool not found: {$poolId}"
            ];
        }
        
        $this->poolRepository->updateStatus($poolId, PoolStatus::CANCELLED->value);
        
        return [
            'success' => true,
            'pool_id' => $poolId,
            'status' => PoolStatus::CANCELLED->value,
            'reason' => $reason
        ];
    }

    /**
     * Cron entry point for pools stuck in PENDING_IDENTITY_CLAIM past
     * the identity_swap_holds record's expiry (24h, same as single-source).
     * Deliberately does NOT call SwapService::cancelExpiredIdentitySwaps() —
     * that method only knows about the anchor hold recorded in
     * identity_swap_holds and would attempt to release it, but a pool has
     * N-1 OTHER source holds that method has no visibility into. This
     * releases all N holds itself and marks the identity_swap_holds row
     * expired directly.
     */
    public function cancelExpiredPoolIdentityClaims(): array
    {
        $results = ['total_expired' => 0, 'cancelled' => 0, 'errors' => 0, 'details' => []];

        $sql = "
            SELECT p.pool_id, p.swap_reference, h.hold_id AS anchor_hold_id, h.hold_expires_at
            FROM virtual_funding_pools p
            JOIN identity_swap_holds h ON h.swap_reference = p.swap_reference
            WHERE p.status = :pending_status
            AND h.status = 'pending'
            AND h.hold_expires_at < NOW()
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':pending_status' => PoolStatus::PENDING_IDENTITY_CLAIM->value]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $results['total_expired'] = count($rows);

        foreach ($rows as $row) {
            $poolId = $row['pool_id'];

            try {
                [$pool, $holds, $contributions] = $this->reloadPoolForConfirmation($poolId);

                foreach ($holds as $hold) {
                    try {
                        $this->swapService->releaseHold(
                            $hold['source_payload'] ?? [],
                            $hold['institution'],
                            null,
                            $hold['hold_reference'] ?? null
                        );
                    } catch (\Throwable $releaseError) {
                        $this->logger->error('Failed to release pool source hold on identity claim expiry', [
                            'pool_id' => $poolId,
                            'institution' => $hold['institution'],
                            'error' => $releaseError->getMessage(),
                        ]);
                    }
                }

                $stmt2 = $this->db->prepare("
                    UPDATE identity_swap_holds
                    SET status = 'expired', expired_at = NOW()
                    WHERE hold_id = :hold_id
                ");
                $stmt2->execute([':hold_id' => $row['anchor_hold_id']]);

                $this->poolRepository->updateStatus($poolId, PoolStatus::CANCELLED->value, [
                    'cancel_reason' => 'identity_claim_expired',
                ]);

                $results['cancelled']++;
                $results['details'][] = ['pool_id' => $poolId, 'status' => 'cancelled'];

            } catch (\Throwable $e) {
                $this->logger->error('cancelExpiredPoolIdentityClaims failed for pool', ['pool_id' => $poolId, 'error' => $e->getMessage()]);
                $results['errors']++;
                $results['details'][] = ['pool_id' => $poolId, 'status' => 'error', 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Executes a pool where every source's funds are ALREADY held
     * (VouchMorph Card hook flow), rather than the standard execute()
     * pipeline which discovers, verifies, and holds sources itself.
     *
     * Called by CardContributionSessionService once a card owner's
     * contribution session has reached full coverage of the destination
     * amount (status READY).
     *
     * @param array $payload Same shape as execute()'s $payload for the
     *   destination side (amount, currency, delivery_method /
     *   destination_institution / destination_identifier, OR
     *   identity_type + identity_value). No `sources` key needed —
     *   $preHeldSources replaces that entirely.
     * @param array $preHeldSources One entry per contributing hooked
     *   source: ['institution', 'asset_type', 'source_identifier',
     *   'source_identifier_type', 'amount' (the FINAL contribution amount
     *   to debit — not necessarily the full held amount), 'hold_reference'].
     */
    public function executeFromCardHook(array $payload, array $preHeldSources): array
    {
        $this->logger->info('PoolCoordinator executing from card hook (pre-held sources)', [
            'sources' => count($preHeldSources),
            'amount' => $payload['amount'] ?? 0
        ]);

        $transactionStartedHere = !$this->db->inTransaction();
        if ($transactionStartedHere) {
            $this->db->beginTransaction();
            $this->logger->debug('Started new transaction in PoolCoordinator (card hook)');
        } else {
            $this->logger->debug('Using existing transaction from caller (card hook)');
        }

        $pool = null;

        try {
            // 1. Create pool record (forex snapshot, destination fields) —
            // identical to the normal path.
            $pool = $this->createPool($payload);
            $this->logger->info('Pool created (card hook)', ['pool_id' => $pool['id']]);

            // 2. Shape the pre-held sources exactly as persistContributions()
            // expects, and drop anything with a non-positive amount (same
            // rule execute() applies to normally-discovered contributions).
            $rawContributions = array_values(array_filter(array_map(function ($s) use ($pool) {
                return [
                    'institution' => $s['institution'],
                    'asset_type' => $s['asset_type'] ?? 'ACCOUNT',
                    'source_identifier' => $s['source_identifier'],
                    'source_identifier_type' => $s['source_identifier_type'] ?? 'auto',
                    'amount' => (float)($s['amount'] ?? 0),
                    'actual_amount' => (float)($s['amount'] ?? 0),
                    'requested_amount' => (float)($s['amount'] ?? 0),
                    'currency' => $pool['currency'] ?? 'BWP',
                    '_hold_reference' => $s['hold_reference'] ?? null,
                ];
            }, $preHeldSources), fn($c) => $c['amount'] > 0));

            if (empty($rawContributions)) {
                throw new RuntimeException("No positive-amount pre-held contributions to execute");
            }

            foreach ($rawContributions as $c) {
                if (empty($c['_hold_reference'])) {
                    throw new RuntimeException("Missing hold_reference for pre-held source: {$c['institution']}");
                }
            }

            // 3. Persist contributions the normal way (pool_contributions
            // rows, sub-references, etc.) so downstream reporting/reconciliation
            // sees a card-hook-funded pool exactly like any other pool.
            $contributions = $this->persistContributions($pool, $rawContributions);

            // 4. Build the $holds shape placeHolds() would normally produce,
            // and mark each contribution HELD directly — skip verifySources()
            // and placeHolds() entirely, since CardService::hookSourcesToCard()
            // already did real verify+hold for these at hook time.
            $holds = [];
            foreach ($contributions as $index => $contribution) {
                $holdRef = $rawContributions[$index]['_hold_reference'];

                if (isset($contribution['_contribution_id'])) {
                    // FIX: Wrap DB updates in savepoint to prevent transaction poisoning
                    $this->runInSavepoint(function() use ($contribution, $holdRef) {
                        $this->contributionRepository->updateHoldReference(
                            $contribution['_contribution_id'],
                            $holdRef
                        );
                        $this->contributionRepository->updateStatus(
                            $contribution['_contribution_id'],
                            ContributionStatus::HELD
                        );
                    }, 'sp_card_hook_hold_' . $index);
                }

                $holds[] = [
                    'index' => $index,
                    'institution' => $contribution['institution'],
                    'hold_reference' => $holdRef,
                    'amount' => $contribution['amount'],
                    'source_payload' => $contribution,
                ];
            }

            // 5. Jump straight to HOLDING -> FUNDED (holds already real).
            $this->stateMachine->transition($pool, PoolStatus::HOLDING->value);
            $this->stateMachine->transition($pool, PoolStatus::FUNDED->value);

            $verifications = array_map(fn($h) => [
                'index' => $h['index'],
                'institution' => $h['institution'],
                'verified' => true,
            ], $holds);

            // 6. Master signature, destination execution — identical to
            // the normal path from here on.
            $masterSignature = $this->aggregateSigner->signAggregate($pool, $holds, $verifications);
            $this->logger->info('Master signature generated (card hook)');

            $this->stateMachine->transition($pool, PoolStatus::DESTINATION_PENDING->value);
            $destinationResult = $this->executeDestination($pool, $contributions, $masterSignature, $holds);
            $this->logger->info('Destination executed (card hook)', ['success' => $destinationResult['success'] ?? false]);

            if (!($destinationResult['success'] ?? false)) {
                throw new RuntimeException(
                    "Destination credit failed: " . ($destinationResult['message'] ?? 'Unknown error')
                );
            }

            $isDeferred = ($destinationResult['_defer_debit'] ?? false) === true;

            if ($isDeferred) {
                // CASHOUT or IDENTITY destination — same deferred-debit
                // handling as the normal execute() path.
                $deferredStatus = $destinationResult['_defer_status'] ?? PoolStatus::PENDING_CASHOUT->value;
                $this->stateMachine->transition($pool, $deferredStatus);
                $this->poolRepository->updateStatus($pool['id'], $deferredStatus);

                if ($transactionStartedHere) {
                    $this->db->commit();
                    $this->logger->debug('Committed transaction in PoolCoordinator (card hook, deferred)');
                }

                return [
                    'success' => true,
                    'pool_id' => $pool['id'],
                    'reference' => $pool['reference'],
                    'status' => $deferredStatus,
                    'total_amount' => $pool['amount'],
                    'currency' => $pool['currency'] ?? 'BWP',
                    'source_count' => count($contributions),
                    'destination_result' => $destinationResult,
                    'message' => $deferredStatus === PoolStatus::PENDING_CASHOUT->value
                        ? 'Cashout code generated. Sources will be debited once the code is redeemed.'
                        : 'Identity claim pending. Sources will be debited once the claim is confirmed.',
                ];
            }

            // 7. Ordinary deposit path — debit all sources, settle, invoice.
            $this->stateMachine->transition($pool, PoolStatus::DESTINATION_COMPLETED->value);
            $completion = $this->completeDeferredPool($pool, $holds, $contributions);

            if ($transactionStartedHere) {
                $this->db->commit();
                $this->logger->debug('Committed transaction in PoolCoordinator (card hook)');
            }

            return $this->buildResponse($pool, $contributions, $destinationResult, $completion['settlement'], $completion['invoices']);

        } catch (Exception $e) {
            if ($transactionStartedHere && $this->db->inTransaction()) {
                $this->db->rollBack();
                $this->logger->debug('Rolled back transaction in PoolCoordinator (card hook)');
            }

            $this->logger->error('Card hook pool execution failed', ['error' => $e->getMessage()]);

            // Deliberately NOT releasing the pre-held sources here. Those
            // holds belong to the card hook (card_pool_hook_sources), not
            // to this pool attempt — the same hook may be retried with a
            // corrected contribution session. Releasing on a pool-level
            // failure is CardContributionSessionService's decision (or the
            // hook's own natural expiry), not PoolCoordinator's.
            $this->rollback($pool ?? null);

            throw new RuntimeException("Card hook pool execution failed: " . $e->getMessage());
        }
    }
}
