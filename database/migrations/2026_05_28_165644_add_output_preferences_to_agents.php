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
        Schema::table('agents', function (Blueprint $table) {
            // Output preferences — auto-injected into system prompt at execution
            $table->string('output_language', 10)->default('bg')->after('config');
            $table->string('output_tone', 30)->nullable()->after('output_language');
            $table->string('output_style', 30)->nullable()->after('output_tone');
            $table->string('output_format', 30)->nullable()->after('output_style');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn(['output_language', 'output_tone', 'output_style', 'output_format']);
        });
    }
};
