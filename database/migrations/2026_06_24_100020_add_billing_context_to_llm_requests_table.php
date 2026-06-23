<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Билинг-атрибуция върху llm_requests (§0.5.1): context_type + полиморфен
        // subject + reservation_id, за да сумира actualFor() точно requests-ите под
        // дадена резервация (а не по груб flow_run_id, който не покрива chat/research/
        // avatar/interview/generation контекстите).
        Schema::table('llm_requests', function (Blueprint $table) {
            $table->string('context_type', 40)->nullable()->after('agent_type');
            $table->string('subject_type', 60)->nullable()->after('context_type');
            $table->unsignedBigInteger('subject_id')->nullable()->after('subject_type');
            $table->foreignId('reservation_id')->nullable()->after('subject_id')
                ->constrained('credit_reservations')->nullOnDelete();
            $table->index('reservation_id');
        });
    }

    public function down(): void
    {
        Schema::table('llm_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reservation_id');
            $table->dropColumn(['context_type', 'subject_type', 'subject_id']);
        });
    }
};
