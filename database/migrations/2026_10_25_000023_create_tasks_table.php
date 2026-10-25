<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** My own tasks: written down when I think of them, reminded at the time I set until done. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('note')->nullable();
            $table->timestamp('due_at')->nullable(); // when to remind me
            $table->string('repeat', 10)->default('none'); // none | daily | weekly | monthly
            $table->timestamp('remind_at')->nullable(); // next reminder
            $table->unsignedTinyInteger('reminded')->default(0); // for this due time
            $table->timestamp('done_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
