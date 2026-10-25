<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * My own clients' ads (Edge Education, Marine Orvion…): which ad accounts or campaigns are theirs,
 * what each campaign spent and brought day by day in the account's currency, and the monthly
 * ads bill for what my card paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_clients', function (Blueprint $table) {
            $table->string('ad_report_token', 40)->nullable()->unique(); // the private report link
            $table->json('ad_campaigns')->nullable(); // id => {name, status, daily_budget, start, end, account}
            $table->boolean('ad_bill')->default(true); // a monthly bill for the ads my card paid
            $table->decimal('ad_fee_percent', 5, 2)->default(0); // added to that bill, if any
            $table->date('ad_billed_until')->nullable(); // every day up to here is billed (or settled before): never billed again
        });

        Schema::create('personal_ad_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personal_client_id')->constrained()->cascadeOnDelete();
            $table->string('account_id', 40);
            $table->string('account_name')->nullable();
            $table->string('currency', 3);
            $table->string('campaign_id', 40)->nullable(); // null: the whole account
            $table->string('campaign_name')->nullable();
            $table->string('paid_by', 10)->default('me'); // me (my card) | client (their own card)
            $table->date('from_date'); // counts from this day
            $table->timestamps();
            $table->unique(['account_id', 'campaign_id']);
        });

        Schema::create('personal_ad_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personal_client_id')->constrained()->cascadeOnDelete();
            $table->string('account_id', 40);
            $table->string('campaign_id', 40);
            $table->string('campaign_name');
            $table->date('date');
            $table->string('currency', 3);
            $table->bigInteger('spend')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('reach')->default(0);
            $table->unsignedInteger('results')->default(0);
            $table->string('result_type', 80)->nullable();
            $table->string('paid_by', 10)->default('me');
            $table->timestamps();
            $table->unique(['account_id', 'campaign_id', 'date']);
            $table->index(['personal_client_id', 'date']);
        });

        Schema::create('personal_ad_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personal_client_id')->constrained()->cascadeOnDelete();
            $table->date('period_from'); // the days this bill covers — right after the previous bill,
            $table->date('period_to');   // so no day is billed twice and none is missed
            $table->string('currency', 3);
            $table->bigInteger('spend');
            $table->foreignId('personal_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['personal_client_id', 'period_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_ad_bills');
        Schema::dropIfExists('personal_ad_days');
        Schema::dropIfExists('personal_ad_assignments');
        Schema::table('personal_clients', function (Blueprint $table) {
            $table->dropColumn(['ad_report_token', 'ad_campaigns', 'ad_bill', 'ad_fee_percent', 'ad_billed_until']);
        });
    }
};
