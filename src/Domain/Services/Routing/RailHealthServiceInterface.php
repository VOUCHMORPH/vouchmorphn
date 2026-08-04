<?php
declare(strict_types=1);

namespace Domain\Services\Routing;

/**
 * Deliberately unimplemented today. Once EMIS confirms Kwik's actual
 * health signal (poll endpoint, webhook heartbeat, or timeout-derived),
 * this gets a real implementation. Until then, RouteResolver treats
 * "no health service" as "assume everything advertised in policy is up" -
 * which is fine, because right now nothing in participant_rails lists
 * a switch anyway (see the empty default in RoutingPolicyConfig).
 */
interface RailHealthServiceInterface
{
    public function isAvailable(string $rail): bool;
}
