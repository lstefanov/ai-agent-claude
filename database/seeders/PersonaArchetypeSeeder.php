<?php

namespace Database\Seeders;

use App\Models\PersonaArchetype;
use Illuminate\Database\Seeder;

class PersonaArchetypeSeeder extends Seeder
{
    public function run(): void
    {
        // Manager-кандидатите за casting-а (§1.4) — примерни Управители с реални имена,
        // демография, характер и готов портрет. Точно 5: 3-ма мъже + 2 жени, видимо различни.
        // traits 0–100: риск/креативност/прецизност/автономност/темпо.
        $managers = [
            [
                'vertical' => null, 'role' => 'manager', 'name' => 'Калоян Стефанов',
                'age' => 34, 'gender' => 'мъж', 'ethnicity' => 'българин',
                'background' => 'растеж и продуктова стратегия',
                'traits' => ['risk' => 80, 'creativity' => 86, 'precision' => 60, 'autonomy' => 85, 'tempo' => 82],
                'tone' => 'амбициозен, вдъхновяващ, стратегически',
                'bio_template' => 'Млад визионер, който вижда голямата картина и тласка екипа към смели цели. Превръща неясната идея в посока, която хората искат да следват.',
                'avatar_path' => 'archetypes/manager_kaloyan.png',
            ],
            [
                'vertical' => null, 'role' => 'manager', 'name' => 'Георги Петров',
                'age' => 58, 'gender' => 'мъж', 'ethnicity' => 'българин',
                'background' => 'корпоративни финанси и управление',
                'traits' => ['risk' => 24, 'creativity' => 42, 'precision' => 92, 'autonomy' => 66, 'tempo' => 46],
                'tone' => 'сдържан, разсъдлив, предпазлив',
                'bio_template' => 'Ветеран лидер, който мери два пъти и реже веднъж. Пази устойчивостта на бизнеса и решава с числа, не с настроение.',
                'avatar_path' => 'archetypes/manager_georgi.png',
            ],
            [
                'vertical' => null, 'role' => 'manager', 'name' => 'Ивайло Димитров',
                'age' => 29, 'gender' => 'мъж', 'ethnicity' => 'българин',
                'background' => 'дигитален маркетинг и стартъпи',
                'traits' => ['risk' => 88, 'creativity' => 82, 'precision' => 50, 'autonomy' => 78, 'tempo' => 88],
                'tone' => 'дързък, енергичен, експериментаторски',
                'bio_template' => 'Смел предприемач, който пуска бързи тестове и учи от данните без страх. Обича скоростта и не чака идеалния момент — създава го.',
                'avatar_path' => 'archetypes/manager_ivaylo.png',
            ],
            [
                'vertical' => null, 'role' => 'manager', 'name' => 'Емилия Радева',
                'age' => 47, 'gender' => 'жена', 'ethnicity' => 'българка',
                'background' => 'операции и управление на екипи',
                'traits' => ['risk' => 38, 'creativity' => 52, 'precision' => 88, 'autonomy' => 70, 'tempo' => 58],
                'tone' => 'спокойна, методична, уверена',
                'bio_template' => 'Опитен организатор, който превръща хаоса в ясни процеси и държи екипа фокусиран. Решава без драма и държи на дадената дума.',
                'avatar_path' => 'archetypes/manager_emilia.png',
            ],
            [
                'vertical' => null, 'role' => 'manager', 'name' => 'Десислава Колева',
                'age' => 41, 'gender' => 'жена', 'ethnicity' => 'българка',
                'background' => 'човешки ресурси и клиентски опит',
                'traits' => ['risk' => 46, 'creativity' => 68, 'precision' => 72, 'autonomy' => 64, 'tempo' => 64],
                'tone' => 'топла, внимателна, отзивчива',
                'bio_template' => 'Лидер, който води чрез доверие и слуша преди да реши. Държи екипа мотивиран и всеки клиент усетен — растежът минава през хората.',
                'avatar_path' => 'archetypes/manager_desislava.png',
            ],
        ];

        // Точно 5 Управителя след seed — чистим стария набор и вмъкваме наново (идемпотентно).
        PersonaArchetype::where('role', 'manager')->delete();
        foreach ($managers as $manager) {
            PersonaArchetype::create($manager);
        }

        // Типови персони по роля×вертикал. Захранват предложенията на Управителя (§5/§11).
        $others = [
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

        foreach ($others as $archetype) {
            // Идемпотентност по (role, name).
            PersonaArchetype::updateOrCreate(
                ['role' => $archetype['role'], 'name' => $archetype['name']],
                $archetype,
            );
        }
    }
}
