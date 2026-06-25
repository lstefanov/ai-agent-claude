<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // §6.1: повишаване/понижаване на член маркира задачите му без override като
        // tier_stale → при СЛЕДВАЩОТО пускане flow-ът се re-pin-ва server-side към
        // effectiveStarTier() (lazy re-pin), после tier_stale=false. Без изненадваща цена.
        Schema::table('assistant_tasks', function (Blueprint $table) {
            $table->boolean('tier_stale')->default(false)->after('star_tier');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_tasks', function (Blueprint $table) {
            $table->dropColumn('tier_stale');
        });
    }
};
