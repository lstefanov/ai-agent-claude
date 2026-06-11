<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained()->cascadeOnDelete();
            // The run that produced the entry — kept for traceability, nulled
            // when old runs are pruned so memory outlives its source run.
            $table->foreignId('flow_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('node_key')->nullable();
            $table->string('kind', 20); // 'output' | 'lesson'
            $table->string('title', 300)->nullable();
            $table->text('summary');
            $table->json('embedding')->nullable();
            // Vectors from different providers/models have different dimensions
            // and are NOT comparable — similarity only runs within one tag.
            $table->string('embedding_provider', 100)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['flow_id', 'kind']);
            $table->index(['flow_id', 'node_key', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_memories');
    }
};
