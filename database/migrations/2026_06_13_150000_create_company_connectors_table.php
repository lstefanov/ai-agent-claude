<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MCP конектори — auth на ниво Company. Една връзка (Gmail/Notion/HTTP API…)
 * се ползва от всички flows на фирмата. `credentials` са КРИПТИРАНИ (Laravel
 * encrypted cast, APP_KEY) — никога plaintext, никога в логове. Flow-specific
 * настройки (коя папка/канал) живеят в FlowNode.config, не тук.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_connectors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // gmail | google_sheets | google_drive | slack | notion | airtable |
            // hubspot | github | trello | mailchimp | http_api
            $table->string('connector_type', 50);
            // "Фирмен Gmail" — може да имаш 2 акаунта от един тип.
            $table->string('display_name')->nullable();
            $table->string('auth_type', 20); // oauth2 | api_key | bearer | basic
            $table->text('credentials');      // ENCRYPTED JSON (виж CompanyConnector cast)
            $table->json('scopes')->nullable();
            $table->string('status', 20)->default('active'); // active|expired|revoked|error
            $table->timestamp('last_tested_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('settings')->nullable(); // connector-specific defaults
            $table->timestamps();

            $table->unique(['company_id', 'connector_type', 'display_name']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_connectors');
    }
};
