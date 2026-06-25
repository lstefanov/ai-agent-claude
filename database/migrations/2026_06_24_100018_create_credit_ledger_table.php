<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only журнал зад резервациите (reserve/settle/refund/topup/grant редове).
        Schema::create('credit_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_wallet_id')->constrained('credit_wallets')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete(); // денормализирано за справки
            // към коя резервация принадлежи редът (§A1)
            $table->foreignId('reservation_id')->nullable()->constrained('credit_reservations')->nullOnDelete();
            // носещото поле за reserve/settle машината (§0.5.2)
            $table->string('type');                          // reserve | settle | refund | topup | grant
            // operation-scoped (напр. "{reservation}:settle"); reserve/settle/refund са
            // РАЗЛИЧНИ редове с РАЗЛИЧНИ ключове — без collision (§A1)
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('direction');                     // debit | credit (само за справки)
            $table->integer('amount');                       // кредити, винаги ≥0; знакът се чете от type
            $table->string('reason');                        // run | monthly_grant | top_up | overage | refund
            $table->foreignId('flow_run_id')->nullable()->constrained('flow_runs')->nullOnDelete();
            $table->foreignId('node_run_id')->nullable()->constrained('node_runs')->nullOnDelete();
            $table->decimal('cost_usd', 10, 6)->nullable();  // реалният inference зад дебита
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_ledger');
    }
};
