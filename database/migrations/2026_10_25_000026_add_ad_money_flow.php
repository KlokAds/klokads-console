<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The prepaid ad money flow: the client's card is charged in the ad account's currency (Stripe),
 * the payout lands in Airwallex, and Meta charges the Airwallex card. Plus the account's full
 * spend history, imported a month at a time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('currency', 3)->nullable()->after('billing_mode'); // charge in this (the ad account's) currency
            $table->date('history_from')->nullable()->after('spend_billed_until'); // first day the account spent
            $table->date('history_cursor')->nullable()->after('history_from'); // imported back to this day
        });

        Schema::create('airwallex_entries', function (Blueprint $table) {
            $table->id();
            $table->string('airwallex_id', 80)->unique();
            $table->string('kind', 12); // deposit | meta | card | payout | fee | other
            $table->bigInteger('amount'); // cents; money in is positive
            $table->string('currency', 3);
            $table->string('description')->nullable();
            $table->string('status', 20)->nullable();
            $table->timestamp('occurred_at');
            $table->foreignId('payout_id')->nullable()->constrained()->nullOnDelete(); // the Stripe payout this deposit is
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete(); // whose ads a Meta charge paid for
            $table->json('raw')->nullable();
            $table->timestamps();
            $table->index(['kind', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('airwallex_entries');
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['currency', 'history_from', 'history_cursor']);
        });
    }
};
