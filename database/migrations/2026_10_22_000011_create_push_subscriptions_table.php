<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phones/browsers that get the morning and night reminders. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->string('endpoint_hash', 64)->unique();
            $table->string('public_key');
            $table->string('auth_token');
            $table->string('content_encoding', 20)->default('aes128gcm');
            $table->string('device')->nullable();
            $table->string('timezone', 64)->default('Asia/Singapore');
            $table->boolean('morning')->default(true);
            $table->string('morning_at', 5)->default('09:00');
            $table->boolean('evening')->default(true);
            $table->string('evening_at', 5)->default('21:00');
            $table->date('last_morning_on')->nullable();
            $table->date('last_evening_on')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
