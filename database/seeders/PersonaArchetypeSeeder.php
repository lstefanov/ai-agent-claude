<?php

namespace Database\Seeders;

use App\Models\PersonaArchetype;
use Illuminate\Database\Seeder;

class PersonaArchetypeSeeder extends Seeder
{
    public function run(): void
    {
        // Типови персони по роля×вертикал. traits 0–100: риск/креативност/прецизност/
        // автономност/темпо. Захранват casting-а и предложенията на Управителя (§5/§11).
        $archetypes = [
            [
                'vertical' => null, 'role' => 'manager', 'name' => 'визионер управител',
                'traits' => ['risk' => 78, 'creativity' => 85, 'precision' => 62, 'autonomy' => 85, 'tempo' => 80],
                'tone' => 'амбициозен, вдъхновяващ, стратегически',
                'bio_template' => 'Млад визионер, който вижда голямата картина и тласка екипа напред.',
            ],
            [
                'vertical' => null, 'role' => 'director', 'name' => 'млад growth маркетинг',
                'traits' => ['risk' => 80, 'creativity' => 90, 'precision' => 55, 'autonomy' => 70, 'tempo' => 82],
                'tone' => 'дързък, енергичен, експериментаторски',
                'bio_template' => 'Смел маркетинг директор, който залага на бързи експерименти и силни послания.',
            ],
            [
                'vertical' => null, 'role' => 'director', 'name' => 'ветеран финансов директор',
                'traits' => ['risk' => 25, 'creativity' => 40, 'precision' => 92, 'autonomy' => 65, 'tempo' => 45],
                'tone' => 'сдържан, прецизен, предпазлив',
                'bio_template' => 'Опитен финансист, който пази числата чисти и решенията обосновани.',
            ],
            [
                'vertical' => null, 'role' => 'director', 'name' => 'прецизен операции',
                'traits' => ['risk' => 35, 'creativity' => 50, 'precision' => 88, 'autonomy' => 72, 'tempo' => 62],
                'tone' => 'методичен, спокоен, системен',
                'bio_template' => 'Операционен директор, който превръща хаоса в гладко работещи процеси.',
            ],
            [
                'vertical' => null, 'role' => 'director', 'name' => 'емпатичен клиентско',
                'traits' => ['risk' => 40, 'creativity' => 65, 'precision' => 70, 'autonomy' => 60, 'tempo' => 68],
                'tone' => 'топъл, внимателен, отзивчив',
                'bio_template' => 'Директор „Клиенти", за когото всеки контакт е възможност за доверие.',
            ],
            [
                'vertical' => null, 'role' => 'assistant', 'name' => 'креативен копирайтър',
                'traits' => ['risk' => 65, 'creativity' => 90, 'precision' => 60, 'autonomy' => 50, 'tempo' => 78],
                'tone' => 'игрив, жив, закачлив',
                'bio_template' => 'Асистент по съдържание с усет за думи, които спират скрола.',
            ],
            [
                'vertical' => null, 'role' => 'assistant', 'name' => 'методичен анализатор',
                'traits' => ['risk' => 28, 'creativity' => 45, 'precision' => 90, 'autonomy' => 55, 'tempo' => 50],
                'tone' => 'фактологичен, точен, безпристрастен',
                'bio_template' => 'Асистент-анализатор, който вярва само на данните.',
            ],
            [
                'vertical' => null, 'role' => 'assistant', 'name' => 'организиран координатор',
                'traits' => ['risk' => 32, 'creativity' => 52, 'precision' => 85, 'autonomy' => 58, 'tempo' => 66],
                'tone' => 'делови, ясен, надежден',
                'bio_template' => 'Асистент-координатор, който държи графиците и задачите в ред.',
            ],
        ];

        foreach ($archetypes as $archetype) {
            // Идемпотентност по (role, name).
            PersonaArchetype::updateOrCreate(
                ['role' => $archetype['role'], 'name' => $archetype['name']],
                $archetype,
            );
        }
    }
}
