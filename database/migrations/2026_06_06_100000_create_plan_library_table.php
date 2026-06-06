<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan library — the planner's long-term memory. One row per flow holds the
 * latest APPROVED plan (saving the graph = approval). A row becomes a few-shot
 * candidate ('proven') only after the flow completes a successful run; QA
 * scores and run counts rank candidates during retrieval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_library', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();

            // The planner's understanding + the approved pipeline.
            $table->json('intent');
            $table->json('agents');

            // Denormalized intent fingerprint for cheap similarity retrieval.
            $table->string('deliverable', 40)->index();
            $table->string('language', 10)->default('bg');
            $table->string('complexity', 20)->nullable();
            $table->json('information_sources');
            $table->boolean('needs_image')->default(false);
            $table->boolean('needs_hashtags')->default(false);
            $table->boolean('competitor_focus')->default(false);
            $table->boolean('improvement_suggestions')->default(false);

            // Outcome tracking: candidate → proven after the first successful run.
            $table->string('status', 20)->default('candidate')->index();
            $table->unsignedInteger('runs_count')->default(0);
            $table->unsignedTinyInteger('avg_qa_score')->nullable();
            $table->timestamp('last_run_at')->nullable();

            $table->timestamps();
            $table->unique('flow_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_library');
    }
};
