<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The personal side belongs to one person (the owner) and can be locked with a PIN. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_owner')->default(false)->after('email');
            $table->string('pin_hash')->nullable()->after('is_owner');
        });

        // The first account created (the installer's admin) is the owner.
        if ($first = DB::table('users')->orderBy('id')->value('id')) {
            DB::table('users')->where('id', $first)->update(['is_owner' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_owner', 'pin_hash']);
        });
    }
};
