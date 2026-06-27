<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Чатът виси на члена (преживява версиите).
        Schema::create('member_chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('org_member_id')->constrained('org_members')->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->dateTime('last_message_at')->nullable();
            $table->timestamps();
            $table->index('org_member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_chats');
    }
};
