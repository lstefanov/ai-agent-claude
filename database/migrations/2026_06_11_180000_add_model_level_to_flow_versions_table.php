<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_versions', function (Blueprint $table) {
            // Ниво на runtime моделите на агентите в тази версия:
            // low|medium|high|ultra|custom (custom = ръчно сменян модел).
            // null = записана преди нивата да съществуват.
            $table->string('model_level', 12)->nullable()->after('generator');
        });
    }

    public function down(): void
    {
        Schema::table('flow_versions', function (Blueprint $table) {
            $table->dropColumn('model_level');
        });
    }
};
