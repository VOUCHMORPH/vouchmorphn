<?php
declare(strict_types=1);
namespace Domain\ValueObjects;
enum PoolStatus: string
{
    case CREATED = 'CREATED';
    case VERIFYING = 'VERIFYING';
    case HOLDING = 'HOLDING';
    case FUNDED = 'FUNDED';
    case DESTINATION_PENDING = 'DESTINATION_PENDING';
    case DESTINATION_COMPLETED = 'DESTINATION_COMPLETED';
    case PENDING_CASHOUT = 'PENDING_CASHOUT';
    case PENDING_IDENTITY_CLAIM = 'PENDING_ID_CLAIM';
    case DEBITING = 'DEBITING';
    case SETTLING = 'SETTLING';
    case INVOICING = 'INVOICING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';
    case ROLLED_BACK = 'ROLLED_BACK';
}
