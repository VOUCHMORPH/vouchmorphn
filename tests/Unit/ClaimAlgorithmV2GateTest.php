<?php

use PHPUnit\Framework\TestCase;
use Domain\Services\SwapService;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Locks in SwapService::isClaimAlgorithmV2Enabled(), the staged-rollout
 * gate added after Increments 3-5 shipped ungated (a process gap: the
 * approved plan called for every new-behavior increment to ship behind
 * capabilities.claim_algorithm_v2, defaulting off, mirroring how
 * ReservationAccountService::isSupported() already gates
 * capabilities.reservation_accounts). Constructs SwapService without
 * running its constructor (which needs a live DB) and sets the private
 * $participants property directly via reflection -- this method only
 * ever reads that one property.
 */
class ClaimAlgorithmV2GateTest extends TestCase
{
    private function makeSwapServiceWithParticipants(array $participants): SwapService
    {
        $reflection = new \ReflectionClass(SwapService::class);
        $instance = $reflection->newInstanceWithoutConstructor();

        $prop = $reflection->getProperty('participants');
        $prop->setAccessible(true);
        $prop->setValue($instance, $participants);

        return $instance;
    }

    private function isEnabled(SwapService $instance, string $institution): bool
    {
        $method = new \ReflectionMethod(SwapService::class, 'isClaimAlgorithmV2Enabled');
        $method->setAccessible(true);
        return $method->invoke($instance, $institution);
    }

    public function testDisabledWhenFlagIsFalse(): void
    {
        $service = $this->makeSwapServiceWithParticipants([
            'ZURUBANK' => ['capabilities' => ['claim_algorithm_v2' => false]],
        ]);
        $this->assertFalse($this->isEnabled($service, 'ZURUBANK'));
    }

    public function testEnabledWhenFlagIsTrue(): void
    {
        $service = $this->makeSwapServiceWithParticipants([
            'ZURUBANK' => ['capabilities' => ['claim_algorithm_v2' => true]],
        ]);
        $this->assertTrue($this->isEnabled($service, 'ZURUBANK'));
    }

    public function testDefaultsToDisabledWhenCapabilityKeyMissing(): void
    {
        $service = $this->makeSwapServiceWithParticipants([
            'ZURUBANK' => ['capabilities' => ['reservation_accounts' => true]],
        ]);
        $this->assertFalse($this->isEnabled($service, 'ZURUBANK'));
    }

    public function testDefaultsToDisabledWhenInstitutionUnknown(): void
    {
        $service = $this->makeSwapServiceWithParticipants([]);
        $this->assertFalse($this->isEnabled($service, 'UNKNOWN_BANK'));
    }

    public function testCaseInsensitiveInstitutionLookupMirrorsReservationAccountService(): void
    {
        $service = $this->makeSwapServiceWithParticipants([
            'ZURUBANK' => ['capabilities' => ['claim_algorithm_v2' => true]],
        ]);
        $this->assertTrue($this->isEnabled($service, 'zurubank'));
    }

    public function testFlagsAreIndependentPerInstitution(): void
    {
        $service = $this->makeSwapServiceWithParticipants([
            'ZURUBANK' => ['capabilities' => ['claim_algorithm_v2' => true]],
            'SACCUSSALIS' => ['capabilities' => ['claim_algorithm_v2' => false]],
        ]);
        $this->assertTrue($this->isEnabled($service, 'ZURUBANK'));
        $this->assertFalse($this->isEnabled($service, 'SACCUSSALIS'));
    }
}
