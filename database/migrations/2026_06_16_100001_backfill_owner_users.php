<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Идемпотентно: гарантира по един `owner` потребител на всяка фирма.
     * Преизпълнението е безопасно — пропуска фирмите, които вече имат owner.
     */
    public function up(): void
    {
        Company::query()->each(function (Company $company) {
            $hasOwner = User::where('company_id', $company->id)->where('role', 'owner')->exists();
            if ($hasOwner) {
                return;
            }

            User::create([
                'name' => 'Owner',
                'email' => "owner+{$company->id}@flowai.local",
                'password' => bcrypt(Str::random(32)),
                'company_id' => $company->id,
                'role' => 'owner',
                'is_active' => true,
            ]);
        });
    }

    public function down(): void
    {
        // Без обратна миграция — собственикът reset-ва БД (виж CLAUDE.md).
    }
};
