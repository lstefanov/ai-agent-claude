<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_tasks', function (Blueprint $table) {
            $table->id();
            // Задачите висят на стабилния член (НЕ на per-version assistant ред),
            // за да преживеят реорганизациите.
            $table->foreignId('org_member_id')->constrained('org_members')->cascadeOnDelete();
            // Текущото подчинение (по избор) — кой директор-член я надзирава сега.
            $table->foreignId('current_director_member_id')->nullable()
                ->constrained('org_members')->nullOnDelete();
            $table->foreignId('flow_id')->nullable()->constrained('flows')->nullOnDelete();
            $table->string('title');
            $table->text('description');
            $table->string('trigger')->default('manual');        // manual | scheduled | event
            $table->string('schedule')->nullable();              // cron
            $table->string('act_mode')->default('draft');        // draft | act | mixed
            $table->string('approval_policy')->default('approve_each'); // auto | approve_each | approve_first_then_auto
            // null = наследява org_members.default_star_tier на члена; non-null = явен override
            // (накрая cap по plans.max_star_tier — виж §6.1 „Наследяване на нивото").
            $table->string('star_tier')->nullable();
            $table->string('kpi')->nullable();
            // proposed | generating | ready | disabled | failed
            $table->string('status')->default('proposed');
            $table->string('gen_token')->nullable();
            // persisted намерение „пусни щом стане ready" (lazy-gen gate, §A3)
            $table->boolean('run_after_generate')->default(false);
            $table->timestamps();
            $table->index(['org_member_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_tasks');
    }
};
