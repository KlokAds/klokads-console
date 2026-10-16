<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Setting;

/**
 * Ad-budget pricing: the client pays the card and payout fees on top, so Klok Ads
 * nets exactly the budget, and the commission is a share of that net amount.
 *
 * All amounts are integers in cents.
 */
class Pricing
{
    public function __construct(
        public float $cardFeePercent,
        public int $cardFeeFixed,
        public float $payoutFeePercent,
        public float $commissionRate,
    ) {}

    public static function forClient(?Client $client = null): self
    {
        $rate = $client?->commission_rate ?? Setting::get('commission_rate', 20);

        return new self(
            (float) Setting::get('card_fee_percent', 2.9),
            (int) Setting::get('card_fee_fixed', 30),
            (float) Setting::get('payout_fee_percent', 0.3),
            (float) $rate,
        );
    }

    /**
     * Smallest charge that, after the card fee and then the payout fee, leaves at least $net.
     * net = (charge − charge·p − f) · (1 − q)  ⇒  charge = (net / (1 − q) + f) / (1 − p)
     */
    public function grossUp(int $net): int
    {
        $p = $this->cardFeePercent / 100;
        $q = $this->payoutFeePercent / 100;

        $charge = (int) ceil((($net / (1 - $q)) + $this->cardFeeFixed) / (1 - $p));

        // Fees are rounded to whole cents, so settle on the smallest charge that still nets $net.
        while ($this->netOf($charge) < $net) {
            $charge++;
        }
        while ($charge > $net && $this->netOf($charge - 1) >= $net) {
            $charge--;
        }

        return $charge;
    }

    /** Estimated amount that reaches the bank from a charge. */
    public function netOf(int $charge): int
    {
        $afterCard = $charge - (int) round($charge * $this->cardFeePercent / 100) - $this->cardFeeFixed;

        return $afterCard - (int) round($afterCard * $this->payoutFeePercent / 100);
    }

    /**
     * @return array{net: int, charge: int, processing_fee: int, card_fee: int, payout_fee: int, commission_rate: float, commission: int, ad_budget: int}
     */
    public function breakdown(int $net, bool $passFees = true): array
    {
        $charge = $passFees ? $this->grossUp($net) : $net;
        $cardFee = (int) round($charge * $this->cardFeePercent / 100) + $this->cardFeeFixed;
        $received = $passFees ? $net : $this->netOf($charge);
        $commission = (int) round($received * $this->commissionRate / 100);

        return [
            'net' => $received,
            'charge' => $charge,
            'processing_fee' => $charge - $net,
            'card_fee' => $cardFee,
            'payout_fee' => ($charge - $cardFee) - $received,
            'commission_rate' => $this->commissionRate,
            'commission' => $commission,
            'ad_budget' => $received - $commission,
        ];
    }

    /** @return array{card_fee_percent: float, card_fee_fixed: int, payout_fee_percent: float, commission_rate: float} */
    public function toArray(): array
    {
        return [
            'card_fee_percent' => $this->cardFeePercent,
            'card_fee_fixed' => $this->cardFeeFixed,
            'payout_fee_percent' => $this->payoutFeePercent,
            'commission_rate' => $this->commissionRate,
        ];
    }
}
