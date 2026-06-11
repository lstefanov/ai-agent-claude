<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // null = company-wide note (applies to every flow of the company).
            $table->foreignId('flow_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('note');
            $table->timestamps();

            $table->index(['company_id', 'flow_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_notes');
    }
};
