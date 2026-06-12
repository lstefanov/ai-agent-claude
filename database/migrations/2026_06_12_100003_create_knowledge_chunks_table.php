<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_document_id')->constrained()->cascadeOnDelete();
            // Denormalized so similarity search scans ONE table per company.
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('seq');
            $table->text('content');
            $table->json('embedding')->nullable();
            // Vectors from different providers/models have different dimensions
            // and are NOT comparable — similarity only runs within one tag.
            $table->string('embedding_provider', 100)->nullable();
            $table->json('meta')->nullable(); // heading | sheet | page | node_key/node_name
            $table->timestamps();

            $table->index(['company_id', 'embedding_provider']);
            $table->index(['knowledge_document_id', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
    }
};
