<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Клиентски портал ревизия: предложението става първокласен обект (brief + решение),
 * а персоната носи стабилни умения, отделни от задачите.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_tasks', function (Blueprint $table) {
            // Структуриран бриф на предложението (rationale/steps/tools/estimated_cost/warnings),
            // за да не се извлича всеки път от prompt-овете.
            $table->json('proposal')->nullable()->after('kpi');
            // Решение (одобри/откажи) — durable следа извън org_events.
            $table->timestamp('approved_at')->nullable()->after('proposal');
            $table->unsignedBigInteger('approved_by')->nullable()->after('approved_at');
            $table->timestamp('rejected_at')->nullable()->after('approved_by');
            $table->unsignedBigInteger('rejected_by')->nullable()->after('rejected_at');
            $table->text('rejection_reason')->nullable()->after('rejected_by');
        });

        Schema::table('personas', function (Blueprint $table) {
            // Стабилни компетентности на служителя (≠ задачи). Генерирани при дизайн.
            $table->json('skills')->nullable()->after('traits');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_tasks', function (Blueprint $table) {
            $table->dropColumn([
                'proposal', 'approved_at', 'approved_by',
                'rejected_at', 'rejected_by', 'rejection_reason',
            ]);
        });

        Schema::table('personas', function (Blueprint $table) {
            $table->dropColumn('skills');
        });
    }
};
