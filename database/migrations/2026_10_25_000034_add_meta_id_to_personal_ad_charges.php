<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Meta's own transaction id for a card charge read from the ad account's activity log: never added twice. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_ad_charges', function (Blueprint $table) {
            $table->string('meta_id', 80)->nullable()->unique()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('personal_ad_charges', function (Blueprint $table) {
            $table->dropUnique(['meta_id']);
            $table->dropColumn('meta_id');
        });
    }
};
