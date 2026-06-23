<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only одит на организацията (без updated_at).
        Schema::create('org_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('org_version_id')->nullable()->constrained('org_versions')->nullOnDelete();
            // hire | fire | reassign | mandate_change | approval | action | review
            $table->string('type');
            // когато субектът е член
            $table->foreignId('org_member_id')->nullable()->constrained('org_members')->nullOnDelete();
            // за нечленски субекти (полиморфно)
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('summary');
            $table->json('meta')->nullable();
            $table->string('actor')->nullable();             // manager | director | human
            $table->timestamp('created_at')->nullable();
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_events');
    }
};
