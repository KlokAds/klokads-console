<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('scope', 10)->index(); // business | personal
            $table->string('kind', 10)->default('bank'); // bank | wallet | cash | card
            $table->string('currency', 3);
            $table->bigInteger('opening_balance')->default(0);
            $table->decimal('opening_rate', 18, 8)->nullable(); // reporting currency per 1 unit
            $table->string('notes', 500)->nullable();
            $table->boolean('archived')->default(false);
            $table->timestamps();
        });

        Schema::create('money_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10)->index(); // income | expense | transfer
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('to_account_id')->nullable()->constrained('accounts')->cascadeOnDelete();
            $table->bigInteger('amount'); // in the account's currency
            $table->bigInteger('to_amount')->nullable(); // what arrived, in the receiving account's currency
            $table->bigInteger('fee')->default(0); // bank/transfer fee, in the account's currency
            $table->bigInteger('base_amount')->default(0); // value in the reporting currency
            $table->string('category', 40)->nullable()->index();
            $table->string('counterparty')->nullable(); // where / to whom / from whom
            $table->string('description', 500)->nullable(); // purpose
            $table->date('occurred_on')->index();
            $table->foreignId('expense_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fee_expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->foreignId('payout_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('money_transactions');
        Schema::dropIfExists('accounts');
    }
};
