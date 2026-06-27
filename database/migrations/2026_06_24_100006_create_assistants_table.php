<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('org_member_id')->constrained('org_members')->cascadeOnDelete();
            $table->foreignId('director_id')->constrained('directors')->cascadeOnDelete();
            $table->string('title');
            $table->text('mandate');
            $table->json('kpi')->nullable();
            $table->json('position')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['org_version_id', 'org_member_id']);
            $table->index('director_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistants');
    }
};
