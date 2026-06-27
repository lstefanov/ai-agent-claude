<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Демография + готов портрет за casting-кандидатите (примерни Управители със снимки).
        Schema::table('persona_archetypes', function (Blueprint $table) {
            $table->unsignedTinyInteger('age')->nullable()->after('name');
            $table->string('gender')->nullable()->after('age');          // 'мъж' | 'жена'
            $table->string('ethnicity')->nullable()->after('gender');
            $table->string('background')->nullable()->after('ethnicity');
            $table->string('avatar_path')->nullable()->after('bio_template'); // напр. 'archetypes/manager_kaloyan.png'
        });
    }

    public function down(): void
    {
        Schema::table('persona_archetypes', function (Blueprint $table) {
            $table->dropColumn(['age', 'gender', 'ethnicity', 'background', 'avatar_path']);
        });
    }
};
