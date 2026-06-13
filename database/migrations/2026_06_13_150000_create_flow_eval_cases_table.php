<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Golden test cases per flow — the Eval Suite's inputs. Each case carries
     * the run input ({prompt, variables}) and a list of quality criteria
     * (llm_judge / rule / regex). Cases are evaluated against any version ×
     * model-level (see flow_eval_runs).
     */
    public function up(): void
    {
        Schema::create('flow_eval_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            // { "prompt": "...", "variables": {...} } — fed into the run seed.
            $table->json('input_data');
            // [ { key, label, description, weight, type: llm_judge|rule|regex, ... } ]
            $table->json('criteria');
            // Aggregation weight across cases in the same flow.
            $table->float('weight')->default(1.0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_eval_cases');
    }
};
