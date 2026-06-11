<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained()->cascadeOnDelete();
            // One conversation thread = one session uuid; "Нов разговор" starts a fresh one.
            $table->uuid('session');
            $table->string('role', 16); // user | assistant
            $table->longText('content')->nullable();
            // Graph operations proposed by the assistant (applied client-side, saved by the user).
            $table->json('ops')->nullable();
            // UI actions (open_node, ...) executed client-side on arrival.
            $table->json('ui')->nullable();
            $table->string('status', 16)->default('completed'); // pending | completed | failed
            $table->text('error')->nullable();
            $table->decimal('cost_usd', 10, 6)->nullable();
            $table->timestamps();

            $table->index(['flow_id', 'session']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_messages');
    }
};
