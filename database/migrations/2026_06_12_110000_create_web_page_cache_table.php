<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // GLOBAL scrape cache (no company_id) — scraped pages are public web
        // content; one record serves every flow that touches the same URL.
        Schema::create('web_page_cache', function (Blueprint $table) {
            $table->id();
            $table->char('url_hash', 64)->unique(); // sha256 of the normalized URL
            $table->string('url', 2048);
            $table->char('content_hash', 64);       // sha256 of the markdown
            $table->longText('markdown');
            $table->timestamp('fetched_at');        // last time content actually changed
            $table->timestamp('last_checked_at')->index();
            $table->unsignedInteger('hit_count')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_page_cache');
    }
};
