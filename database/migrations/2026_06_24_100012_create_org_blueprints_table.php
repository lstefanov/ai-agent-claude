<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Библиотека от org шаблони по вертикала (директори + типични асистенти + задачи).
        // proven=true → доказан от реална компания (few-shot за Управителя).
        Schema::create('org_blueprints', function (Blueprint $table) {
            $table->id();
            $table->string('vertical')->index();
            $table->string('name');
            $table->json('structure');                       // директори + типични асистенти + типични задачи
            $table->json('embedding')->nullable();
            $table->boolean('proven')->default(false);
            $table->foreignId('source_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_blueprints');
    }
};
