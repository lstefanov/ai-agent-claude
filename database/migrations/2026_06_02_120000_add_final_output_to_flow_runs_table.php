<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_runs', function (Blueprint $table) {
            $table->longText('final_output')->nullable()->after('context');
            $table->string('final_output_model')->nullable()->after('final_output');
        });
    }

    public function down(): void
    {
        Schema::table('flow_runs', function (Blueprint $table) {
            $table->dropColumn(['final_output', 'final_output_model']);
        });
    }
};
