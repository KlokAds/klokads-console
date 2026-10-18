<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('purpose', 10)->default('spending')->after('kind'); // spending | savings
        });

        // Monthly spending limit per category and currency (personal).
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->string('category', 40);
            $table->string('currency', 3);
            $table->bigInteger('amount');
            $table->timestamps();
            $table->unique(['category', 'currency']);
        });

        // Something to save up for; progress is the balance of its savings account.
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('target');
            $table->date('due_on')->nullable();
            $table->boolean('done')->default(false);
            $table->timestamps();
        });

        // Things paid every month (rent, EMI, family, internet…).
        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('category', 40)->default('bills');
            $table->bigInteger('amount');
            $table->unsignedTinyInteger('day')->default(1);
            $table->string('counterparty')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::table('money_transactions', function (Blueprint $table) {
            $table->foreignId('bill_id')->nullable()->after('payout_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('money_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bill_id');
        });
        Schema::dropIfExists('bills');
        Schema::dropIfExists('goals');
        Schema::dropIfExists('budgets');
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('purpose');
        });
    }
};
