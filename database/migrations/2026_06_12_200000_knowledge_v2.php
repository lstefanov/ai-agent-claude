<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Знания v2 — ресурсно-базирана база знания (NotebookLM стил).
 *
 * Пълно зануляване на v1 (документи/чанкове/пропуски + web кешовете) и нова
 * схема: РЕСУРС (url|upload|image|note) → СТРАНИЦИ (за url) → ЧАНКОВЕ, плюс
 * ФАКТИ (натрупващият се фирмен профил), СЪБИТИЯ (одит-история) и чат.
 * Без back-compat: старите данни се изхвърлят умишлено (чист старт).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Зануляване на v1 ────────────────────────────────────────────────
        Schema::dropIfExists('knowledge_chunks');
        Schema::dropIfExists('knowledge_gaps');
        Schema::dropIfExists('knowledge_documents');
        Schema::dropIfExists('web_page_digests');
        Schema::dropIfExists('web_page_cache');
        Storage::disk('local')->deleteDirectory('knowledge');

        // ── Ресурси: това, което потребителят добавя ────────────────────────
        Schema::create('knowledge_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Папките са чисто визуална организация; изтриване на папка пуска
            // ресурсите в корена, никога не ги губи.
            $table->foreignId('folder_id')->nullable()->constrained('knowledge_folders')->nullOnDelete();
            $table->string('type', 10); // url | upload | image | note
            $table->string('title', 300);
            $table->string('original_name')->nullable();
            $table->string('mime', 150)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('storage_path')->nullable();
            // Оригиналният текст на БЕЛЕЖКА (редактира се в UI → re-ingest).
            $table->mediumText('content')->nullable();
            $table->string('url', 2048)->nullable();
            // sha256 на НОРМАЛИЗИРАНИЯ URL (utf8mb4 не може unique index на 2048).
            $table->char('url_hash', 64)->nullable();
            $table->string('status', 20)->default('pending'); // pending|processing|ready|failed
            $table->text('error')->nullable();
            $table->unsignedInteger('chunk_count')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            // file_sha256 | chars | progress counters (discovered/parsed/digested)
            $table->json('meta')->nullable();
            $table->timestamp('ingested_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'type']);
            $table->index(['company_id', 'url_hash']);
        });

        // ── Страници на url ресурс (1 ред = 1 обходена страница) ───────────
        Schema::create('knowledge_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_resource_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->char('url_hash', 64);
            $table->string('title', 500)->nullable();
            $table->text('meta_description')->nullable();
            // sha256 на trim(markdown) — непроменен хеш = синтезът се преизползва.
            $table->char('content_hash', 64)->nullable();
            // LLM-синтезираното съдържание на страницата (това вижда търсенето).
            $table->mediumText('digest')->nullable();
            $table->string('status', 20)->default('pending'); // pending|ready|failed
            $table->text('error')->nullable();
            $table->timestamp('parsed_at')->nullable();
            $table->json('meta')->nullable(); // links_count | depth | digest_reused
            $table->timestamps();

            $table->index(['knowledge_resource_id', 'url_hash']);
            $table->index(['company_id', 'status']);
        });

        // ── Чанкове (търсимата единица) ─────────────────────────────────────
        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->id();
            // Денормализирано: similarity сканира ЕДНА таблица per company.
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('knowledge_resource_id')->constrained()->cascadeOnDelete();
            $table->foreignId('knowledge_page_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('seq');
            $table->text('content');
            $table->json('embedding')->nullable();
            // Вектори от различни провайдъри/модели НЕ са сравними — similarity
            // върви само в рамките на един tag ("провайдър:модел").
            $table->string('embedding_provider', 100)->nullable();
            $table->json('meta')->nullable(); // heading | sheet | section | url | title
            $table->timestamps();

            $table->index(['company_id', 'embedding_provider']);
            $table->index(['knowledge_resource_id', 'seq']);
            $table->fullText('content'); // keyword половината на hybrid търсенето
        });

        // ── Факти: натрупващият се "профил" на фирмата ──────────────────────
        Schema::create('knowledge_facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // services|prices|contacts|locations|about|team|competitors|faq|other
            $table->string('category', 30);
            // Мулти-локации ("PrimeLaser Русе/София/Варна"): фирмата е ЕДНА,
            // фактът носи локационен таг; null = важи за цялата фирма.
            $table->string('location', 150)->nullable();
            // Нормализирано име, напр. "цена лазерна епилация подмишници мъже".
            $table->string('name', 300);
            $table->text('value');
            $table->string('source_type', 20); // resource | page | run | chat
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('flow_run_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('confidence', 4, 3)->nullable(); // 0–1 от извличащия LLM
            // Ново знание за същото нещо НЕ трие старото — supersede-ва го,
            // така историята показва как се е променил фактът.
            $table->string('status', 20)->default('active'); // active|superseded
            $table->json('embedding')->nullable();
            $table->string('embedding_provider', 100)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'category', 'status']);
            $table->fullText(['name', 'value']);
        });

        // ── Събития: одит-историята "кое знание, откъде, кога" ─────────────
        Schema::create('knowledge_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('action', 20); // added | updated | deleted
            $table->string('subject_type', 20); // resource | page | fact
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('title', 300);
            // Преглед на синтезираното съдържание към момента на събитието —
            // оцелява и след изтриване на самия subject.
            $table->mediumText('snippet')->nullable();
            // Човешки източник: "качен файл", "crawl на …", "run #N — агент X".
            $table->string('source', 300)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['company_id', 'created_at']);
        });

        // ── Пропуски (v2: със статус и резолюция) ───────────────────────────
        Schema::create('knowledge_gaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('node_key')->nullable();
            $table->string('query', 500);
            $table->decimal('best_score', 5, 3)->nullable();
            $table->json('embedding')->nullable();
            $table->string('embedding_provider', 100)->nullable();
            $table->string('status', 16)->default('open'); // open | resolved
            // Какво го запълни: "fact:12" | "page:33" | "resource:5".
            $table->string('resolved_by', 100)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status', 'created_at']);
        });

        // ── Чат "Тествай знанията" (по конвенцията на assistant_messages) ──
        Schema::create('knowledge_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Една сесия = един разговор; "Нов разговор" започва нова uuid.
            $table->uuid('session');
            $table->string('role', 16); // user | assistant
            $table->longText('content')->nullable();
            // Цитираните източници [{type, id, title, url, score}, …].
            $table->json('sources')->nullable();
            $table->string('status', 16)->default('completed'); // pending|completed|failed
            $table->text('error')->nullable();
            $table->decimal('cost_usd', 10, 6)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'session']);
        });

        // ── Глобален суров кеш (БЕЗ company_id — публично уеб съдържание) ──
        Schema::create('web_page_cache', function (Blueprint $table) {
            $table->id();
            $table->char('url_hash', 64)->unique(); // sha256 на нормализирания URL
            $table->string('url', 2048);
            $table->char('content_hash', 64);       // sha256 на trim(markdown)
            $table->longText('markdown');
            $table->string('title', 500)->nullable();
            $table->text('meta_description')->nullable();
            // Вътрешните линкове от РЕНДЕРИРАНИЯ DOM — BFS кралът чете оттук.
            $table->json('links')->nullable();
            $table->timestamp('fetched_at');        // последна реална промяна
            $table->timestamp('last_checked_at')->index();
            $table->unsignedInteger('hit_count')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        // ── Глобален digest кеш: (url, съдържание, параметри) → синтез ─────
        Schema::create('web_page_digests', function (Blueprint $table) {
            $table->id();
            $table->char('url_hash', 64);
            $table->char('content_hash', 64);  // sha256 на page markdown
            $table->char('params_hash', 64);   // sha256 на model|maxTokens|PROMPT_VERSION
            $table->string('model', 100);
            $table->mediumText('digest');
            $table->unsignedInteger('hit_count')->default(0);
            $table->timestamps();

            $table->unique(['url_hash', 'content_hash', 'params_hash']);
        });
    }

    public function down(): void
    {
        foreach ([
            'knowledge_chat_messages', 'knowledge_gaps', 'knowledge_events',
            'knowledge_facts', 'knowledge_chunks', 'knowledge_pages',
            'knowledge_resources', 'web_page_digests', 'web_page_cache',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
