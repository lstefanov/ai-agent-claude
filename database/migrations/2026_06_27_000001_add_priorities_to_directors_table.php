<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Приоритети на отдела (какви приоритети има) — AI-генерирани, бизнес-специфични,
        // редактируеми на ревю екрана; описанието „с какво се занимава" живее в `mandate`.
        Schema::table('directors', function (Blueprint $table) {
            $table->json('priorities')->nullable()->after('mandate');
        });
    }

    public function down(): void
    {
        Schema::table('directors', function (Blueprint $table) {
            $table->dropColumn('priorities');
        });
    }
};
