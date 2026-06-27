<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Една персона на член (mutable; промените се логват). Портретният аватар се
        // извежда от демографията (пол/възраст/етнос/роля/тон) — чисто козметичен (§5.3).
        Schema::create('personas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_member_id')->unique()->constrained('org_members')->cascadeOnDelete();
            $table->string('name');
            $table->string('ethnicity')->nullable();
            $table->unsignedInteger('age')->nullable();
            $table->string('gender')->nullable();
            $table->string('background')->nullable();
            $table->string('education')->nullable();
            $table->text('bio')->nullable();
            // риск/креативност/прецизност/автономност/темпо/тон, 0–100
            $table->json('traits');
            $table->string('tone')->nullable();
            // temperature, star_tier, tool_bias, parallelism — резултатът от §5
            $table->json('derived_knobs')->nullable();
            $table->string('archetype_key')->nullable();
            // Портрет (изведен от демографията; стабилен seed → детерминистичен ре-рендер)
            $table->string('avatar_url')->nullable();
            $table->text('avatar_prompt')->nullable();
            $table->unsignedBigInteger('avatar_seed')->nullable();
            $table->string('avatar_status')->default('pending');  // pending | ready | failed
            $table->json('avatar_meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personas');
    }
};
