<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Commission only" billing: the client pays the ad platform themselves and we bill
 * just our commission on that spend. The spend is kept for the record.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private array $tables = ['invoices', 'recurring_plans', 'payment_links'];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->bigInteger('client_spend')->nullable()->after('ad_budget');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('client_spend');
            });
        }
    }
};
