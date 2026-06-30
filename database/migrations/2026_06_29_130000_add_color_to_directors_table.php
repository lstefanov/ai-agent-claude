<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Явен цвят на отдела (override). NULL = „авто" (цвят по домейн през config function_colors).
     * Чете се от OrgMember::functionColor() и каскадира към директора + асистентите му.
     */
    public function up(): void
    {
        Schema::table('directors', function (Blueprint $table) {
            $table->string('color')->nullable()->after('domain');
        });
    }

    public function down(): void
    {
        Schema::table('directors', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
