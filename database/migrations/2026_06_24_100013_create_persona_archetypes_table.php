<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Типови персони по роля×вертикал (напр. „млад growth маркетинг").
        Schema::create('persona_archetypes', function (Blueprint $table) {
            $table->id();
            $table->string('vertical')->nullable()->index();
            $table->string('role');                          // director | assistant
            $table->string('name');                          // напр. „млад growth маркетинг"
            $table->json('traits');
            $table->string('tone')->nullable();
            $table->text('bio_template')->nullable();
            $table->json('embedding')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('persona_archetypes');
    }
};
