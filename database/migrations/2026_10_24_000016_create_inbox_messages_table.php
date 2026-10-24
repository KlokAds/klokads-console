<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Bank / wallet SMS and email alerts, read into money entries automatically. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_messages', function (Blueprint $table) {
            $table->id();
            $table->string('source', 10); // sms | email
            $table->string('sender')->nullable();
            $table->text('body');
            $table->string('hash', 64)->unique(); // same alert twice → kept once
            $table->timestamp('received_at')->nullable();
            $table->json('parsed')->nullable(); // direction, amount, currency, provider, counterparty, reference, fee
            $table->string('status', 12)->default('new'); // added | matched | review | ignored | skipped
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('money_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('personal_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_messages');
    }
};
