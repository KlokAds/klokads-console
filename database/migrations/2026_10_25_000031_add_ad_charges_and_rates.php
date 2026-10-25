<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ads bills in taka at the agency's dollar rate: what Meta charged my card (or what the ads spent)
 * in the account's currency → dollars (BDT ÷ Meta's rate, e.g. 119) → × KlokOne's dollar rate
 * → + my commission. Some clients are billed by each card charge rather than by spend.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_clients', function (Blueprint $table) {
            $table->string('ad_bill_basis', 8)->default('spend'); // spend (days) | charges (each card charge)
            $table->decimal('ad_meta_rate', 8, 2)->default(119); // BDT per dollar when Meta charges in taka
        });

        Schema::create('personal_ad_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personal_client_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('currency', 3);
            $table->bigInteger('amount'); // what Meta took from my card, cents
            $table->string('note')->nullable();
            $table->foreignId('personal_ad_bill_id')->nullable()->constrained()->nullOnDelete(); // billed once
            $table->timestamps();
        });

        Schema::table('personal_ad_bills', function (Blueprint $table) {
            $table->string('basis', 8)->default('spend')->after('spend');
            $table->bigInteger('usd')->nullable()->after('basis'); // the dollars billed, cents
            $table->decimal('fx_rate', 10, 4)->nullable()->after('usd'); // KlokOne's BDT per dollar used
        });
    }

    public function down(): void
    {
        Schema::table('personal_ad_bills', function (Blueprint $table) {
            $table->dropColumn(['basis', 'usd', 'fx_rate']);
        });
        Schema::dropIfExists('personal_ad_charges');
        Schema::table('personal_clients', function (Blueprint $table) {
            $table->dropColumn(['ad_bill_basis', 'ad_meta_rate']);
        });
    }
};
