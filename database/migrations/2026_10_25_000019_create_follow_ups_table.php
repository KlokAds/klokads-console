<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Follow-ups: a WhatsApp / Messenger / IMO message I haven't seen, or a call I missed and
 * haven't returned. After an hour it reminds me who it was and what it's about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20); // whatsapp | whatsapp_business | messenger | imo | call
            $table->string('kind', 12); // message | missed_call
            $table->string('person');
            $table->string('phone', 30)->nullable();
            $table->string('key')->index(); // source + person/number: one open follow-up each
            $table->text('preview')->nullable();
            $table->unsignedSmallInteger('count')->default(1);
            $table->timestamp('first_at');
            $table->timestamp('last_at');
            $table->timestamp('seen_at')->nullable(); // opened the chat / called back
            $table->timestamp('done_at')->nullable(); // I marked it done
            $table->timestamp('remind_at')->nullable(); // next reminder
            $table->unsignedTinyInteger('reminded')->default(0);
            $table->string('note', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_ups');
    }
};
