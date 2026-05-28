<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('llm_models', function (Blueprint $table) {
            $table->id();
            $table->string('ollama_tag', 100)->unique();
            $table->string('display_name', 100);
            $table->string('category', 50);
            $table->text('description');
            $table->json('strengths')->nullable();
            $table->decimal('ram_required_gb', 4, 1)->default(0);
            $table->boolean('is_available')->default(false);
            $table->json('is_default_for')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('llm_models');
    }
};
