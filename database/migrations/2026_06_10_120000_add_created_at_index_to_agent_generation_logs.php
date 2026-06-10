<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The generation-log panel and the admin costs page both read recent
     * agent_generation_logs ordered by recency — index the sort column.
     */
    public function up(): void
    {
        Schema::table('agent_generation_logs', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('agent_generation_logs', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
