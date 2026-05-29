<?php
declare(strict_types=1);

namespace Domain\Services;

class ContributionCalculator
{
    /**
     * Calculate contributions based on selected strategy
     */
    public function calculateContributions(
        float $targetAmount,
        array $sources, // Each source has ['institution', 'asset_type', 'identifier', 'available_balance']
        string $strategy, // 'user_specified', 'ratio', 'drain_smallest'
        ?array $userSpecified = null
    ): array {
        return match($strategy) {
            'user_specified' => $this->calculateUserSpecified($targetAmount, $sources, $userSpecified),
            'ratio' => $this->calculateRatioBased($targetAmount, $sources),
            'drain_smallest' => $this->calculateDrainSmallest($targetAmount, $sources),
            default => $this->calculateRatioBased($targetAmount, $sources)
        };
    }
    
    /**
     * Option A: User specifies exact amounts per source
     */
    private function calculateUserSpecified(
        float $targetAmount, 
        array $sources, 
        array $userSpecified
    ): array {
        $contributions = [];
        $totalSpecified = 0;
        
        foreach ($sources as $index => $source) {
            $specifiedAmount = $userSpecified[$source['institution']] ?? 0;
            
            // Validate not exceeding available balance
            $actualAmount = min($specifiedAmount, $source['available_balance']);
            $contributions[] = [
                'source' => $source,
                'requested_amount' => $specifiedAmount,
                'actual_amount' => $actualAmount,
                'order' => $index + 1
            ];
            $totalSpecified += $actualAmount;
        }
        
        // Validate total matches target (within rounding)
        if (abs($totalSpecified - $targetAmount) > 0.01) {
            throw new \RuntimeException(
                sprintf("User specified total (%.2f) doesn't match target (%.2f)", 
                    $totalSpecified, $targetAmount)
            );
        }
        
        return $contributions;
    }
    
    /**
     * Option B: Ratio-based distribution
     */
    private function calculateRatioBased(float $targetAmount, array $sources): array
    {
        $totalBalance = array_sum(array_column($sources, 'available_balance'));
        
        if ($totalBalance < $targetAmount - 0.01) {
            throw new \RuntimeException(
                sprintf("Insufficient total balance (%.2f) for target (%.2f)", 
                    $totalBalance, $targetAmount)
            );
        }
        
        $contributions = [];
        $runningTotal = 0;
        
        foreach ($sources as $index => $source) {
            $ratio = $source['available_balance'] / $totalBalance;
            $calculatedAmount = $targetAmount * $ratio;
            
            // Round to 2 decimal places
            $roundedAmount = round($calculatedAmount, 2);
            
            $contributions[] = [
                'source' => $source,
                'requested_amount' => $roundedAmount,
                'actual_amount' => $roundedAmount,
                'order' => $index + 1
            ];
            $runningTotal += $roundedAmount;
        }
        
        // Adjust for rounding errors (add/subtract from largest contribution)
        $roundingError = $targetAmount - $runningTotal;
        if (abs($roundingError) > 0.01) {
            $largestIndex = $this->findLargestContributionIndex($contributions);
            $contributions[$largestIndex]['actual_amount'] += $roundingError;
        }
        
        return $contributions;
    }
    
    /**
     * Option C: Drain smallest balances first (recommended default)
     */
    private function calculateDrainSmallest(float $targetAmount, array $sources): array
    {
        // Sort by available balance (ascending)
        usort($sources, fn($a, $b) => $a['available_balance'] <=> $b['available_balance']);
        
        $contributions = [];
        $remaining = $targetAmount;
        
        foreach ($sources as $index => $source) {
            if ($remaining <= 0.01) break;
            
            $takeFromSource = min($source['available_balance'], $remaining);
            
            if ($takeFromSource > 0) {
                $contributions[] = [
                    'source' => $source,
                    'requested_amount' => $takeFromSource,
                    'actual_amount' => $takeFromSource,
                    'order' => $index + 1
                ];
                $remaining -= $takeFromSource;
            }
        }
        
        if ($remaining > 0.01) {
            throw new \RuntimeException(
                sprintf("Cannot reach target (%.2f) even after draining all sources", $targetAmount)
            );
        }
        
        return $contributions;
    }
    
    private function findLargestContributionIndex(array $contributions): int
    {
        $largestIndex = 0;
        $largestAmount = 0;
        
        foreach ($contributions as $index => $contribution) {
            if ($contribution['actual_amount'] > $largestAmount) {
                $largestAmount = $contribution['actual_amount'];
                $largestIndex = $index;
            }
        }
        
        return $largestIndex;
    }
}
