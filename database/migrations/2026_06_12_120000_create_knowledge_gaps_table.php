<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Пропуски": grounding търсения без покритие в базата знания — казват
        // на собственика КАКВО да качи, за да спрат агентите да гадаят.
        Schema::create('knowledge_gaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('node_key')->nullable();
            $table->string('query', 500);
            $table->decimal('best_score', 5, 3)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_gaps');
    }
};
