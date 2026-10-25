<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A prepaid client's statement for a period (a month, usually): opening balance, top-ups, what each
 * campaign spent, closing balance. Drafted automatically; emailed only when I press Send.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->json('data');
            $table->boolean('show_fee')->default(true); // the service fee on its own line, or folded into each campaign
            $table->string('status', 10)->default('draft'); // draft | sent
            $table->string('sent_to')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['client_id', 'period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_statements');
    }
};
