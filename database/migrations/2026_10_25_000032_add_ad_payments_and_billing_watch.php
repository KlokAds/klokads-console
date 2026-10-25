<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta has no charge history in its API, so each ad account's "spent in all" and "not charged yet"
 * are kept from one look to the next: when the second drops, Meta charged the card. And the
 * client's payments — any amount, early or extra — pay the oldest ads bills; the rest is credit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_account_states', function (Blueprint $table) {
            $table->id();
            $table->string('account_id', 40)->unique();
            $table->string('currency', 3);
            $table->bigInteger('amount_spent'); // all time, minor units
            $table->bigInteger('unbilled');     // spent, not charged yet
            $table->string('card')->nullable(); // "VISA *5372"
            $table->timestamp('checked_at');
            $table->timestamps();
        });

        Schema::table('personal_ad_charges', function (Blueprint $table) {
            $table->string('source', 8)->default('manual')->after('note'); // manual | auto (seen from Meta)
        });

        Schema::create('personal_ad_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personal_client_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->bigInteger('amount'); // taka, cents
            $table->bigInteger('applied')->default(0); // put on their ads bills so far; the rest is credit
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('money_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_ad_payments');
        Schema::table('personal_ad_charges', function (Blueprint $table) {
            $table->dropColumn('source');
        });
        Schema::dropIfExists('ad_account_states');
    }
};
