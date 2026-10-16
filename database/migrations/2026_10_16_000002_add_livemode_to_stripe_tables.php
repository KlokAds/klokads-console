<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keep Stripe test-mode and live-mode data apart, so test invoices and payments
 * never count towards real totals once a live key is connected.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private array $tables = ['invoices', 'recurring_plans', 'payment_links', 'transactions', 'payouts'];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->boolean('livemode')->default(false)->index();
            });
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->string('stripe_test_customer_id')->nullable()->index()->after('stripe_customer_id');
        });
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['livemode']);
                $table->dropColumn('livemode');
            });
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['stripe_test_customer_id']);
            $table->dropColumn('stripe_test_customer_id');
        });
    }
};
