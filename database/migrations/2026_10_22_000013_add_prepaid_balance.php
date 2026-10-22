<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prepaid balance: the client keeps funds with us; each finished day's Meta spend
 * (+ commission) is taken from it, and it is topped up automatically before it runs
 * low — early enough that Stripe's ~7-day payout lands before we need the money.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('billing_mode', 10)->default('postpaid')->after('spend_billing'); // postpaid | prepaid
            $table->unsignedSmallInteger('prepaid_buffer_days')->default(10)->after('billing_mode');
            $table->unsignedSmallInteger('prepaid_topup_days')->default(21)->after('prepaid_buffer_days');
            $table->bigInteger('prepaid_min_topup')->default(50000)->after('prepaid_topup_days');
        });

        Schema::create('client_balance_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10); // top_up | spend | manual
            $table->bigInteger('amount'); // + adds to the balance, − takes from it
            $table->bigInteger('spend')->nullable(); // spend days: Meta spend
            $table->bigInteger('commission')->nullable(); // spend days: our commission on it
            $table->date('date')->index();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['client_id', 'kind', 'date', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_balance_entries');
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['billing_mode', 'prepaid_buffer_days', 'prepaid_topup_days', 'prepaid_min_topup']);
        });
    }
};
