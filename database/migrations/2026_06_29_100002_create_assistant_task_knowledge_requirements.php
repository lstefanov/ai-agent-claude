<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Изискванията за знание на една задача (dedicated table → audit, evidence,
        // resolved state, повторни проверки). Планерът ПРЕДЛАГА (sourceability,
        // how_to_provide), КОДЪТ ГАРАНТИРА (key, status, best_score, evidence_sources).
        Schema::create('assistant_task_knowledge_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assistant_task_id')->constrained('assistant_tasks')->cascadeOnDelete();
            // key се derive-ва от КОДА (НЕ LLM): Str::slug(label).'-'.substr(sha1(query),0,8) — upsert стабилност
            $table->string('key');
            $table->string('label');
            $table->text('query');                          // подава се на KnowledgeService::search()
            $table->string('sourceability', 12);            // private | public | existing
            $table->string('status', 12)->default('missing'); // covered | partial | missing — КОДЪТ го поставя
            $table->float('best_score')->nullable();
            // typed refs (facts нямат resource_id!): [{kind, resource_id?, page_id?, fact_id?, score}]
            $table->json('evidence_sources')->nullable();
            $table->text('how_to_provide')->nullable();      // markdown инструкции за Управителя (private/existing)
            $table->boolean('acknowledged')->default(false); // публична липса, разрешена от Управителя
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            // Изричен кратък индекс-name — автоматичният надхвърля 64-знаковия лимит на MySQL.
            $table->unique(['assistant_task_id', 'key'], 'atkr_task_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_task_knowledge_requirements');
    }
};
