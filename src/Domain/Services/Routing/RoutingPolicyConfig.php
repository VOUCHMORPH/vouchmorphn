<?php
declare(strict_types=1);

namespace Domain\Services\Routing;

/**
 * Capability + policy source. Deliberately a plain PHP array config for now
 * instead of YAML, because LoadCountry's manual YAML parser can't represent
 * nested capability blocks or lists reliably. Swap this for real parsed
 * YAML once ext-yaml / symfony/yaml is confirmed available - the shape
 * below is what that YAML should eventually deserialize into.
 *
 * Lives at: Core/Config/Countries/{Country}/routing_policy.php
 * Falls back to "everything is DIRECT-only" if the file doesn't exist,
 * so this is safe to drop into Botswana today with zero behavior change.
 */
final class RoutingPolicyConfig
{
    public static function load(string $countryDir): array
    {
        $path = $countryDir . '/routing_policy.php';

        if (!file_exists($path)) {
            // No policy file = no switches known. Every operation stays DIRECT.
            return [
                'participant_rails' => [],   // institution => ['DIRECT', 'KWIK', ...]
                'participant_ops'   => [],   // institution => ['deposit' => true, 'cashout' => true, ...]
                'operation_policy'  => [],   // operation => ['preferred' => ['KWIK','DIRECT'], 'reservation' => bool]
            ];
        }

        return require $path;
    }
}
