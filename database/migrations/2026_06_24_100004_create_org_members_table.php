<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Стабилната идентичност — „служителят за цял живот". Тук висят персона/чат/
        // памет/представяне, нивото (default_star_tier) и — за асистенти — задачите.
        // Преживява org версиите (директор/асистент са плейсмънт редове, сочещи члена).
        Schema::create('org_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('kind');                          // manager | director | assistant
            $table->string('key');                           // стабилен машинен ключ
            $table->string('display_name');
            // proposed | active | retired
            $table->string('status')->default('active');
            $table->dateTime('retired_at')->nullable();
            // рангът/нивото на члена (валиден ModelLevel) — задачите му без override го наследяват
            $table->string('default_star_tier')->default('medium');
            $table->string('avatar_url')->nullable();        // денормализиран от personas.avatar_url
            $table->timestamps();
            $table->unique(['company_id', 'key']);
            $table->index(['company_id', 'kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_members');
    }
};
