<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** How an invoice went out (email or WhatsApp) and to whom — shown on the invoice. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_invoices', function (Blueprint $table) {
            $table->string('sent_via', 10)->nullable()->after('sent_at');
            $table->string('sent_to')->nullable()->after('sent_via');
        });
    }

    public function down(): void
    {
        Schema::table('personal_invoices', function (Blueprint $table) {
            $table->dropColumn(['sent_via', 'sent_to']);
        });
    }
};
