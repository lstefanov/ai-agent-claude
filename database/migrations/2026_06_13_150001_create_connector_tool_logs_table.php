<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Одит на всеки MCP tool call — append-only. Пази САМО sanitize-нати params
 * (без tokens/secrets) и кратко result_summary (без съдържанието на write
 * операции). Connector/run/node FK-овете са nullOnDelete, за да оцелее логът
 * след изтриване на конектор или run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connector_tool_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connector_id')->nullable()->constrained('company_connectors')->nullOnDelete();
            $table->foreignId('flow_run_id')->nullable()->constrained('flow_runs')->nullOnDelete();
            $table->foreignId('node_run_id')->nullable()->constrained('node_runs')->nullOnDelete();
            $table->string('tool', 100)->nullable();  // 'gmail.send_email', 'notion.create_page'…
            $table->json('params')->nullable();        // входните параметри (sanitize-нати)
            $table->string('status', 20)->nullable();  // ok | error | skipped
            $table->text('result_summary')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();

            $table->index('flow_run_id');
            $table->index(['company_id', 'connector_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_tool_logs');
    }
};
