<?php

namespace Database\Seeders;

use App\Models\OrgBlueprint;
use Illuminate\Database\Seeder;

class OrgBlueprintSeeder extends Seeder
{
    public function run(): void
    {
        // 3 seed вертикала (§11/§23). Всяка структура = директори (с подсказка за
        // default_star_tier) + типични асистенти (ниво по роля) + типични задачи.
        // star_tier на задача се задава САМО когато умишлено се различава от нивото
        // на члена — иначе null = наследява. proven=false (учат се с употребата).
        foreach ($this->blueprints() as $blueprint) {
            OrgBlueprint::updateOrCreate(
                ['vertical' => $blueprint['vertical'], 'name' => $blueprint['name']],
                $blueprint,
            );
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function blueprints(): array
    {
        return [
            [
                'vertical' => 'fitness',
                'name' => 'Спортен/фитнес център',
                'proven' => false,
                'structure' => [
                    'directors' => [
                        ['key' => 'operations', 'title' => 'Директор Операции', 'domain' => 'operations', 'default_star_tier' => 'high', 'mandate' => 'Графици на залата, резервации, гладко ежедневие.'],
                        ['key' => 'marketing', 'title' => 'Директор Маркетинг', 'domain' => 'marketing', 'default_star_tier' => 'high', 'mandate' => 'Привличане и задържане през съдържание и кампании.'],
                        ['key' => 'finance', 'title' => 'Директор Финанси', 'domain' => 'finance', 'default_star_tier' => 'high', 'mandate' => 'Приходи, разходи, членски такси, отчети.'],
                        ['key' => 'customer', 'title' => 'Директор Клиентско', 'domain' => 'customer', 'default_star_tier' => 'medium', 'mandate' => 'Задържане, обратна връзка, лоялност.'],
                        ['key' => 'data', 'title' => 'Директор Данни', 'domain' => 'data', 'default_star_tier' => 'medium', 'mandate' => 'Пълнота на класове, тенденции, прогнози.'],
                        ['key' => 'training', 'title' => 'Директор Тренировъчен', 'domain' => 'training', 'default_star_tier' => 'medium', 'mandate' => 'Програми, инструктори, качество на класовете.'],
                    ],
                    'assistants' => [
                        ['key' => 'content', 'title' => 'Асистент Съдържание', 'director' => 'marketing', 'default_star_tier' => 'medium', 'mandate' => 'Постове и съобщения в тона на бранда.'],
                        ['key' => 'social', 'title' => 'Асистент Социални мрежи', 'director' => 'marketing', 'default_star_tier' => 'medium', 'mandate' => 'Планиране и публикуване по канали.'],
                        ['key' => 'reservations', 'title' => 'Асистент Резервации', 'director' => 'operations', 'default_star_tier' => 'medium', 'mandate' => 'Онлайн резервации и напомняния.'],
                        ['key' => 'retention', 'title' => 'Асистент Задържане', 'director' => 'customer', 'default_star_tier' => 'medium', 'mandate' => 'Кампании за връщане на неактивни членове.'],
                    ],
                    'tasks' => [
                        ['title' => 'Седмичен пост', 'assistant' => 'content', 'act_mode' => 'draft', 'star_tier' => null, 'trigger' => 'scheduled'],
                        ['title' => 'Напомняния за резервации', 'assistant' => 'reservations', 'act_mode' => 'act', 'star_tier' => 'high', 'trigger' => 'scheduled'],
                        ['title' => 'Месечен финансов отчет', 'assistant' => null, 'act_mode' => 'draft', 'star_tier' => null, 'trigger' => 'scheduled'],
                    ],
                ],
            ],
            [
                'vertical' => 'restaurant',
                'name' => 'Ресторант',
                'proven' => false,
                'structure' => [
                    'directors' => [
                        ['key' => 'operations', 'title' => 'Директор Операции', 'domain' => 'operations', 'default_star_tier' => 'high', 'mandate' => 'Резервации, смени, доставки.'],
                        ['key' => 'marketing', 'title' => 'Директор Маркетинг', 'domain' => 'marketing', 'default_star_tier' => 'high', 'mandate' => 'Меню комуникация, промоции, присъствие онлайн.'],
                        ['key' => 'finance', 'title' => 'Директор Финанси', 'domain' => 'finance', 'default_star_tier' => 'high', 'mandate' => 'Себестойност, маржове, разходи за продукти.'],
                        ['key' => 'customer', 'title' => 'Директор Клиентско', 'domain' => 'customer', 'default_star_tier' => 'medium', 'mandate' => 'Ревюта, обратна връзка, лоялност.'],
                        ['key' => 'data', 'title' => 'Директор Данни', 'domain' => 'data', 'default_star_tier' => 'medium', 'mandate' => 'Топ ястия, натовареност по часове.'],
                        ['key' => 'kitchen', 'title' => 'Директор Кухня', 'domain' => 'kitchen', 'default_star_tier' => 'medium', 'mandate' => 'Меню, качество, наличности.'],
                    ],
                    'assistants' => [
                        ['key' => 'menu_content', 'title' => 'Асистент Меню съдържание', 'director' => 'marketing', 'default_star_tier' => 'medium', 'mandate' => 'Описания на ястия и дневни предложения.'],
                        ['key' => 'reservations', 'title' => 'Асистент Резервации', 'director' => 'operations', 'default_star_tier' => 'medium', 'mandate' => 'Управление на резервации и напомняния.'],
                        ['key' => 'reviews', 'title' => 'Асистент Ревюта', 'director' => 'customer', 'default_star_tier' => 'medium', 'mandate' => 'Мониторинг и отговор на ревюта.'],
                        ['key' => 'supply', 'title' => 'Асистент Доставки', 'director' => 'kitchen', 'default_star_tier' => 'medium', 'mandate' => 'Поръчки към доставчици по наличности.'],
                    ],
                    'tasks' => [
                        ['title' => 'Дневно меню пост', 'assistant' => 'menu_content', 'act_mode' => 'draft', 'star_tier' => null, 'trigger' => 'scheduled'],
                        ['title' => 'Отговор на ревюта', 'assistant' => 'reviews', 'act_mode' => 'act', 'star_tier' => 'high', 'trigger' => 'event'],
                        ['title' => 'Седмична поръчка доставки', 'assistant' => 'supply', 'act_mode' => 'act', 'star_tier' => null, 'trigger' => 'scheduled'],
                    ],
                ],
            ],
            [
                'vertical' => 'services',
                'name' => 'Услуги/ремонти',
                'proven' => false,
                'structure' => [
                    'directors' => [
                        ['key' => 'operations', 'title' => 'Директор Операции', 'domain' => 'operations', 'default_star_tier' => 'high', 'mandate' => 'График на екипи, обекти, материали.'],
                        ['key' => 'marketing', 'title' => 'Директор Маркетинг', 'domain' => 'marketing', 'default_star_tier' => 'high', 'mandate' => 'Запитвания, оферти, репутация.'],
                        ['key' => 'finance', 'title' => 'Директор Финанси', 'domain' => 'finance', 'default_star_tier' => 'high', 'mandate' => 'Калкулации, фактуриране, събиране на вземания.'],
                        ['key' => 'customer', 'title' => 'Директор Клиентско', 'domain' => 'customer', 'default_star_tier' => 'medium', 'mandate' => 'Последващи контакти, гаранции, удовлетвореност.'],
                        ['key' => 'data', 'title' => 'Директор Данни', 'domain' => 'data', 'default_star_tier' => 'medium', 'mandate' => 'Конверсия на оферти, сезонност.'],
                        ['key' => 'field', 'title' => 'Директор Терен', 'domain' => 'field', 'default_star_tier' => 'medium', 'mandate' => 'Качество на изпълнение и приемане на обекти.'],
                    ],
                    'assistants' => [
                        ['key' => 'quotes', 'title' => 'Асистент Оферти', 'director' => 'marketing', 'default_star_tier' => 'medium', 'mandate' => 'Бързи оферти по запитване.'],
                        ['key' => 'scheduling', 'title' => 'Асистент График екипи', 'director' => 'operations', 'default_star_tier' => 'medium', 'mandate' => 'Разпределение на екипи по обекти.'],
                        ['key' => 'followup', 'title' => 'Асистент Последващи обаждания', 'director' => 'customer', 'default_star_tier' => 'medium', 'mandate' => 'Проследяване след завършен обект.'],
                        ['key' => 'content', 'title' => 'Асистент Съдържание', 'director' => 'marketing', 'default_star_tier' => 'medium', 'mandate' => 'Кейсове и преди/след съдържание.'],
                    ],
                    'tasks' => [
                        ['title' => 'Оферта по запитване', 'assistant' => 'quotes', 'act_mode' => 'draft', 'star_tier' => null, 'trigger' => 'event'],
                        ['title' => 'Напомняне за плащане', 'assistant' => 'followup', 'act_mode' => 'act', 'star_tier' => 'high', 'trigger' => 'scheduled'],
                        ['title' => 'Седмичен график екипи', 'assistant' => 'scheduling', 'act_mode' => 'draft', 'star_tier' => null, 'trigger' => 'scheduled'],
                    ],
                ],
            ],
        ];
    }
}
