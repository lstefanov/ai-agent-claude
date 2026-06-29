<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-company автономен бюджет: няма фирма = глобалният config cap;
     * има =overrides-ва. 0 означава „изключен таван" за тази фирма (безкап);
     * -1 означава „използвай глобалния" (sentinel — удобно за UI: не-зададено).
     *
     * За дневния % от баланса е същата конвенция.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // -1 = наследява глобалния config (sentinel), 0 = без таван, >0 = abs кредити/ден.
            $table->integer('auton_daily_credits')->default(-1)->after('act_enabled');
            // -1 = наследява, 0 = изключен, >0 = процент от баланса/ден.
            $table->integer('auton_daily_percent')->default(-1)->after('auton_daily_credits');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['auton_daily_credits', 'auton_daily_percent']);
        });
    }
};
