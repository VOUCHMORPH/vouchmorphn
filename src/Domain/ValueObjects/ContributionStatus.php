<?php
declare(strict_types=1);

namespace Domain\ValueObjects;

/**
 * Status lifecycle for a single source's contribution within a
 * multi-source funding pool.
 *
 * Normal path: PENDING -> VERIFIED -> HELD -> DEBITED -> COMPLETED
 * Failure path: any state -> FAILED (source-level failure, e.g. hold rejected)
 * Failure path: any state -> CANCELLED (pool-level cancellation, e.g. rollback)
 */
enum ContributionStatus: string
{
    case PENDING = 'PENDING';
    case VERIFIED = 'VERIFIED';
    case HELD = 'HELD';
    case DEBITED = 'DEBITED';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';
}
