<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * For whom I spent it: Ma, Bou, Shoshur… (paid to Mithu, but for Ma). Lets me see what each
 * person in the family cost in any period.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('money_transactions', function (Blueprint $table) {
            $table->string('for_whom', 40)->nullable()->after('counterparty');
        });
    }

    public function down(): void
    {
        Schema::table('money_transactions', fn (Blueprint $table) => $table->dropColumn('for_whom'));
    }
};
