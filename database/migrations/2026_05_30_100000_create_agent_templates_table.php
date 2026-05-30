<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description');
            $table->string('icon', 10)->default('🤖');
            $table->string('type', 50);
            $table->text('role')->nullable();
            $table->text('system_prompt')->nullable();
            $table->longText('prompt_template')->nullable();
            $table->string('model', 100)->default('');
            $table->json('capabilities')->nullable();
            $table->text('strengths')->nullable();
            $table->text('limitations')->nullable();
            $table->text('input_description')->nullable();
            $table->text('output_description')->nullable();
            $table->boolean('is_verifier')->default(false);
            $table->unsignedTinyInteger('qa_threshold')->nullable();
            $table->json('config')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_templates');
    }
};
