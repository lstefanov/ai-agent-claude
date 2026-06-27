<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            LlmModelSeeder::class,
            AgentTemplateSeeder::class,
            // AI Организация — seed библиотеки + планове (Фаза 0).
            PlanSeeder::class,
            PersonaArchetypeSeeder::class,
            OrgBlueprintSeeder::class,
        ]);
    }
}
