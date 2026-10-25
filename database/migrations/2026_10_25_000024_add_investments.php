<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Investments (land, a business share, shares, FDR…) are accounts of kind "investment": money
 * moved in is still mine, just in another form. They keep what kind they are, the return I expect,
 * when they mature, what they are worth now; returns paid out point back to them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('inv_type', 20)->nullable()->after('kind'); // land | business | shares | fdr | dps | sanchayapatra | gold | crypto | other
            $table->decimal('expected_rate', 6, 2)->nullable()->after('inv_type'); // % a year
            $table->date('maturity_on')->nullable()->after('expected_rate');
            $table->bigInteger('current_value')->nullable()->after('maturity_on'); // what it is worth now, if not what went in
            $table->timestamp('value_at')->nullable()->after('current_value');
        });
        Schema::table('money_transactions', function (Blueprint $table) {
            $table->foreignId('investment_id')->nullable()->after('bill_id')->constrained('accounts')->nullOnDelete(); // a return paid by this investment
        });
    }

    public function down(): void
    {
        Schema::table('money_transactions', fn (Blueprint $table) => $table->dropConstrainedForeignId('investment_id'));
        Schema::table('accounts', fn (Blueprint $table) => $table->dropColumn(['inv_type', 'expected_rate', 'maturity_on', 'current_value', 'value_at']));
    }
};
