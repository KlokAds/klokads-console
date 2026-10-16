<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Our own client-facing invoice page (/i/{token}) and our own emails.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('public_token', 64)->nullable()->unique();
            $table->timestamp('invoice_sent_at')->nullable();
            $table->timestamp('receipt_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn(['public_token', 'invoice_sent_at', 'receipt_sent_at']);
        });
    }
};
