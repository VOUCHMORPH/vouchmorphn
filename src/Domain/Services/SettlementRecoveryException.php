<?php
declare(strict_types=1);

namespace Domain\Services;

/**
 * Thrown by SwapService::retrySettlementOrCompensate() (swap-to-identity
 * algorithm v2, plan §3a) once automatic recovery from a
 * debit-succeeded-but-settlement-failed hold has run its course --
 * either the debited amount was successfully credited back to the
 * source ($wasCompensated = true, no further action needed), or that
 * compensation attempt also failed and manual reconciliation has already
 * been flagged inside that method ($wasCompensated = false).
 *
 * Distinguishing this from a generic \Throwable lets
 * finalizeHoldToReceiving()'s catch block skip re-flagging manual
 * reconciliation a second time -- retrySettlementOrCompensate() already
 * made that call (or didn't need to) before throwing.
 */
class SettlementRecoveryException extends \RuntimeException
{
    public bool $wasCompensated;

    public function __construct(string $message, bool $wasCompensated)
    {
        parent::__construct($message);
        $this->wasCompensated = $wasCompensated;
    }
}
