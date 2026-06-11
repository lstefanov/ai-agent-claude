<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_runs', function (Blueprint $table) {
            // Финалният step-QA score (0–100) на нода — храни историческото
            // учене на ModelRouterService. null = нодът няма QA gate.
            $table->unsignedTinyInteger('qa_score')->nullable()->after('quality_metrics');
        });
    }

    public function down(): void
    {
        Schema::table('node_runs', function (Blueprint $table) {
            $table->dropColumn('qa_score');
        });
    }
};
