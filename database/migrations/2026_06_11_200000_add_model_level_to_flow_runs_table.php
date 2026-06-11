<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_runs', function (Blueprint $table) {
            // Снимка на нивото на runtime моделите (low|medium|high|ultra|custom),
            // взето от версията в момента на пускане — историята показва с какво
            // ниво е изпълнен run-ът, дори ако шаблонът се пре-нивелира после.
            $table->string('model_level', 12)->nullable()->after('flow_version_id');
        });
    }

    public function down(): void
    {
        Schema::table('flow_runs', function (Blueprint $table) {
            $table->dropColumn('model_level');
        });
    }
};
