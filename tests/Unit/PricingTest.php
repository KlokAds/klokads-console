<?php

namespace Tests\Unit;

use App\Support\Pricing;
use PHPUnit\Framework\TestCase;

class PricingTest extends TestCase
{
    public function test_the_client_pays_the_fees_and_we_net_the_budget(): void
    {
        $pricing = new Pricing(2.9, 30, 0.3, 20);

        $b = $pricing->breakdown(50000);

        $this->assertSame(51679, $b['charge']);           // client pays 516.79
        $this->assertSame(1679, $b['processing_fee']);    // fee line on the invoice
        $this->assertSame(50000, $b['net']);              // 500.00 reaches the bank
        $this->assertSame(10000, $b['commission']);       // 20% is Klok Ads'
        $this->assertSame(40000, $b['ad_budget']);        // 400.00 to spend on ads
        $this->assertGreaterThanOrEqual(50000, $pricing->netOf($b['charge']));
        $this->assertLessThan(50000, $pricing->netOf($b['charge'] - 1));
    }

    public function test_gross_up_is_exact_for_many_amounts_and_fee_levels(): void
    {
        foreach ([[2.9, 30, 0.3], [3.4, 50, 0.3], [3.4, 50, 0.0], [4.4, 50, 1.0]] as [$p, $f, $q]) {
            $pricing = new Pricing($p, $f, $q, 20);
            foreach ([100, 999, 12345, 50000, 189029, 1000000] as $net) {
                $charge = $pricing->grossUp($net);
                $this->assertGreaterThanOrEqual($net, $pricing->netOf($charge), "net {$net} at {$p}%+{$f}");
                $this->assertLessThan($net, $pricing->netOf($charge - 1), "charge for {$net} is not the smallest");
            }
        }
    }

    public function test_without_passing_fees_the_commission_comes_from_what_we_actually_net(): void
    {
        $b = (new Pricing(2.9, 30, 0.3, 20))->breakdown(50000, passFees: false);

        $this->assertSame(50000, $b['charge']);
        $this->assertSame(0, $b['processing_fee']);
        // 500.00 − card fee 14.80 (2.9% + 0.30) = 485.20, − payout 0.3% (1.46) = 483.74
        $this->assertSame(48374, $b['net']);
        $this->assertSame(9675, $b['commission']);
    }

    public function test_commission_rate_can_differ(): void
    {
        $b = (new Pricing(2.9, 30, 0.3, 15))->breakdown(100000);

        $this->assertSame(15000, $b['commission']);
        $this->assertSame(85000, $b['ad_budget']);
    }
}
