<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Token usage + cost (USD) per planner LLM call. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_generation_logs', function (Blueprint $table) {
            $table->unsignedInteger('prompt_tokens')->nullable()->after('duration_ms');
            $table->unsignedInteger('completion_tokens')->nullable()->after('prompt_tokens');
            $table->decimal('cost_usd', 10, 6)->nullable()->after('completion_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('agent_generation_logs', function (Blueprint $table) {
            $table->dropColumn(['prompt_tokens', 'completion_tokens', 'cost_usd']);
        });
    }
};
