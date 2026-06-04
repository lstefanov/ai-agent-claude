<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_runs', function (Blueprint $table) {
            $table->json('params_snapshot')->nullable()->after('quality_metrics');
        });
    }

    public function down(): void
    {
        Schema::table('node_runs', function (Blueprint $table) {
            $table->dropColumn('params_snapshot');
        });
    }
};
