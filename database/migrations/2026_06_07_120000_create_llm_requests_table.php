<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-call audit of every PAID LLM request (OpenAI / Anthropic).
 *
 * One row per API call — chat, structured JSON, embedding, AND each retry —
 * written at the provider chokepoints (OpenAiChatService / AnthropicChatService).
 * This is the single source of truth for the admin "Разходи" page: full prompt
 * + response, exact token counts, USD cost, and the context (company / flow /
 * run / node / agent / purpose) the call was made for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_requests', function (Blueprint $table) {
            $table->id();

            $table->string('provider', 20);              // openai | anthropic
            $table->string('model', 100);
            $table->string('kind', 20)->default('chat'); // chat | chat_json | embedding
            $table->string('purpose', 60)->nullable();   // runtime | planner:<phase> | agent_revision | embedding

            // Context — what the call was made for (all best-effort, nullable).
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('flow_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('flow_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('node_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('agent_name')->nullable();
            $table->string('agent_type', 60)->nullable();

            // Content — exactly what went in and came out.
            $table->longText('system_prompt')->nullable();
            $table->longText('user_message')->nullable();
            $table->longText('response_text')->nullable();
            $table->json('options')->nullable();

            // Metrics.
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->unsignedBigInteger('duration_ms')->nullable();

            $table->string('status', 20)->default('completed'); // completed | failed
            $table->text('error')->nullable();

            $table->timestamps();

            $table->index('created_at');
            $table->index('provider');
            $table->index('company_id');
            $table->index('flow_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_requests');
    }
};
