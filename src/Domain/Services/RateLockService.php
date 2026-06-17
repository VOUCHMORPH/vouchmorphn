<?php
// src/Domain/Services/RateLockService.php
class RateLockService 
{
    public function lockRate(RateDTO $rate, SwapTransaction $swap): string 
    {
        // Store the rateToken with the swap transaction
        $swap->rateToken = $rate->getRateToken();
        $swap->lockedRate = $rate->getRate();
        $swap->rateExpiresAt = $rate->getExpiresAt();
        
        // Save to database
        $this->swapRepository->save($swap);
        
        return $rate->getRateToken();
    }
    
    public function validateLock(SwapTransaction $swap): bool 
    {
        // Ensure rate hasn't expired before executing swap
        return $swap->rateExpiresAt > time();
    }
}
