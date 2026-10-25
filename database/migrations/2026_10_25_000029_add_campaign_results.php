<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign results, not only spend: each day's results (leads, messages…) and reach, and per
 * client the campaigns' last known delivery status and daily budget (to tell when one stops).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_spend_days', function (Blueprint $table) {
            $table->unsignedInteger('reach')->default(0)->after('clicks');
            $table->unsignedInteger('results')->default(0)->after('reach');
            $table->string('result_type', 80)->nullable()->after('results');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->json('meta_campaigns')->nullable()->after('meta_campaign_filter'); // id => {name, status, objective, daily_budget}
        });
    }

    public function down(): void
    {
        Schema::table('meta_spend_days', function (Blueprint $table) {
            $table->dropColumn(['reach', 'results', 'result_type']);
        });
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('meta_campaigns');
        });
    }
};
