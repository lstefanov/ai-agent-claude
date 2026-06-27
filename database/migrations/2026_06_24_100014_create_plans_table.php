<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();                 // starter | professional | business | enterprise
            $table->string('name');
            $table->unsignedInteger('price_cents');
            $table->unsignedInteger('monthly_credits');
            $table->string('max_star_tier');                 // макс. позволено ModelLevel
            $table->json('features')->nullable();
            $table->string('stripe_price_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
