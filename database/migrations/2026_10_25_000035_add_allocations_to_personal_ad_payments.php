<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Which ads bills each client payment paid, and how much on each — shown with the payment. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_ad_payments', function (Blueprint $table) {
            $table->json('allocations')->nullable()->after('applied'); // [{invoice_id, amount}]
        });
    }

    public function down(): void
    {
        Schema::table('personal_ad_payments', function (Blueprint $table) {
            $table->dropColumn('allocations');
        });
    }
};
