<?php
declare(strict_types=1);

namespace Domain\Services;

use Domain\ValueObjects\ContributionStrategy;

/**
 * Contribution Calculator - VOUCHER AWARE with SMART Strategy
 * 
 * Strategies:
 * - EQUAL: Split equally among all sources (up to available balance)
 * - RATIO: Proportional to available balance (default, but skewed)
 * - PRIORITY: User-defined priority order
 * - USER_SPECIFIED: User specifies exact amounts
 * - SMART: Intelligent distribution - balances user preference with source limits
 */
class ContributionCalculator
{
    /**
     * Asset types that are FIXED (cannot be split)
     * These must be used in full
     */
    private array $fixedAssetTypes = [
        'VOUCHER',
        'CASHOUT-VOUCHER'
    ];
    
    /**
     * Asset types that are FLEXIBLE (can be split/partial)
     */
    private array $flexibleAssetTypes = [
        'ACCOUNT',
        'BANK-WALLET',
        'MNO-WALLET',
        'E-WALLET',
        'WALLET',
        'CARD',
        'ATM'
    ];

    /**
     * Calculate contributions based on selected strategy
     */
    public function calculateContributions(
        float $targetAmount,
        array $sources,
        string $strategy, // 'EQUAL', 'RATIO', 'SMART', 'PRIORITY', 'USER_SPECIFIED'
        ?array $userSpecified = null,
        ?array $priorityOrder = null
    ): array {
        // Separate fixed and flexible sources
        $fixedSources = [];
        $flexibleSources = [];
        $fixedTotal = 0;
        
        foreach ($sources as $source) {
            $assetType = strtoupper($source['asset_type'] ?? 'ACCOUNT');
            $isVoucher = $this->isVoucher($assetType);
            $isFixed = $this->isFixedAsset($assetType);
            
            // ATM special handling
            if ($assetType === 'ATM') {
                $isVoucherATM = $this->isVoucherATM($source);
                if ($isVoucherATM) {
                    $isFixed = true;
                    error_log("[ContributionCalculator] ATM code is a VOUCHER - FIXED");
                } else {
                    $isFixed = false;
                    error_log("[ContributionCalculator] ATM code is wallet-backed - FLEXIBLE");
                }
            }
            
            // CARD is flexible
            if ($assetType === 'CARD') {
                $isFixed = false;
                error_log("[ContributionCalculator] CARD is flexible");
            }
            
            if ($isFixed) {
                $fixedAmount = $source['available_balance'] ?? $source['amount'] ?? 0;
                $fixedSources[] = [
                    'source' => $source,
                    'asset_type' => $assetType,
                    'full_amount' => $fixedAmount,
                    'is_fixed' => true,
                    'is_voucher' => $isVoucher
                ];
                $fixedTotal += $fixedAmount;
                error_log("[ContributionCalculator] Fixed source: {$assetType} = {$fixedAmount}");
            } else {
                $flexibleSources[] = [
                    'source' => $source,
                    'asset_type' => $assetType,
                    'available_balance' => $source['available_balance'] ?? $source['amount'] ?? 0,
                    'is_fixed' => false,
                    'is_voucher' => $isVoucher
                ];
                error_log("[ContributionCalculator] Flexible source: {$assetType} = {$source['available_balance']}");
            }
        }
        
        // Check if fixed total exceeds target
        if ($fixedTotal > $targetAmount) {
            throw new \RuntimeException(
                sprintf("Voucher total (%.2f) exceeds target amount (%.2f). Please remove some vouchers.", 
                    $fixedTotal, $targetAmount)
            );
        }
        
        // Remaining amount to be covered by flexible sources
        $remainingAmount = $targetAmount - $fixedTotal;
        error_log("[ContributionCalculator] Voucher total: {$fixedTotal}, Remaining: {$remainingAmount}");
        
        // If no remaining amount, only fixed sources are used
        if ($remainingAmount <= 0.01) {
            $result = [];
            foreach ($fixedSources as $fs) {
                $result[] = [
                    'source' => $fs['source'],
                    'requested_amount' => $fs['full_amount'],
                    'actual_amount' => $fs['full_amount'],
                    'order' => count($result) + 1,
                    'is_fixed' => true,
                    'is_voucher' => $fs['is_voucher'],
                    'asset_type' => $fs['asset_type'],
                    'contribution_type' => 'FIXED_VOUCHER'
                ];
            }
            return $result;
        }
        
        // Calculate flexible contributions based on strategy
        $flexibleContributions = $this->calculateFlexibleContributions(
            $remainingAmount,
            $flexibleSources,
            $strategy,
            $userSpecified,
            $priorityOrder
        );
        
        // Combine fixed and flexible contributions
        $result = [];
        
        // Add fixed sources first (vouchers)
        foreach ($fixedSources as $fs) {
            $result[] = [
                'source' => $fs['source'],
                'requested_amount' => $fs['full_amount'],
                'actual_amount' => $fs['full_amount'],
                'order' => count($result) + 1,
                'is_fixed' => true,
                'is_voucher' => $fs['is_voucher'],
                'asset_type' => $fs['asset_type'],
                'contribution_type' => 'FIXED_VOUCHER'
            ];
        }
        
        // Add flexible contributions
        foreach ($flexibleContributions as $fc) {
            $result[] = [
                'source' => $fc['source'],
                'requested_amount' => $fc['requested_amount'],
                'actual_amount' => $fc['actual_amount'],
                'order' => count($result) + 1,
                'is_fixed' => false,
                'is_voucher' => false,
                'asset_type' => $fc['asset_type'],
                'contribution_type' => $fc['contribution_type'] ?? 'FLEXIBLE'
            ];
        }
        
        return $result;
    }
    
    /**
     * Calculate contributions for flexible sources only
     */
    private function calculateFlexibleContributions(
        float $targetAmount,
        array $flexibleSources,
        string $strategy,
        ?array $userSpecified = null,
        ?array $priorityOrder = null
    ): array {
        // If no flexible sources, throw error
        if (empty($flexibleSources)) {
            throw new \RuntimeException(
                sprintf("No flexible sources available to cover remaining amount (%.2f)", $targetAmount)
            );
        }
        
        // Check total available balance
        $totalFlexibleBalance = array_sum(array_column($flexibleSources, 'available_balance'));
        
        if ($totalFlexibleBalance < $targetAmount - 0.01) {
            throw new \RuntimeException(
                sprintf("Insufficient flexible balance (%.2f) for remaining amount (%.2f)", 
                    $totalFlexibleBalance, $targetAmount)
            );
        }
        
        // Apply strategy
        return match($strategy) {
            'EQUAL' => $this->calculateEqualFlexible($targetAmount, $flexibleSources),
            'RATIO' => $this->calculateRatioBasedFlexible($targetAmount, $flexibleSources),
            'SMART' => $this->calculateSmartFlexible($targetAmount, $flexibleSources),
            'PRIORITY' => $this->calculatePriorityFlexible($targetAmount, $flexibleSources, $priorityOrder),
            'USER_SPECIFIED' => $this->calculateUserSpecifiedFlexible($targetAmount, $flexibleSources, $userSpecified),
            default => $this->calculateSmartFlexible($targetAmount, $flexibleSources) // Default to SMART
        };
    }
    
    /**
     * EQUAL strategy - Split equally among all sources
     * This is more balanced than RATIO
     */
    private function calculateEqualFlexible(
        float $targetAmount,
        array $flexibleSources
    ): array {
        $sourceCount = count($flexibleSources);
        $equalShare = $targetAmount / $sourceCount;
        
        $contributions = [];
        $remaining = $targetAmount;
        
        // First pass: try to allocate equal shares
        foreach ($flexibleSources as $index => $source) {
            $available = $source['available_balance'];
            $allocated = min($equalShare, $available, $remaining);
            
            $contributions[] = [
                'source' => $source['source'],
                'asset_type' => $source['asset_type'],
                'requested_amount' => $allocated,
                'actual_amount' => $allocated,
                'contribution_type' => 'EQUAL',
                'order' => $index + 1
            ];
            
            $remaining -= $allocated;
        }
        
        // If there's remaining amount, distribute it to sources that can handle more
        if ($remaining > 0.01) {
            $this->distributeRemaining($contributions, $flexibleSources, $remaining);
        }
        
        return $contributions;
    }
    
    /**
     * SMART strategy - Balances user preference with source limits
     * 
     * This strategy:
     * 1. Tries to keep contributions proportional to user preference (default: 50/50)
     * 2. Respects source limits
     * 3. Fills remaining from available sources
     * 4. Prevents one source from being drained completely
     */
    private function calculateSmartFlexible(
        float $targetAmount,
        array $flexibleSources
    ): array {
        $sourceCount = count($flexibleSources);
        $contributions = [];
        $remaining = $targetAmount;
        
        // Step 1: Calculate ideal distribution (balanced, not skewed)
        // Use 50/50 split if 2 sources, 33/33/34 if 3 sources, etc.
        $idealShares = [];
        $totalBalance = array_sum(array_column($flexibleSources, 'available_balance'));
        
        // If all balances are healthy, use equal split
        $allHealthy = true;
        $minBalance = PHP_FLOAT_MAX;
        foreach ($flexibleSources as $source) {
            $minBalance = min($minBalance, $source['available_balance']);
            if ($source['available_balance'] < $targetAmount / $sourceCount) {
                $allHealthy = false;
            }
        }
        
        // If all sources can cover equal share, use equal distribution
        if ($allHealthy || $minBalance > $targetAmount / $sourceCount) {
            $idealShare = $targetAmount / $sourceCount;
            foreach ($flexibleSources as $index => $source) {
                $allocated = min($idealShare, $source['available_balance']);
                $contributions[] = [
                    'source' => $source['source'],
                    'asset_type' => $source['asset_type'],
                    'requested_amount' => $allocated,
                    'actual_amount' => $allocated,
                    'contribution_type' => 'SMART',
                    'order' => $index + 1
                ];
                $remaining -= $allocated;
            }
        } else {
            // Use a hybrid approach: proportional but with a floor
            $floorAmount = min($targetAmount * 0.1, $targetAmount / $sourceCount); // Minimum 10% each
            
            foreach ($flexibleSources as $index => $source) {
                $available = $source['available_balance'];
                $allocated = min($floorAmount, $available);
                
                $contributions[] = [
                    'source' => $source['source'],
                    'asset_type' => $source['asset_type'],
                    'requested_amount' => $allocated,
                    'actual_amount' => $allocated,
                    'contribution_type' => 'SMART_FLOOR',
                    'order' => $index + 1
                ];
                $remaining -= $allocated;
            }
        }
        
        // Step 2: Distribute remaining amount
        if ($remaining > 0.01) {
            $this->distributeRemaining($contributions, $flexibleSources, $remaining);
        }
        
        return $contributions;
    }
    
    /**
     * PRIORITY strategy - Use sources in priority order
     */
    private function calculatePriorityFlexible(
        float $targetAmount,
        array $flexibleSources,
        ?array $priorityOrder
    ): array {
        if (!$priorityOrder) {
            // Default priority: use smallest balances first
            return $this->calculateDrainSmallestFlexible($targetAmount, $flexibleSources);
        }
        
        // Sort sources by priority order
        usort($flexibleSources, function($a, $b) use ($priorityOrder) {
            $aPriority = array_search($a['source']['institution'] ?? '', $priorityOrder);
            $bPriority = array_search($b['source']['institution'] ?? '', $priorityOrder);
            if ($aPriority === false) $aPriority = PHP_INT_MAX;
            if ($bPriority === false) $bPriority = PHP_INT_MAX;
            return $aPriority <=> $bPriority;
        });
        
        $contributions = [];
        $remaining = $targetAmount;
        
        foreach ($flexibleSources as $index => $source) {
            if ($remaining <= 0.01) break;
            
            $takeFromSource = min($source['available_balance'], $remaining);
            
            if ($takeFromSource > 0) {
                $contributions[] = [
                    'source' => $source['source'],
                    'asset_type' => $source['asset_type'],
                    'requested_amount' => $takeFromSource,
                    'actual_amount' => $takeFromSource,
                    'contribution_type' => 'PRIORITY',
                    'order' => $index + 1
                ];
                $remaining -= $takeFromSource;
            }
        }
        
        if ($remaining > 0.01) {
            throw new \RuntimeException(
                sprintf("Cannot reach target (%.2f) even after using priority sources", $targetAmount)
            );
        }
        
        return $contributions;
    }
    
    /**
     * USER_SPECIFIED strategy - User specified exact amounts
     */
    private function calculateUserSpecifiedFlexible(
        float $targetAmount,
        array $flexibleSources,
        ?array $userSpecified
    ): array {
        if (!$userSpecified) {
            throw new \RuntimeException("User specified amounts required for USER_SPECIFIED strategy");
        }
        
        $contributions = [];
        $totalSpecified = 0;
        
        foreach ($flexibleSources as $index => $source) {
            $institution = $source['source']['institution'] ?? '';
            $specifiedAmount = $userSpecified[$institution] ?? 0;
            
            $actualAmount = min($specifiedAmount, $source['available_balance']);
            $contributions[] = [
                'source' => $source['source'],
                'asset_type' => $source['asset_type'],
                'requested_amount' => $specifiedAmount,
                'actual_amount' => $actualAmount,
                'contribution_type' => 'USER_SPECIFIED',
                'order' => $index + 1
            ];
            $totalSpecified += $actualAmount;
        }
        
        if (abs($totalSpecified - $targetAmount) > 0.01) {
            throw new \RuntimeException(
                sprintf("User specified total (%.2f) doesn't match target (%.2f)", 
                    $totalSpecified, $targetAmount)
            );
        }
        
        return $contributions;
    }
    
    /**
     * RATIO strategy - Proportional to available balance (original behavior)
     */
    private function calculateRatioBasedFlexible(
        float $targetAmount,
        array $flexibleSources
    ): array {
        $totalFlexibleBalance = array_sum(array_column($flexibleSources, 'available_balance'));
        
        if ($totalFlexibleBalance < $targetAmount - 0.01) {
            throw new \RuntimeException(
                sprintf("Insufficient flexible balance (%.2f) for target (%.2f)", 
                    $totalFlexibleBalance, $targetAmount)
            );
        }
        
        $contributions = [];
        $runningTotal = 0;
        
        foreach ($flexibleSources as $index => $source) {
            $ratio = $source['available_balance'] / $totalFlexibleBalance;
            $calculatedAmount = $targetAmount * $ratio;
            $roundedAmount = round($calculatedAmount, 2);
            
            $contributions[] = [
                'source' => $source['source'],
                'asset_type' => $source['asset_type'],
                'requested_amount' => $roundedAmount,
                'actual_amount' => $roundedAmount,
                'contribution_type' => 'RATIO',
                'order' => $index + 1
            ];
            $runningTotal += $roundedAmount;
        }
        
        $roundingError = $targetAmount - $runningTotal;
        if (abs($roundingError) > 0.01) {
            $largestIndex = $this->findLargestContributionIndex($contributions);
            $contributions[$largestIndex]['actual_amount'] += $roundingError;
            $contributions[$largestIndex]['requested_amount'] += $roundingError;
        }
        
        return $contributions;
    }
    
    /**
     * DRAIN_SMALLEST strategy - Use smallest balances first
     */
    private function calculateDrainSmallestFlexible(
        float $targetAmount,
        array $flexibleSources
    ): array {
        usort($flexibleSources, fn($a, $b) => $a['available_balance'] <=> $b['available_balance']);
        
        $contributions = [];
        $remaining = $targetAmount;
        
        foreach ($flexibleSources as $index => $source) {
            if ($remaining <= 0.01) break;
            
            $takeFromSource = min($source['available_balance'], $remaining);
            
            if ($takeFromSource > 0) {
                $contributions[] = [
                    'source' => $source['source'],
                    'asset_type' => $source['asset_type'],
                    'requested_amount' => $takeFromSource,
                    'actual_amount' => $takeFromSource,
                    'contribution_type' => 'DRAIN_SMALLEST',
                    'order' => $index + 1
                ];
                $remaining -= $takeFromSource;
            }
        }
        
        if ($remaining > 0.01) {
            throw new \RuntimeException(
                sprintf("Cannot reach target (%.2f) even after draining all flexible sources", $targetAmount)
            );
        }
        
        return $contributions;
    }
    
    /**
     * Distribute remaining amount across sources
     */
    private function distributeRemaining(array &$contributions, array $flexibleSources, float $remaining): void
    {
        // Find sources that can take more
        $candidates = [];
        foreach ($flexibleSources as $index => $source) {
            $alreadyAllocated = $contributions[$index]['actual_amount'] ?? 0;
            $available = $source['available_balance'];
            if ($available > $alreadyAllocated) {
                $candidates[] = [
                    'index' => $index,
                    'available' => $available - $alreadyAllocated
                ];
            }
        }
        
        if (empty($candidates)) {
            throw new \RuntimeException("Cannot distribute remaining amount - no capacity left");
        }
        
        // Distribute remaining equally among candidates
        $remainingPerCandidate = $remaining / count($candidates);
        $remainingLeft = $remaining;
        
        foreach ($candidates as $candidate) {
            $takeFromThis = min($remainingPerCandidate, $candidate['available']);
            $contributions[$candidate['index']]['actual_amount'] += $takeFromThis;
            $contributions[$candidate['index']]['requested_amount'] += $takeFromThis;
            $remainingLeft -= $takeFromThis;
        }
        
        // If still remaining, distribute to largest capacity
        if ($remainingLeft > 0.01) {
            // Sort by available capacity descending
            usort($candidates, fn($a, $b) => $b['available'] <=> $a['available']);
            foreach ($candidates as $candidate) {
                if ($remainingLeft <= 0.01) break;
                $takeFromThis = min($remainingLeft, $candidate['available']);
                $contributions[$candidate['index']]['actual_amount'] += $takeFromThis;
                $contributions[$candidate['index']]['requested_amount'] += $takeFromThis;
                $remainingLeft -= $takeFromThis;
            }
        }
    }
    
    /**
     * Check if an asset type is a voucher (fixed)
     */
    private function isFixedAsset(string $assetType): bool
    {
        $assetType = strtoupper($assetType);
        return in_array($assetType, $this->fixedAssetTypes);
    }
    
    /**
     * Check if an asset type is a voucher
     */
    private function isVoucher(string $assetType): bool
    {
        $assetType = strtoupper($assetType);
        return in_array($assetType, ['VOUCHER', 'CASHOUT-VOUCHER']);
    }
    
    /**
     * Check if ATM code is a voucher (fixed) or wallet-backed (flexible)
     */
    private function isVoucherATM(array $source): bool
    {
        if (isset($source['is_voucher']) && $source['is_voucher'] === true) {
            return true;
        }
        
        if (isset($source['atm_type']) && strtoupper($source['atm_type']) === 'VOUCHER') {
            return true;
        }
        
        if (isset($source['wallet_id']) || isset($source['wallet_reference'])) {
            return false;
        }
        
        return false;
    }
    
    /**
     * Find the largest contribution for rounding adjustment
     */
    private function findLargestContributionIndex(array $contributions): int
    {
        $largestIndex = 0;
        $largestAmount = 0;
        
        foreach ($contributions as $index => $contribution) {
            $amount = $contribution['actual_amount'] ?? $contribution['amount'] ?? 0;
            if ($amount > $largestAmount) {
                $largestAmount = $amount;
                $largestIndex = $index;
            }
        }
        
        return $largestIndex;
    }
    
    /**
     * Get a summary of the contribution breakdown
     */
    public function getContributionSummary(array $contributions): array
    {
        $fixedTotal = 0;
        $flexibleTotal = 0;
        $fixedCount = 0;
        $flexibleCount = 0;
        $breakdown = [];
        
        foreach ($contributions as $contrib) {
            $isFixed = $contrib['is_fixed'] ?? false;
            $amount = $contrib['actual_amount'] ?? 0;
            $assetType = $contrib['asset_type'] ?? 'UNKNOWN';
            $institution = $contrib['source']['institution'] ?? 'UNKNOWN';
            $isVoucher = $contrib['is_voucher'] ?? false;
            
            if ($isFixed) {
                $fixedTotal += $amount;
                $fixedCount++;
                $breakdown['vouchers'][] = [
                    'institution' => $institution,
                    'asset_type' => $assetType,
                    'amount' => $amount,
                    'is_voucher' => $isVoucher
                ];
            } else {
                $flexibleTotal += $amount;
                $flexibleCount++;
                $breakdown['flexible'][] = [
                    'institution' => $institution,
                    'asset_type' => $assetType,
                    'amount' => $amount,
                    'strategy' => $contrib['contribution_type'] ?? 'FLEXIBLE'
                ];
            }
        }
        
        return [
            'total_contributions' => $fixedTotal + $flexibleTotal,
            'voucher_total' => $fixedTotal,
            'flexible_total' => $flexibleTotal,
            'voucher_count' => $fixedCount,
            'flexible_count' => $flexibleCount,
            'breakdown' => $breakdown,
            'has_vouchers' => $fixedCount > 0,
            'has_flexible_sources' => $flexibleCount > 0
        ];
    }
}
