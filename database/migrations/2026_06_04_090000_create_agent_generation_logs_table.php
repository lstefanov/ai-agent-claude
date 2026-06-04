<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_generation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('token')->nullable()->index();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->longText('system_prompt')->nullable();
            $table->longText('user_message')->nullable();
            $table->json('options')->nullable();
            $table->longText('raw_response')->nullable();
            $table->integer('parsed_count')->nullable();
            $table->string('status')->default('running');
            $table->text('error')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_generation_logs');
    }
};
