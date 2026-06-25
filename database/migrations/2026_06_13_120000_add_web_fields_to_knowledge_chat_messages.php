<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Интернет fallback на чата "Тествай знанията": когато базата няма покритие,
 * отговорът идва от уеб търсене (Perplexity). Маркираме произхода, пазим
 * 👍/👎 обратната връзка и към кой записан ресурс е промотиран одобреният
 * интернет-отговор (тип `chat`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_chat_messages', function (Blueprint $table) {
            // kb = от базата знания (по подразбиране); web = от интернет fallback.
            $table->string('source_type', 8)->default('kb')->after('sources');
            // 👍/👎 само на web отговори; null = без оценка.
            $table->string('feedback', 4)->nullable()->after('source_type');
            // Ресурсът (тип chat), създаден при 👍 — промотираният отговор в знанието.
            $table->foreignId('saved_resource_id')->nullable()->after('feedback')
                ->constrained('knowledge_resources')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_chat_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('saved_resource_id');
            $table->dropColumn(['source_type', 'feedback']);
        });
    }
};
