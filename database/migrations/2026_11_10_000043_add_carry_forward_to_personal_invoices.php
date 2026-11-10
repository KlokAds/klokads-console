<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** An unpaid invoice carried into a newer one (and back): which one, and how much it brought. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_invoices', function (Blueprint $table) {
            $table->foreignId('carried_into_id')->nullable()->after('personal_recurring_id')->constrained('personal_invoices')->nullOnDelete();
            $table->bigInteger('carried_amount')->default(0)->after('carried_into_id'); // what it brought into the newer one
            $table->bigInteger('carried')->default(0)->after('total'); // previous dues it holds
        });
    }

    public function down(): void
    {
        Schema::table('personal_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('carried_into_id');
            $table->dropColumn(['carried_amount', 'carried']);
        });
    }
};
