<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two accounts in the same wallet app (e.g. two nSave): one in the main app, one in the phone's
 * App Clone. The phone says which copy a notification came from; this says which copy is which.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('app_profile', 10)->nullable()->after('last4'); // main | clone
        });
    }

    public function down(): void
    {
        Schema::table('accounts', fn (Blueprint $table) => $table->dropColumn('app_profile'));
    }
};
