<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_chat_id')->constrained('member_chats')->cascadeOnDelete();
            $table->string('role');                          // user | assistant
            $table->text('content')->nullable();
            $table->json('payload')->nullable();             // предложено действие → за Кутията
            $table->string('status')->default('completed');  // pending | completed | failed
            $table->text('error')->nullable();
            $table->decimal('cost_usd', 10, 6)->nullable();
            $table->timestamps();
            $table->index('member_chat_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_messages');
    }
};
