<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One evaluation of a case for a concrete version × model-level. Backed by
     * a real FlowRun (triggered_by='eval'); judged after the run finalizes.
     * session_token groups one "Стартирай Eval" batch for progress polling.
     */
    public function up(): void
    {
        Schema::create('flow_eval_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_version_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('flow_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('eval_case_id')->constrained('flow_eval_cases')->cascadeOnDelete();
            $table->string('model_level', 12)->nullable();      // low|medium|high|ultra|god
            $table->string('session_token')->nullable()->index();
            $table->string('status', 20)->default('pending');   // pending|running|completed|failed
            $table->float('score')->nullable();                 // 0–100, weighted from criteria
            $table->json('scores_detail')->nullable();          // { criterion_key => {score, reason} }
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->longText('final_output')->nullable();        // flow output for this eval
            $table->json('judge_log')->nullable();              // raw LLM-as-judge responses
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['flow_id', 'model_level', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_eval_runs');
    }
};
