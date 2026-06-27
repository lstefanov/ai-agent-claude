<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_profiles', function (Blueprint $table) {
            // Задължителният синтез след интервюто (§3-part understanding):
            // всички проблеми, всички нужди и предложени нови възможности за растеж.
            $table->json('problems')->nullable()->after('pain_points');
            $table->json('needs')->nullable()->after('problems');
            $table->json('opportunities')->nullable()->after('needs');
            // Маркер за идемпотентност — синтезът се прави веднъж.
            $table->timestamp('synthesis_completed_at')->nullable()->after('opportunities');
        });
    }

    public function down(): void
    {
        Schema::table('business_profiles', function (Blueprint $table) {
            $table->dropColumn(['problems', 'needs', 'opportunities', 'synthesis_completed_at']);
        });
    }
};
