<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Token usage + cost (USD) per node when it runs on a paid provider (openai/*). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_runs', function (Blueprint $table) {
            $table->unsignedInteger('prompt_tokens')->nullable()->after('tokens_used');
            $table->unsignedInteger('completion_tokens')->nullable()->after('prompt_tokens');
            $table->decimal('cost_usd', 10, 6)->nullable()->after('completion_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('node_runs', function (Blueprint $table) {
            $table->dropColumn(['prompt_tokens', 'completion_tokens', 'cost_usd']);
        });
    }
};
