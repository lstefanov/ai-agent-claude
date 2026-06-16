<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_drafts', function (Blueprint $table) {
            // C2: състояние на детерминистичния скрипт {domain, step, mode:'script'|'llm'}.
            $table->json('script')->nullable()->after('answers');
        });
    }

    public function down(): void
    {
        Schema::table('flow_drafts', function (Blueprint $table) {
            $table->dropColumn('script');
        });
    }
};
