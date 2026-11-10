<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** An auto entry is mine once I approve it (or it is like ones I approved before); what came before is approved. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_messages', function (Blueprint $table) {
            $table->timestamp('confirmed_at')->nullable()->after('note');
        });
        DB::table('inbox_messages')->whereIn('status', ['added', 'matched'])->update(['confirmed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('inbox_messages', function (Blueprint $table) {
            $table->dropColumn('confirmed_at');
        });
    }
};
