<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('session')->index();
            // interviewing | ready | building | completed | abandoned
            $table->string('status')->default('interviewing');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->json('answers')->nullable(); // събрани key → стойност
            $table->foreignId('flow_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_drafts');
    }
};
