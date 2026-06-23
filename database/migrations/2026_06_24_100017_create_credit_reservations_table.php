<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mutable състоянието на ЕДНА билинг-операция; credit_ledger е append-only
        // журналът зад нея (reserve/settle/refund редове сочат reservation_id).
        // ВАЖНО: създава се ПРЕДИ credit_ledger — ledger.reservation_id сочи насам.
        Schema::create('credit_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // org_planning | interview | research | member_chat | avatar | embedding | generation | task_run
            $table->string('context_type');
            // полиморфният субект: org_member_id / assistant_task_id / flow_run_id според контекста
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedInteger('estimated_credits');
            $table->unsignedInteger('spent_credits')->default(0);
            // reserved | settled | refunded | expired
            $table->string('status')->default('reserved');
            // на RESERVE intent (напр. "{context}:{subject}:reserve") — пази от двоен резерв при retry/паралел
            $table->string('idempotency_key')->unique();
            $table->timestamps();
            $table->index(['company_id', 'context_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_reservations');
    }
};
