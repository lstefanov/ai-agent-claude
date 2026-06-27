<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MUTABLE решението зад DecisionBox (§A7) — org_events остава отделният
        // append-only одит. При approve: ако base_org_version_id != активната версия
        // → superseded + ре-ревю (две паралелни одобрения не материализват върху остаряла версия).
        Schema::create('org_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // org_change | hire | fire | task | mandate | act_action
            $table->string('type');
            $table->json('payload');
            // pending | approved | rejected | superseded
            $table->string('status')->default('pending');
            // срещу коя активна версия е изготвено (optimistic concurrency)
            $table->foreignId('base_org_version_id')->nullable()->constrained('org_versions')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_proposals');
    }
};
