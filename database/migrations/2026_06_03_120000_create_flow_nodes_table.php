<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained()->cascadeOnDelete();
            $table->string('node_key');              // stable Drawflow id, referenced by edges
            $table->string('name');
            $table->string('type', 50);              // from config/agent_types.php
            $table->string('icon')->nullable();
            $table->longText('prompt_template')->nullable();
            $table->text('system_prompt')->nullable();
            $table->string('model', 100)->nullable();
            $table->json('config')->nullable();      // temperature, qa policy, sub-agent options
            $table->string('output_language')->nullable();
            $table->string('output_tone')->nullable();
            $table->string('output_style')->nullable();
            $table->string('output_format')->nullable();
            $table->string('output_role')->nullable();
            $table->integer('pos_x')->default(0);
            $table->integer('pos_y')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['flow_id', 'node_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_nodes');
    }
};
