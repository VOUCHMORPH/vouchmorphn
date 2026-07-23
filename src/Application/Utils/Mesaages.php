<?php
declare(strict_types=1);

/**
 * Centralized user-facing message strings, so wording stays
 * consistent across SwapService, API endpoints, and the dashboard,
 * and can be updated in one place.
 */
class Messages
{
    const SWAP_SUCCESS_CASHOUT = "Swap successful! Here are your cashout details.";
    const IDENTITY_NOT_VERIFIED = "Ask your agent or government official to help add this identity to a VouchMorph account.";
    const INSUFFICIENT_BALANCE = "You have insufficient balance to perform this swap.";
    const IDENTITY_SWAP_PENDING = "Swap to identity successful! Finalize it at any shop, agent, or ATM.";
    const GOVERNMENT_ID_REQUIRES_AGENT = "Government-issued IDs can only be added with help from a VouchMorph agent or government official.";
    const IDENTITY_ALREADY_REGISTERED = "This identity is already registered to another VouchMorph account. Identities cannot be shared between accounts.";
}
