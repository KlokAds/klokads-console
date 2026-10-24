<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal invoices (my own work, in BDT): clients pay by bank transfer, bKash, Nagad,
 * Rocket, Upay or Bangla QR. A payment the client reports waits ("on hold") until it
 * is confirmed — by me or by a matching bank/wallet SMS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('company')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('address', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('personal_recurring', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personal_client_id')->constrained()->cascadeOnDelete();
            $table->string('currency', 3)->default('BDT');
            $table->json('items');
            $table->unsignedTinyInteger('day')->default(1); // day of month to issue
            $table->unsignedSmallInteger('due_days')->default(7);
            $table->boolean('auto_send')->default(true);
            $table->boolean('active')->default(true);
            $table->date('next_on');
            $table->string('note', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('personal_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('personal_client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personal_recurring_id')->nullable()->constrained('personal_recurring')->nullOnDelete();
            $table->string('currency', 3)->default('BDT');
            $table->json('items');
            $table->bigInteger('total');
            $table->bigInteger('paid')->default(0);
            $table->string('status', 10)->default('unpaid'); // unpaid | partial | paid | void
            $table->date('issued_on');
            $table->date('due_on')->nullable();
            $table->date('paid_on')->nullable();
            $table->string('public_token', 40)->unique();
            $table->string('note', 1000)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->json('reported')->nullable(); // what the client said they paid, waiting to be confirmed
            $table->timestamps();
        });

        Schema::create('personal_invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personal_invoice_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount');
            $table->date('paid_on');
            $table->string('method', 20); // bank | bkash | nagad | rocket | upay | qr | cash | other
            $table->string('reference')->nullable();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('money_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 10)->default('manual'); // manual | sms | email
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_invoice_payments');
        Schema::dropIfExists('personal_invoices');
        Schema::dropIfExists('personal_recurring');
        Schema::dropIfExists('personal_clients');
    }
};
