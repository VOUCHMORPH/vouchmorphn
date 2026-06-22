<?php
declare(strict_types=1);

namespace Domain\Services;

/**
 * Contribution Calculator - VOUCHER AWARE
 * 
 * Vouchers are FIXED AMOUNTS - they cannot be split.
 * The full voucher amount must be used, and the remaining
 * balance comes from other flexible sources.
 * 
 * Asset Types that are FIXED (cannot be split):
 * - VOUCHER
 * - CASHOUT-VOUCHER
 * 
 * Asset Types that are FLEXIBLE (can be split/partial):
 * - ACCOUNT
 * - BANK-WALLET
 * - MNO-WALLET
 * - E-WALLET
 * - CARD (if tied to wallet/account)
 * - ATM (if tied to wallet/account, not a voucher)
 * 
 * The key distinction: 
 * - VOUCHER = fixed amount, cannot be split
 * - ATM code = can be split IF it's backed by a wallet (flexible)
 * - ATM code = cannot be split IF it's a voucher (fixed)
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
     * These can be partially used
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
     * VOUCHER-AWARE: Fixed amounts are used in full
     */
    public function calculateContributions(
        float $targetAmount,
        array $sources, // Each source has ['institution', 'asset_type', 'identifier', 'available_balance']
        string $strategy, // 'user_specified', 'ratio', 'drain_smallest'
        ?array $userSpecified = null
    ): array {
        // First, separate fixed and flexible sources based on asset type AND context
        $fixedSources = [];
        $flexibleSources = [];
        $fixedTotal = 0;
        
        foreach ($sources as $source) {
            $assetType = strtoupper($source['asset_type'] ?? 'ACCOUNT');
            $isVoucher = $this->isVoucher($assetType);
            
            // Check if this is a voucher (fixed)
            $isFixed = $this->isFixedAsset($assetType);
            
            // Special case: ATM code that is a voucher is fixed
            // ATM code tied to wallet is flexible
            if ($assetType === 'ATM') {
                // Check if this ATM is a voucher or wallet-backed
                $isVoucherATM = $this->isVoucherATM($source);
                if ($isVoucherATM) {
                    $isFixed = true;
                    error_log("[ContributionCalculator] ATM code is a VOUCHER - FIXED");
                } else {
                    $isFixed = false;
                    error_log("[ContributionCalculator] ATM code is wallet-backed - FLEXIBLE");
                }
            }
            
            // Special case: CARD is flexible (can be split)
            if ($assetType === 'CARD') {
                $isFixed = false;
                error_log("[ContributionCalculator] CARD is flexible");
            }
            
            if ($isFixed) {
                // Fixed asset - full amount must be used
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
            // Return fixed sources only
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
            $userSpecified
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
        // Check if the ATM has a voucher flag
        if (isset($source['is_voucher']) && $source['is_voucher'] === true) {
            return true;
        }
        
        // Check if ATM code is tied to a voucher
        if (isset($source['atm_type']) && strtoupper($source['atm_type']) === 'VOUCHER') {
            return true;
        }
        
        // Check if ATM has a wallet reference (flexible)
        if (isset($source['wallet_id']) || isset($source['wallet_reference'])) {
            return false; // Wallet-backed, flexible
        }
        
        // Default: ATM is flexible (wallet-backed)
        return false;
    }
    
    /**
     * Calculate contributions for flexible sources only
     */
    private function calculateFlexibleContributions(
        float $targetAmount,
        array $flexibleSources,
        string $strategy,
        ?array $userSpecified = null
    ): array {
        // If no flexible sources, throw error
        if (empty($flexibleSources)) {
            throw new \RuntimeException(
                sprintf("No flexible sources available to cover remaining amount (%.2f)", $targetAmount)
            );
        }
        
        // Check total available balance of flexible sources
        $totalFlexibleBalance = array_sum(array_column($flexibleSources, 'available_balance'));
        
        if ($totalFlexibleBalance < $targetAmount - 0.01) {
            throw new \RuntimeException(
                sprintf("Insufficient flexible balance (%.2f) for remaining amount (%.2f)", 
                    $totalFlexibleBalance, $targetAmount)
            );
        }
        
        // Apply strategy
        return match($strategy) {
            'user_specified' => $this->calculateUserSpecifiedFlexible($targetAmount, $flexibleSources, $userSpecified),
            'drain_smallest' => $this->calculateDrainSmallestFlexible($targetAmount, $flexibleSources),
            default => $this->calculateRatioBasedFlexible($targetAmount, $flexibleSources)
        };
    }
    
    /**
     * User specified amounts for flexible sources
     */
    private function calculateUserSpecifiedFlexible(
        float $targetAmount,
        array $flexibleSources,
        ?array $userSpecified
    ): array {
        $contributions = [];
        $totalSpecified = 0;
        
        foreach ($flexibleSources as $index => $source) {
            $institution = $source['source']['institution'] ?? '';
            $specifiedAmount = $userSpecified[$institution] ?? 0;
            
            // Validate not exceeding available balance
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
        
        // Validate total matches target
        if (abs($totalSpecified - $targetAmount) > 0.01) {
            throw new \RuntimeException(
                sprintf("User specified total (%.2f) doesn't match target (%.2f)", 
                    $totalSpecified, $targetAmount)
            );
        }
        
        return $contributions;
    }
    
    /**
     * Ratio-based distribution for flexible sources
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
            
            // Round to 2 decimal places
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
        
        // Adjust for rounding errors
        $roundingError = $targetAmount - $runningTotal;
        if (abs($roundingError) > 0.01) {
            $largestIndex = $this->findLargestContributionIndex($contributions);
            $contributions[$largestIndex]['actual_amount'] += $roundingError;
            $contributions[$largestIndex]['requested_amount'] += $roundingError;
        }
        
        return $contributions;
    }
    
    /**
     * Drain smallest balances first for flexible sources
     */
    private function calculateDrainSmallestFlexible(
        float $targetAmount,
        array $flexibleSources
    ): array {
        // Sort by available balance (ascending)
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
