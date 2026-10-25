<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a wallet keeps when money goes in or out: e.g. nSave → Binance 0.6% (1000 arrives as
 * 994), Rizon $1 per withdrawal. A move works the fee out from these and books it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->decimal('in_fee_pct', 6, 3)->default(0)->after('app_profile');
            $table->bigInteger('in_fee_fixed')->default(0)->after('in_fee_pct'); // cents
            $table->decimal('out_fee_pct', 6, 3)->default(0)->after('in_fee_fixed');
            $table->bigInteger('out_fee_fixed')->default(0)->after('out_fee_pct'); // cents
        });
    }

    public function down(): void
    {
        Schema::table('accounts', fn (Blueprint $table) => $table->dropColumn(['in_fee_pct', 'in_fee_fixed', 'out_fee_pct', 'out_fee_fixed']));
    }
};
