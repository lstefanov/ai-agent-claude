<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_draft_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_draft_id')->constrained()->cascadeOnDelete();
            $table->string('role'); // user | assistant
            $table->text('content')->nullable();
            $table->json('payload')->nullable(); // структурираният въпрос ИЛИ отговорът на клиента
            $table->string('status')->default('completed'); // pending | completed | failed
            $table->text('error')->nullable();
            $table->decimal('cost_usd', 10, 5)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_draft_messages');
    }
};
