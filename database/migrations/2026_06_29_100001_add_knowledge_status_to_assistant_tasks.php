<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Гейт по знание (§2-етапни задачи): задача не стига до FlowRun, ако изисква
        // частна фирмена информация, която липсва. knowledge_status води бутона в UI;
        // 'unknown' е и backfill-ът — съществуващите (грешно) ready задачи се пре-оценяват
        // при първи екран/run (корекция на новия invariant, НЕ legacy fallback).
        Schema::table('assistant_tasks', function (Blueprint $table) {
            $table->string('knowledge_status', 20)->default('unknown')->after('status'); // unknown | ready | needs_knowledge
            $table->timestamp('knowledge_evaluated_at')->nullable()->after('knowledge_status'); // TTL гард за re-evaluate
            $table->index('knowledge_status');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_tasks', function (Blueprint $table) {
            $table->dropIndex(['knowledge_status']);
            $table->dropColumn(['knowledge_status', 'knowledge_evaluated_at']);
        });
    }
};
