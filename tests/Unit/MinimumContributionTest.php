<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Domain\Services\ContributionCalculator;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * ContributionCalculator's minimum contribution: the least a source may pay
 * when its share goes out at all. CardService::finalizePooledSwipe() splits
 * an approved VouchMorph Card swipe with it (the DEPOSIT F1, P6, or at an
 * ATM the CASHOUT F1 plus the smallest note, P20), and
 * authorizePooledSwipe() declines a swipe it can't split.
 *
 * SMART splits a swipe evenly, so a small one put every share under the
 * minimum and the calculator threw - after the ATM had dispensed - even
 * when any one source could pay it all. It now goes on the fewest sources
 * that can each pay the minimum, largest held first. And re-splitting what
 * was left after dropping a share under the minimum could put another share
 * under it (a P100 swipe on P1000, P25 and P5 holds: P97.56 and P2.44).
 */
class MinimumContributionTest extends TestCase
{
    /**
     * The SMART split of $amount across sources given as [id => held], as
     * [id => share] for the sources that pay.
     */
    private function split(float $amount, array $held, ?float $minimum): array
    {
        $sources = [];
        foreach ($held as $id => $heldAmount) {
            $sources[] = [
                'hook_source_id' => $id,
                'institution' => 'BANK' . $id,
                'asset_type' => 'ACCOUNT',
                'identifier' => '7000' . $id,
                'available_balance' => $heldAmount,
            ];
        }

        $shares = [];
        foreach ((new ContributionCalculator())->calculateContributions($amount, $sources, 'SMART', null, null, $minimum) as $c) {
            $shares[$c['source']['hook_source_id']] = round($c['actual_amount'], 2);
        }
        return $shares;
    }

    public function testAnEvenSplitThatClearsTheMinimumStands(): void
    {
        $this->assertEquals([1 => 50.0, 2 => 50.0], $this->split(100, [1 => 300, 2 => 200], 6));
    }

    public function testWithoutAMinimumAnEvenSplitStandsHoweverSmall(): void
    {
        $this->assertEquals([1 => 16.67, 2 => 16.67, 3 => 16.67], $this->split(50, [1 => 500, 2 => 500, 3 => 500], null));
    }

    public static function splitsUnderTheMinimum(): array
    {
        return [
            // P16.67 each; equal holds go to the one given first.
            'ATM P50 on three P500 holds' => [50, [1 => 500, 2 => 500, 3 => 500], 20, [1 => 50.0]],
            // P5 each.
            'POS P10 on two P500 holds' => [10, [1 => 500, 2 => 500], 6, [1 => 10.0]],
            'the largest hold pays' => [50, [1 => 30, 2 => 45, 3 => 200], 20, [3 => 50.0]],
            // No one hold covers P50; the largest leaves the next the minimum.
            'the two largest holds pay, each at least the minimum' => [50, [1 => 25, 2 => 45, 3 => 30], 20, [2 => 30.0, 3 => 20.0]],
        ];
    }

    #[DataProvider('splitsUnderTheMinimum')]
    public function testASplitThatPutsEveryShareUnderTheMinimumGoesOnTheLargestHolds(float $amount, array $held, float $minimum, array $shares): void
    {
        $this->assertEquals($shares, $this->split($amount, $held, $minimum));
    }

    public function testSourcesThatClearTheMinimumShareWhatTheOthersDropped(): void
    {
        // SMART gives the P10 hold P10, under P20; the other two split its share.
        $this->assertEquals([1 => 50.0, 2 => 50.0], $this->split(100, [1 => 500, 2 => 500, 3 => 10], 20));
    }

    public function testRedistributingNeverLeavesAShareUnderTheMinimum(): void
    {
        // SMART: P70, P25 and P5. Dropping the P5 and re-splitting by
        // holding gave the P25 hold P2.44.
        $this->assertEquals([1 => 100.0], $this->split(100, [1 => 1000, 2 => 25, 3 => 5], 20));
    }

    public function testTheSameAccountHookedTwiceIsTwoSources(): void
    {
        // Two holds on one account: only the P1000 one can pay P20 of P100.
        $sources = [
            ['hook_source_id' => 1, 'institution' => 'ZURUBANK', 'asset_type' => 'ACCOUNT', 'identifier' => '70001', 'available_balance' => 1000],
            ['hook_source_id' => 2, 'institution' => 'ZURUBANK', 'asset_type' => 'ACCOUNT', 'identifier' => '70001', 'available_balance' => 25],
            ['hook_source_id' => 3, 'institution' => 'SACCUSSALIS', 'asset_type' => 'ACCOUNT', 'identifier' => '70003', 'available_balance' => 5],
        ];

        $contributions = (new ContributionCalculator())->calculateContributions(100, $sources, 'SMART', null, null, 20);

        $this->assertCount(1, $contributions);
        $this->assertSame(1, $contributions[0]['source']['hook_source_id']);
        $this->assertEqualsWithDelta(100.0, $contributions[0]['actual_amount'], 0.001);
    }

    public static function splitsNoSourcesCanPay(): array
    {
        return [
            'the amount is under the minimum' => [5, [1 => 500], 6],
            'no source holds the minimum' => [40, [1 => 15, 2 => 15, 3 => 15], 20],
            // Either could pay P20, but not both out of P30.
            'the sources holding the minimum can\'t each pay it' => [30, [1 => 25, 2 => 25], 20],
        ];
    }

    #[DataProvider('splitsNoSourcesCanPay')]
    public function testASplitNoSourcesCanPayEachTheMinimumThrows(float $amount, array $held, float $minimum): void
    {
        $this->expectException(RuntimeException::class);

        $this->split($amount, $held, $minimum);
    }
}
