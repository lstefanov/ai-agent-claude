<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // origin: разграничава ръчен (човек) от автономен (директор/ревю/scheduled/ignition)
        // от системен разход → дневният автономен таван брои САМО origin=autonomous.
        Schema::table('credit_reservations', function (Blueprint $table) {
            $table->string('origin', 16)->default('manual')->after('context_type'); // manual | autonomous | system
        });
        Schema::table('credit_ledger', function (Blueprint $table) {
            $table->string('origin', 16)->default('manual')->after('type');
            $table->index(['company_id', 'origin', 'created_at']);
        });

        // Cadence-троттъл за директорското „мислене" (maybePropose) — детерминистично.
        Schema::table('directors', function (Blueprint $table) {
            $table->timestamp('last_proposed_at')->nullable()->after('priorities');
        });

        // Per-company opt-in за реални действия (act-mode). Глобалният ORG_ACT_ENABLED е
        // master; тук е фирменото включване (предусловие: поне един active конектор).
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('act_enabled')->default(false)->after('settings');
        });
    }

    public function down(): void
    {
        Schema::table('credit_reservations', function (Blueprint $table) {
            $table->dropColumn('origin');
        });
        Schema::table('credit_ledger', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'origin', 'created_at']);
            $table->dropColumn('origin');
        });
        Schema::table('directors', function (Blueprint $table) {
            $table->dropColumn('last_proposed_at');
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('act_enabled');
        });
    }
};
