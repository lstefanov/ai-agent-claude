<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Схема за точна и обяснима кредитна история + per-company статистика:
 *  - credit_reservations: явно billing ниво, lifecycle timestamps, изход, snapshot на тарифата
 *  - credit_ledger: записан баланс след всеки ред (точен running balance без window изчисления)
 *  - llm_requests: operation_id (групира редовете на една операция дори без резервация) + индекси
 *    за AJAX чартове/таблици по company/date/provider/context/purpose/kind/reservation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_reservations', function (Blueprint $table) {
            $table->string('model_level', 16)->nullable()->after('context_type');   // явно ниво (popup/задача) → token→кредити
            $table->string('outcome', 16)->nullable()->after('status');             // completed | failed | partial | refunded
            $table->json('billing_meta')->nullable()->after('outcome');             // snapshot на тарифата към момента
            $table->timestamp('settled_at')->nullable()->after('billing_meta');
            $table->timestamp('refunded_at')->nullable()->after('settled_at');
            $table->timestamp('failed_at')->nullable()->after('refunded_at');
            $table->index(['company_id', 'context_type', 'created_at']);
        });

        Schema::table('credit_ledger', function (Blueprint $table) {
            // Баланс на портфейла след прилагане на този ред (пише се на ВСеки ред, дори ефект 0).
            $table->integer('wallet_balance_after')->nullable()->after('amount');
        });

        Schema::table('llm_requests', function (Blueprint $table) {
            $table->uuid('operation_id')->nullable()->after('reservation_id');
            $table->index('operation_id');
            $table->index(['company_id', 'operation_id']);
            $table->index(['company_id', 'context_type', 'created_at']);
            $table->index(['company_id', 'provider', 'created_at']);
            $table->index(['company_id', 'purpose', 'created_at']);
            $table->index(['company_id', 'kind', 'created_at']);
            $table->index(['company_id', 'reservation_id']);
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('credit_reservations', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'context_type', 'created_at']);
            $table->dropColumn(['model_level', 'outcome', 'billing_meta', 'settled_at', 'refunded_at', 'failed_at']);
        });

        Schema::table('credit_ledger', function (Blueprint $table) {
            $table->dropColumn('wallet_balance_after');
        });

        Schema::table('llm_requests', function (Blueprint $table) {
            $table->dropIndex(['operation_id']);
            $table->dropIndex(['company_id', 'operation_id']);
            $table->dropIndex(['company_id', 'context_type', 'created_at']);
            $table->dropIndex(['company_id', 'provider', 'created_at']);
            $table->dropIndex(['company_id', 'purpose', 'created_at']);
            $table->dropIndex(['company_id', 'kind', 'created_at']);
            $table->dropIndex(['company_id', 'reservation_id']);
            $table->dropIndex(['company_id', 'created_at']);
            $table->dropColumn('operation_id');
        });
    }
};
