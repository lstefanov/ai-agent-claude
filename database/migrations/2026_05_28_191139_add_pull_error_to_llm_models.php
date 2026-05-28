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
            $table->text('pull_error')->nullable()->after('pull_progress');
        });
    }

    public function down(): void
    {
        Schema::table('llm_models', function (Blueprint $table) {
            $table->dropColumn('pull_error');
        });
    }
};
