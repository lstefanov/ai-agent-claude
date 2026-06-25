<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Плейсмънт ред = ролята/мястото на члена В ТАЗИ версия. Нивото на директора
        // живее на org_members.default_star_tier (рангът на члена), НЕ тук.
        Schema::create('directors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('org_member_id')->constrained('org_members')->cascadeOnDelete();
            $table->string('title');
            $table->string('domain');                        // operations | marketing | finance | ...
            $table->text('mandate');
            $table->json('kpi')->nullable();
            $table->json('position')->nullable();            // x/y за графа
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['org_version_id', 'org_member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directors');
    }
};
