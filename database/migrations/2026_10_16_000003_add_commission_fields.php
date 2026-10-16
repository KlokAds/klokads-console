<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ad-budget billing: what we net, the card/payout fee the client paid on top,
 * and the commission share (default 20%) of the net amount.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private array $tables = ['invoices', 'recurring_plans', 'payment_links'];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->bigInteger('net_amount')->nullable();
                $table->bigInteger('processing_fee')->nullable();
                $table->decimal('commission_rate', 5, 2)->nullable();
                $table->bigInteger('commission_amount')->nullable();
                $table->bigInteger('ad_budget')->nullable();
            });
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->decimal('commission_rate', 5, 2)->nullable()->after('country');
        });
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn(['net_amount', 'processing_fee', 'commission_rate', 'commission_amount', 'ad_budget']);
            });
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('commission_rate');
        });
    }
};
