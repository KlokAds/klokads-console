<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keep in touch: Ma every day, Shoshur every week… When it has been too long, a reminder to
 * call — spread over the day at a time that suits each person (or the time I usually call them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('touches', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('emoji', 16)->nullable();
            $table->string('phone', 30)->nullable();
            $table->unsignedSmallInteger('every_days')->default(7);
            $table->string('window', 10)->default('evening'); // morning | afternoon | evening | night | auto
            $table->timestamp('last_talked_at')->nullable();
            $table->json('talk_hours')->nullable(); // when I usually talk to them (hour => count)
            $table->date('planned_on')->nullable(); // the day today's reminder was planned
            $table->timestamp('remind_at')->nullable();
            $table->unsignedTinyInteger('reminded')->default(0);
            $table->boolean('paused')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('touches');
    }
};
