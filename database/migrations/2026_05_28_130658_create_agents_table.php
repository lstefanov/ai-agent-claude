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
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 50);
            $table->text('role');
            $table->json('capabilities')->nullable();
            $table->text('strengths')->nullable();
            $table->text('limitations')->nullable();
            $table->text('input_description')->nullable();
            $table->text('output_description')->nullable();
            $table->longText('prompt_template');
            $table->string('model', 100);
            $table->text('model_reason')->nullable();
            $table->unsignedSmallInteger('order')->default(0);
            $table->boolean('is_verifier')->default(false);
            $table->unsignedTinyInteger('qa_threshold')->nullable();
            $table->json('depends_on')->nullable();
            $table->json('config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
