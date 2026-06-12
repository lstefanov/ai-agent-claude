<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cached per-page LLM summaries for the deep_researcher map phase. The
        // summary prompt is fixed and task-agnostic, so (page content, model,
        // token budget) fully determines the digest — unchanged pages skip
        // every map LLM call on re-runs.
        Schema::create('web_page_digests', function (Blueprint $table) {
            $table->id();
            $table->char('url_hash', 64);
            $table->char('content_hash', 64);  // sha256 of the page markdown
            $table->char('params_hash', 64);   // sha256 of model|maxTokens|PROMPT_VERSION
            $table->string('model', 100);
            $table->mediumText('digest');
            $table->unsignedInteger('hit_count')->default(0);
            $table->timestamps();

            $table->unique(['url_hash', 'content_hash', 'params_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_page_digests');
    }
};
