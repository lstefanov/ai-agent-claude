<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\Flow;
use Illuminate\Database\Seeder;

class UpdateFlow4PipelineSeeder extends Seeder
{
    public function run(): void
    {
        $flow = Flow::find(4);
        if (!$flow) {
            $this->command->error('Flow 4 not found.');
            return;
        }

        // ── 1. Update existing Researcher → MultiResearcher ──────────────
        $researcher = Agent::where('flow_id', 4)
            ->where('type', 'researcher')
            ->first();

        if ($researcher) {
            $researcher->update([
                'type'   => 'multi_researcher',
                'order'  => 1,
                'config' => array_merge($researcher->config ?? [], [
                    'search_queries_count' => 5,
                    'search_queries'       => [
                        'фитнес зала цени абонамент месечен 2026 {{company_industry}}',
                        'йога студио цени тренировки групови {{company_industry}}',
                        'пилатес цени индивидуални групови занимания България',
                        'спортен център басейн spa абонамент цени конкуренти',
                        'фитнес membership цени сравнение клубове {{company_industry}}',
                    ],
                ]),
                'role' => 'Ти си изследовател на конкурентни цени. Твоята задача е да намериш КОНКРЕТНИ данни: КОНКУРЕНТ → УСЛУГА → ТОЧНА ЦЕНА → ЛИНК. Никога не обобщавай — запазвай точните числа и имена от всеки намерен резултат.',
            ]);
        }

        // ── 2. Check if Extractor already exists (idempotent) ─────────────
        $extractor = Agent::where('flow_id', 4)
            ->where('name', 'Екстрактор на данни')
            ->first();

        if (!$extractor) {
            Agent::create([
                'flow_id'         => 4,
                'name'            => 'Екстрактор на данни',
                'type'            => 'analyzer',
                'order'           => 2,
                'model'           => 'gemma2:9b',
                'is_active'       => true,
                'output_language' => 'bg',
                'output_format'   => 'table',
                'role'            => 'Ти си специалист по структуриране на данни. Твоята ЕДИНСТВЕНА задача: извлечи ВСИЧКИ конкретни цени от текста и ги представи САМО като Markdown таблица. Не добавяй коментари, обяснения или обобщения — САМО таблицата.',
                'prompt_template' => "От следните изследователски данни, извлечи ВСИЧКИ конкретни цени и конкуренти.\n\nИЗИСКВАН ФОРМАТ — САМО тази таблица, нищо друго:\n\n| Конкурент | Услуга | Цена (лв./€) | Тип | Бележки | Линк |\n|-----------|--------|-------------|-----|---------|------|\n\nИзследователски данни:\n{{Изследовател на ценовите стратегии}}",
                'config'          => ['temperature' => 0.1],
            ]);
        }

        // ── 3. Push existing agents order >= 2 up by 1 (except Extractor) ─
        Agent::where('flow_id', 4)
            ->where('order', '>=', 2)
            ->where('name', '!=', 'Екстрактор на данни')
            ->increment('order');

        // ── 4. Update Analyzer prompt ─────────────────────────────────────
        $analyzer = Agent::where('flow_id', 4)
            ->where('type', 'analyzer')
            ->where('name', '!=', 'Екстрактор на данни')
            ->first();

        if ($analyzer) {
            $analyzer->update([
                'order'           => 3,
                'prompt_template' => "Анализирайте таблицата с конкурентни цени по-долу и извлечете ключови инсайти за {{company_description}}.\n\nОТГОВОРЕТЕ НА СЛЕДНИТЕ ВЪПРОСИ (цитирайте конкретни конкуренти и цени):\n1. Кои конкуренти предлагат най-ниски/най-високи цени и за какви услуги?\n2. Какви са пазарните ценови диапазони (мин-макс) за всяка категория услуги?\n3. Кои услуги са под-представени или липсват в пазара?\n4. Каква ценова позиция препоръчвате за {{company_description}}?\n\nТаблица с конкурентни цени:\n{{Екстрактор на данни}}",
            ]);
        }

        // ── 5. Update Content Creator ──────────────────────────────────────
        $contentCreator = Agent::where('flow_id', 4)
            ->where('type', 'like', 'content%')
            ->first();

        if ($contentCreator) {
            $contentCreator->update([
                'order'           => 4,
                'role'            => 'Ти си копирайтър специалист. Генерираш конкретно, базирано на данни съдържание. ЗАБРАНЕНО е да пишеш обобщения без конкретни цифри. Всяко твърдение трябва да е подкрепено с конкретен конкурент и цена от предоставените данни.',
                'prompt_template' => "Създайте конкретно стратегическо съдържание за {{company_description}} въз основа на анализа.\n\nЗАДЪЛЖИТЕЛНИ ИЗИСКВАНИЯ:\n- Цитирай минимум 5 конкретни конкурента с техните реални цени\n- Включи ценови диапазони от таблицата (напр. 'групови тренировки: 8-20 лв.')\n- Предложи конкретна ценова стратегия с числа\n- Сравни поне 3 конкурента директно\n\nАнализ:\n{{Анализатор на ценови тенденции}}\n\nСтруктурирани данни (използвай тези конкретни цени):\n{{Екстрактор на данни}}",
            ]);
        }

        // ── 6. Update QA order ─────────────────────────────────────────────
        Agent::where('flow_id', 4)
            ->where('type', 'qa_verifier')
            ->update(['order' => 5]);

        $this->command->info('Flow 4 pipeline updated successfully.');
        $this->command->info('New pipeline: MultiResearcher(1) → Extractor(2) → Analyzer(3) → ContentCreator(4) → QA(5)');
    }
}
