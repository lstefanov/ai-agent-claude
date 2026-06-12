<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Folder deletion drops documents to the root — never loses them.
            $table->foreignId('folder_id')->nullable()->constrained('knowledge_folders')->nullOnDelete();
            // source_type IS the collection: grounding = upload|site|url, history = run.
            $table->string('source_type', 10)->default('upload');
            $table->string('title', 300);
            $table->string('original_name')->nullable();
            $table->string('mime', 150)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('storage_path')->nullable();
            $table->string('source_url', 2048)->nullable();
            // sha256 of the NORMALIZED source URL — the upsert key for site pages
            // (MySQL utf8mb4 cannot uniquely index a 2048-char string).
            $table->char('source_url_hash', 64)->nullable();
            $table->foreignId('flow_run_id')->nullable()->constrained()->nullOnDelete();
            // sha256 of the EXTRACTED text — unchanged hash means re-ingest can
            // skip the expensive chunk + embed pass.
            $table->char('content_hash', 64)->nullable();
            $table->string('status', 20)->default('pending'); // pending|processing|ready|failed
            $table->text('error')->nullable();
            $table->unsignedInteger('chunk_count')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->json('meta')->nullable();
            $table->timestamp('ingested_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'source_type']);
            $table->index(['company_id', 'source_url_hash']);
            $table->index(['company_id', 'content_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_documents');
    }
};
