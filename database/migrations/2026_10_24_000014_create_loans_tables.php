<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Dena-paona: money lent to people and money borrowed from them, with repayments. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 10); // lent (they owe me) | borrowed (I owe them)
            $table->string('person');
            $table->string('phone', 30)->nullable();
            $table->string('currency', 3);
            $table->bigInteger('principal');
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->date('started_on');
            $table->date('due_on')->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('money_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('loan_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount');
            $table->date('paid_on');
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->foreignId('money_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_payments');
        Schema::dropIfExists('loans');
    }
};
