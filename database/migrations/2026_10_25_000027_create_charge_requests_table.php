<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approve before charging: an automatic top-up is first a draft. I get a notification, can change
 * the amount or text, and the client's card is charged only when I approve it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charge_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12)->default('top_up');
            $table->bigInteger('amount'); // what goes into the balance, cents (the card fee comes on top)
            $table->bigInteger('suggested'); // what it worked out, before any change
            $table->string('currency', 3);
            $table->string('description', 120);
            $table->string('status', 12)->default('pending'); // pending | charged | declined | failed
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('error', 500)->nullable();
            $table->json('reason')->nullable(); // balance, daily cost, days left when it was drafted
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'status']);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('approve_charges')->default(true)->after('spend_billing');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('approve_charges');
        });
        Schema::dropIfExists('charge_requests');
    }
};
