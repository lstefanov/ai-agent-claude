<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('llm_models', function (Blueprint $table) {
            $table->unsignedInteger('size_mb')->nullable()->after('ram_required_gb');
            $table->string('pull_status', 20)->nullable()->after('is_available');
            $table->unsignedTinyInteger('pull_progress')->default(0)->after('pull_status');
        });
    }

    public function down(): void
    {
        Schema::table('llm_models', function (Blueprint $table) {
            $table->dropColumn(['size_mb', 'pull_status', 'pull_progress']);
        });
    }
};
