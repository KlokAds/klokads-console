<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('company')->nullable();
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('country', 2)->default('SG');
            $table->string('stripe_customer_id')->nullable()->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('stripe_invoice_id')->unique();
            $table->string('stripe_subscription_id')->nullable()->index();
            $table->string('number')->nullable();
            $table->string('description')->nullable();
            $table->string('service')->nullable();
            $table->string('currency', 3);
            $table->bigInteger('total')->default(0);
            $table->bigInteger('amount_paid')->default(0);
            $table->bigInteger('amount_remaining')->default(0);
            $table->string('status');
            $table->string('collection_method')->nullable();
            $table->string('hosted_invoice_url')->nullable();
            $table->string('invoice_pdf')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('recurring_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_subscription_id')->unique();
            $table->string('description');
            $table->string('service')->nullable();
            $table->bigInteger('amount');
            $table->string('currency', 3);
            $table->string('interval');
            $table->unsignedSmallInteger('interval_count')->default(1);
            $table->string('collection');
            $table->string('status');
            $table->timestamp('current_period_end')->nullable();
            $table->string('first_invoice_url')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('stripe_payment_link_id')->unique();
            $table->string('url');
            $table->string('description');
            $table->string('service')->nullable();
            $table->bigInteger('amount');
            $table->string('currency', 3);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_id')->unique();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('reporting_category')->nullable()->index();
            $table->string('payment_method')->nullable();
            $table->bigInteger('amount');
            $table->bigInteger('fee')->default(0);
            $table->bigInteger('net');
            $table->string('currency', 3);
            $table->string('description')->nullable();
            $table->string('source_id')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('available_on')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });

        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_payout_id')->unique();
            $table->bigInteger('amount');
            $table->string('currency', 3);
            $table->string('status');
            $table->string('method')->nullable();
            $table->string('destination')->nullable();
            $table->timestamp('arrival_at')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category')->index();
            $table->string('vendor')->nullable();
            $table->string('description')->nullable();
            $table->bigInteger('amount');
            $table->bigInteger('original_amount')->nullable();
            $table->string('original_currency', 3)->nullable();
            $table->string('paid_via')->nullable();
            $table->string('reference')->nullable();
            $table->date('spent_on')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['expenses', 'payouts', 'transactions', 'payment_links', 'recurring_plans', 'invoices', 'clients', 'settings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
