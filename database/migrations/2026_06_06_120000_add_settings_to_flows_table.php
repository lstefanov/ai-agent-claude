<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Flow-level settings bag: per-run input declarations, result delivery
     * channel, and other flow options that don't warrant their own column.
     */
    public function up(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->json('settings')->nullable()->after('plan_intent');
        });
    }

    public function down(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->dropColumn('settings');
        });
    }
};
