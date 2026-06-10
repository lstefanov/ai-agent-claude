<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which graph version ("шаблон") a run executed — powers the run-history
     * comparison of the same flow across versions.
     */
    public function up(): void
    {
        Schema::table('flow_runs', function (Blueprint $table) {
            $table->foreignId('flow_version_id')->nullable()->after('flow_id')
                ->constrained('flow_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('flow_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('flow_version_id');
        });
    }
};
