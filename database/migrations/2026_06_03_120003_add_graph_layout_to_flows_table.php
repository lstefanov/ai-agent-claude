<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->json('graph_layout')->nullable();   // raw Drawflow export (positions/zoom)
        });
    }

    public function down(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->dropColumn('graph_layout');
        });
    }
};
