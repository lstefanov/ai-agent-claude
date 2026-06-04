<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_node_id')->constrained()->cascadeOnDelete();
            $table->string('node_key');
            $table->string('status', 20)->default('pending'); // pending|running|completed|failed|skipped
            $table->longText('input')->nullable();
            $table->longText('output')->nullable();           // namespaced — never overwritten
            $table->longText('raw_output')->nullable();
            $table->json('quality_metrics')->nullable();
            $table->string('model_used', 100)->nullable();
            $table->unsignedInteger('tokens_used')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['flow_run_id', 'node_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_runs');
    }
};
