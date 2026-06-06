<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Intent embedding (OpenAI text-embedding-3-small) per plan-library entry.
 * Used for cosine-similarity retrieval once the library outgrows the
 * structural scorer (services.planner.vector_threshold).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_library', function (Blueprint $table) {
            $table->json('embedding')->nullable()->after('agents');
        });
    }

    public function down(): void
    {
        Schema::table('plan_library', function (Blueprint $table) {
            $table->dropColumn('embedding');
        });
    }
};
