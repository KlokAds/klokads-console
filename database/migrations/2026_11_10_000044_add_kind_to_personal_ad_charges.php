<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** charge (my card paid it) · credit (Meta's coupon: shown, never billed) · refund (back to my card). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_ad_charges', function (Blueprint $table) {
            $table->string('kind', 10)->default('charge')->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('personal_ad_charges', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
