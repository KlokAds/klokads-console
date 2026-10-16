<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payout;
use App\Models\RecurringPlan;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Fake data for previewing the dashboard locally. Never run in production:
 *   php artisan db:seed --class=DemoSeeder
 * Wipe with: php artisan migrate:fresh
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoSeeder is for local preview only.');

            return;
        }

        User::firstOrCreate(['email' => 'demo@klokads.test'], [
            'name' => 'Demo Owner',
            'password' => bcrypt('demo-preview-only'),
            'email_verified_at' => now(),
        ]);

        $clients = collect([
            ['Lim Wei', 'Lion City Bakery Pte Ltd', 'meta_ads', 250000],
            ['Priya Nair', 'Orchard Dental Clinic', 'google_ads', 320000],
            ['Jason Tan', 'Tan Fitness Studio', 'tiktok_ads', 180000],
            ['Mei Ling', 'Marina Interiors', 'seo', 150000],
        ])->map(fn ($c) => [
            'client' => Client::create(['name' => $c[0], 'company' => $c[1], 'email' => Str::slug($c[0]).'@example.sg', 'stripe_customer_id' => 'cus_demo'.Str::random(6)]),
            'service' => $c[2],
            'fee' => $c[3],
        ]);

        foreach ($clients as $row) {
            RecurringPlan::create([
                'client_id' => $row['client']->id, 'stripe_subscription_id' => 'sub_demo'.Str::random(8),
                'description' => 'Monthly '.str($row['service'])->headline().' management', 'service' => $row['service'],
                'amount' => $row['fee'], 'currency' => 'sgd', 'interval' => 'month', 'interval_count' => 1,
                'collection' => $row['client']->id % 2 ? 'auto' : 'invoice', 'status' => 'active',
                'current_period_end' => now()->addDays(rand(2, 28)),
            ]);
        }

        for ($m = 11; $m >= 0; $m--) {
            $month = now()->subMonthsNoOverflow($m)->startOfMonth();
            foreach ($clients as $i => $row) {
                if ($m > 8 && $i === 3) {
                    continue;
                }
                $paidAt = $month->copy()->addDays(3 + $i)->setTime(10, 0);
                if ($paidAt->isFuture()) {
                    continue;
                }
                $amount = $row['fee'];
                $fee = (int) round($amount * 0.034) + 50;
                $isOpen = $m === 0 && $i === 1;

                Invoice::create([
                    'client_id' => $row['client']->id, 'stripe_invoice_id' => 'in_demo'.Str::random(10),
                    'stripe_subscription_id' => 'sub_demo', 'number' => sprintf('KA-%04d', 100 + (11 - $m) * 4 + $i),
                    'description' => str($row['service'])->headline().' management — '.$month->format('F'),
                    'service' => $row['service'], 'currency' => 'sgd', 'total' => $amount,
                    'amount_paid' => $isOpen ? 0 : $amount, 'amount_remaining' => $isOpen ? $amount : 0,
                    'status' => $isOpen ? 'open' : 'paid', 'collection_method' => 'charge_automatically',
                    'hosted_invoice_url' => 'https://invoice.stripe.com/demo', 'issued_at' => $paidAt,
                    'due_at' => $paidAt->copy()->addDays($isOpen ? -2 : 7), 'paid_at' => $isOpen ? null : $paidAt,
                ]);

                if (! $isOpen) {
                    Transaction::create([
                        'stripe_id' => 'txn_demo'.Str::random(12), 'client_id' => $row['client']->id, 'type' => 'charge',
                        'reporting_category' => 'charge', 'payment_method' => $i === 2 ? 'paynow' : 'card:visa',
                        'amount' => $amount, 'fee' => $i === 2 ? (int) round($amount * 0.013) : $fee,
                        'net' => $amount - ($i === 2 ? (int) round($amount * 0.013) : $fee), 'currency' => 'sgd',
                        'description' => 'Payment for invoice', 'status' => 'available', 'occurred_at' => $paidAt,
                    ]);
                }

                Expense::create([
                    'client_id' => $row['client']->id, 'category' => $row['service'],
                    'vendor' => ['meta_ads' => 'Meta', 'google_ads' => 'Google', 'tiktok_ads' => 'TikTok', 'seo' => 'Ahrefs'][$row['service']],
                    'amount' => (int) ($amount * (0.35 + $i * 0.05)), 'paid_via' => 'card',
                    'spent_on' => $month->copy()->addDays(12)->min(now())->toDateString(),
                ]);
            }

            Expense::create(['category' => 'software', 'vendor' => 'Google Workspace, Canva, Slack', 'amount' => 18000, 'paid_via' => 'card', 'spent_on' => $month->toDateString()]);
            Expense::create(['category' => 'salary', 'vendor' => 'Freelance designer', 'amount' => 120000, 'paid_via' => 'bank', 'spent_on' => $month->copy()->addDays(1)->min(now())->toDateString()]);

            $payoutAt = $month->copy()->addDays(15);
            if ($payoutAt->isPast()) {
                $sum = (int) Transaction::whereBetween('occurred_at', [$month, $month->copy()->endOfMonth()])->where('type', 'charge')->sum('net');
                Transaction::create([
                    'stripe_id' => 'txn_demo'.Str::random(12), 'type' => 'payout', 'reporting_category' => 'payout',
                    'amount' => -$sum, 'fee' => 0, 'net' => -$sum, 'currency' => 'sgd', 'description' => 'STRIPE PAYOUT',
                    'status' => 'available', 'occurred_at' => $payoutAt,
                ]);
                Payout::create(['stripe_payout_id' => 'po_demo'.Str::random(10), 'amount' => $sum, 'currency' => 'sgd', 'status' => 'paid', 'method' => 'standard', 'arrival_at' => $payoutAt->copy()->addDays(2), 'occurred_at' => $payoutAt]);
            }
        }
    }
}
