<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Конфликти в знанието: ≥2 АКТИВНИ факта за едно и също нещо (същата категория
 * + локация, близки по смисъл), но с РАЗЛИЧНА стойност — съжителстват, защото
 * dedup-ът (≥0.86) не ги е слял. Изваждат се в таб „Конфликти" за ръчен избор
 * на вярната стойност (другите → superseded). `signature` дедупликира
 * откриването и пази „ignored" да не се пресъздава при повторен скан.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('category', 30);
            $table->string('location', 150)->nullable();
            // Представително име на спорната тема (за заглавие на картата).
            $table->string('subject', 300);
            // Активните факти-членове на конфликта.
            $table->json('fact_ids');
            $table->string('status', 16)->default('open'); // open | resolved | ignored
            // Избраният победител при разрешаване.
            $table->unsignedBigInteger('resolved_fact_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            // sha256 на сортираните fact_ids — дедуп на откриването + „ignored" памет.
            $table->char('signature', 64)->index();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_conflicts');
    }
};
