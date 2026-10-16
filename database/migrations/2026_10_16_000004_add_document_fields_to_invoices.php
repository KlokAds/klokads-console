<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What our own invoice/receipt PDFs need: line items and tax from Stripe,
 * optional ad details (account ID, period, campaigns), the template, and payment info.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->bigInteger('subtotal')->nullable()->after('total');
            $table->bigInteger('tax_amount')->default(0)->after('subtotal');
            $table->json('lines')->nullable();
            $table->json('details')->nullable();
            $table->string('template')->nullable();
            $table->string('paid_with')->nullable();
            $table->string('charge_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['subtotal', 'tax_amount', 'lines', 'details', 'template', 'paid_with', 'charge_id']);
        });
    }
};
