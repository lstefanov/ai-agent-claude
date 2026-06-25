<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('research')->nullable();            // сайт/ревюта/уеб синтез
            $table->json('interview_answers')->nullable();
            $table->text('situational_analysis')->nullable();
            $table->json('pain_points')->nullable();
            // draft | researching | interviewing | ready
            $table->string('status')->default('draft');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_profiles');
    }
};
