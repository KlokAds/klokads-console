<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Invoices of our own: no Stripe invoice behind them, only the card payment (PaymentIntent) that pays one. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('stripe_invoice_id')->nullable()->change();
            $table->string('stripe_payment_intent_id')->nullable()->index()->after('stripe_subscription_id');
            $table->string('statement_descriptor', 22)->nullable()->after('service');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['stripe_payment_intent_id', 'statement_descriptor']);
        });
    }
};
