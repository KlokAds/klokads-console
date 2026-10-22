<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spend billing: read a client's Meta campaign spend; once it passes the threshold
 * (e.g. 500), charge their saved card for the spend plus commission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('meta_ad_account_id', 40)->nullable()->after('tax_id');
            $table->string('meta_campaign_filter', 120)->nullable()->after('meta_ad_account_id'); // only campaigns whose name contains this
            $table->boolean('spend_billing')->default(false)->after('meta_campaign_filter');
            $table->bigInteger('spend_threshold')->default(50000)->after('spend_billing');
            $table->date('spend_billed_until')->nullable()->after('spend_threshold'); // spend up to this day is settled
            $table->timestamp('spend_synced_at')->nullable()->after('spend_billed_until');
            $table->timestamp('spend_attempted_at')->nullable()->after('spend_synced_at');
            $table->string('spend_error', 500)->nullable()->after('spend_attempted_at');
        });

        Schema::create('meta_spend_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('campaign_id', 40);
            $table->string('campaign_name');
            $table->date('date');
            $table->bigInteger('spend'); // cents, in the ad account's currency
            $table->string('currency', 3);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete(); // set once billed
            $table->timestamps();
            $table->unique(['client_id', 'campaign_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_spend_days');
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['meta_ad_account_id', 'meta_campaign_filter', 'spend_billing', 'spend_threshold', 'spend_billed_until', 'spend_synced_at', 'spend_attempted_at', 'spend_error']);
        });
    }
};
