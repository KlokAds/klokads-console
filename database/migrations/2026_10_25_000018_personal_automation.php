<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The personal side runs itself: accounts are matched by their last 4 digits, every
 * automatic credit/debit and reminder lands in one notification feed (web push + the
 * phone app), monthly money is handed out in envelopes, and loans carry their fee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('last4', 4)->nullable()->after('provider'); // bank a/c, card or MFS mobile — only the last 4
            $table->bigInteger('bank_balance')->nullable()->after('notes'); // what the latest bank/wallet alert said
            $table->timestamp('bank_balance_at')->nullable()->after('bank_balance');
            $table->text('guide')->nullable()->after('bank_balance_at'); // how to withdraw to BDT (family guide)
        });

        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20); // credit | debit | reminder | invoice | info
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('url')->nullable();
            $table->string('tag', 40)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        // A reminder that must go exactly once (e.g. "loan due tomorrow · 9am").
        Schema::create('reminder_sends', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('envelopes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('emoji', 16)->nullable();
            $table->string('currency', 3)->default('BDT');
            $table->bigInteger('amount'); // per month
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete(); // where the money comes from
            $table->json('purposes')->nullable();
            $table->unsignedTinyInteger('day')->default(1); // day of the month it is handed out
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('envelope_months', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->string('month', 7); // YYYY-MM
            $table->bigInteger('assigned');
            $table->date('assigned_on');
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('money_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['envelope_id', 'month']);
        });

        Schema::create('envelope_spends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->string('month', 7);
            $table->string('purpose');
            $table->bigInteger('amount');
            $table->date('spent_on');
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::table('loans', function (Blueprint $table) {
            $table->bigInteger('fee')->default(0)->after('principal'); // sending fee, paid by me
        });

        Schema::table('personal_recurring', function (Blueprint $table) {
            $table->date('start_on')->nullable()->after('day'); // when the job started — sets the pay day and period
            $table->unsignedTinyInteger('lead_days')->default(2)->after('due_days'); // invoice is made this many days before pay day
        });

        Schema::table('personal_invoices', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('personal_invoices', fn (Blueprint $table) => $table->dropColumn('reminded_at'));
        Schema::table('personal_recurring', fn (Blueprint $table) => $table->dropColumn(['start_on', 'lead_days']));
        Schema::table('loans', fn (Blueprint $table) => $table->dropColumn('fee'));
        Schema::dropIfExists('envelope_spends');
        Schema::dropIfExists('envelope_months');
        Schema::dropIfExists('envelopes');
        Schema::dropIfExists('reminder_sends');
        Schema::dropIfExists('app_notifications');
        Schema::table('accounts', fn (Blueprint $table) => $table->dropColumn(['last4', 'bank_balance', 'bank_balance_at', 'guide']));
    }
};
