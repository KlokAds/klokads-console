<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Which of my accounts the card Meta charges for a client belongs to; each charge is spending there (once). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_clients', function (Blueprint $table) {
            $table->foreignId('ad_card_account_id')->nullable()->after('ad_meta_rate')->constrained('accounts')->nullOnDelete();
        });
        Schema::table('personal_ad_charges', function (Blueprint $table) {
            $table->foreignId('money_transaction_id')->nullable()->after('personal_ad_bill_id')->constrained('money_transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('personal_ad_charges', function (Blueprint $table) {
            $table->dropConstrainedForeignId('money_transaction_id');
        });
        Schema::table('personal_clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ad_card_account_id');
        });
    }
};
