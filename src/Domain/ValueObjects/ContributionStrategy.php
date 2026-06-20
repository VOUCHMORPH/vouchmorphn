<?php
declare(strict_types=1);

namespace Domain\ValueObjects;

enum ContributionStrategy: string
{
    case EQUAL = 'EQUAL';
    case RATIO = 'RATIO';
    case PRIORITY = 'PRIORITY';
    case USER_SPECIFIED = 'USER_SPECIFIED';
    case SMART = 'SMART';
}
