<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatementDescriptorTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_service_has_its_own_statement_text(): void
    {
        $this->assertSame('META ADS', StripeService::descriptorFor('meta_ads'));
        $this->assertSame('GOOGLE ADS', StripeService::descriptorFor('google_ads'));
        $this->assertSame('SEO', StripeService::descriptorFor('seo'));
        $this->assertSame('SERVICES', StripeService::descriptorFor(null));
    }

    public function test_text_is_cleaned_and_fits_with_the_prefix(): void
    {
        Setting::put('descriptor_prefix', 'KLOKADS');
        $this->assertSame(13, StripeService::descriptorMaxLength()); // 22 − "KLOKADS" − "* "

        $this->assertSame('META ADS OCT', StripeService::cleanDescriptor("  meta*  ads 'oct'  "));
        $this->assertSame('GOOGLE ADS SE', StripeService::cleanDescriptor('Google Ads Search Campaign'));
        $this->assertNull(StripeService::cleanDescriptor('1234'));
    }

    public function test_the_month_is_added_and_always_fits(): void
    {
        Setting::put('descriptor_prefix', 'KLOKADS');
        $sep = new \DateTimeImmutable('2026-09-15');

        $this->assertSame('META ADS SEP', StripeService::descriptorFor('meta_ads', $sep));      // KLOKADS* META ADS SEP (21)
        $this->assertSame('GOOGLE SEP', StripeService::descriptorFor('google_ads', $sep));      // "GOOGLE ADS SEP" would be 14 > 13
        $this->assertSame('SEO SEP', StripeService::descriptorFor('seo', $sep));
        $this->assertLessThanOrEqual(13, strlen(StripeService::descriptorFor('tiktok_ads', $sep)));

        Setting::put('descriptor_with_month', '0');
        $this->assertSame('META ADS', StripeService::descriptorFor('meta_ads', $sep));
    }

    public function test_owner_can_change_the_text_per_service(): void
    {
        $this->actingAs(User::factory()->create())->post('/settings/descriptors', [
            'descriptors' => [
                'meta_ads' => 'fb ads', 'google_ads' => 'GOOGLE ADS', 'tiktok_ads' => 'TIKTOK ADS',
                'seo' => 'SEO SERVICE', 'web' => 'WEBSITE', 'retainer' => 'MGMT FEE', 'other' => 'SERVICES',
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame('FB ADS', StripeService::descriptorFor('meta_ads'));
        $this->assertSame('SEO SERVICE', StripeService::descriptorFor('seo'));
    }

    public function test_invalid_text_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())->from('/settings')->post('/settings/descriptors', [
            'descriptors' => ['meta_ads' => '1234', 'google_ads' => 'X', 'tiktok_ads' => 'X', 'seo' => 'X', 'web' => 'X', 'retainer' => 'X', 'other' => 'X'],
        ])->assertSessionHasErrors('descriptors.meta_ads');
    }
}
