<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_profiles', function (Blueprint $table) {
            // Пълен транскрипт на интервюто (реплики + въпроси) — за да оцелява при refresh.
            $table->json('interview_transcript')->nullable()->after('interview_answers');
        });
    }

    public function down(): void
    {
        Schema::table('business_profiles', function (Blueprint $table) {
            $table->dropColumn('interview_transcript');
        });
    }
};
