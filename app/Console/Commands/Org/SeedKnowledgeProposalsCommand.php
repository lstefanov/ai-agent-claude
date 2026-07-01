<?php

namespace App\Console\Commands\Org;

use App\Models\AssistantTask;
use App\Models\Company;
use App\Models\OrgMember;
use App\Services\KnowledgeService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Еднократно сее демонстрационни предложения „Чака знания" (§2-етапни задачи) за фирма:
 * задачи в състояние needs_knowledge с изисквания за липсваща фирмена информация. Появяват се
 * в Кутията за решения като assistant_task_knowledge — Управителят качва знанието, системата
 * пре-оценява (KnowledgeRequirementService) и чак тогава генерира Flow. Чист reuse на
 * съществуващия гейт — тук само сеем детерминистично (без LLM). Идемпотентна по (фирма, заглавие).
 */
class SeedKnowledgeProposalsCommand extends Command
{
    protected $signature = 'org:seed-knowledge-proposals {company? : ID или част от името на фирмата (по подразбиране: Game sport)}';

    protected $description = 'Seed two knowledge-gated ("Чака знания") task proposals for a company';

    public function handle(): int
    {
        $company = $this->resolveCompany($this->argument('company'));
        if (! $company) {
            $this->error('Фирмата не е намерена. Подай ID или част от името, напр.: org:seed-knowledge-proposals "Game sport".');

            return self::FAILURE;
        }

        $this->info("Фирма: {$company->name} (#{$company->id})");

        if (! KnowledgeService::enabled($company)) {
            $this->warn('Внимание: знанието е ИЗКЛЮЧЕНО за тази фирма — гейтът е no-op и „Провери" ще отпуши задачата веднага.');
        }

        $assistants = $company->members()
            ->where('kind', 'assistant')
            ->where('status', 'active')
            ->get();

        if ($assistants->isEmpty()) {
            $this->error('Няма активни асистенти във фирмата — не мога да възложа задачите.');

            return self::FAILURE;
        }

        $domainByMember = $this->domainByMember($company);

        $created = 0;
        $usedIds = [];
        foreach ($this->definitions() as $def) {
            if ($this->taskExists($company, $def['title'])) {
                $this->line("• Пропуснато (вече съществува): {$def['title']}");

                continue;
            }

            $owner = $this->pickAssistant($assistants, $domainByMember, $def['domain_keywords'], $usedIds);
            $usedIds[] = $owner->id;

            $task = AssistantTask::create([
                'org_member_id' => $owner->id,
                'title' => $def['title'],
                'description' => $def['description'],
                'trigger' => 'manual',
                'act_mode' => 'draft',
                'approval_policy' => 'auto',
                'status' => 'proposed',
                'knowledge_status' => 'needs_knowledge',
                'knowledge_evaluated_at' => null,
            ]);

            foreach ($def['requirements'] as $req) {
                $query = (string) $req['query'];
                $task->knowledgeRequirements()->create([
                    'key' => Str::slug($req['label']).'-'.substr(sha1($query), 0, 12),
                    'label' => $req['label'],
                    'query' => $query,
                    'sourceability' => $req['sourceability'],
                    'status' => 'missing',
                    'acknowledged' => false,
                    'how_to_provide' => ($req['how_to_provide'] ?? '') !== '' ? $req['how_to_provide'] : null,
                ]);
            }

            $created++;
            $count = count($def['requirements']);
            $this->info("✓ Създадено: {$def['title']} -> {$owner->display_name} (#{$owner->id}), {$count} изисквания [task #{$task->id}]");
        }

        $this->newLine();
        $this->info("Готово. Създадени {$created} предложения от тип 'Чака знания'.");
        $this->line("Виж ги в Кутията за решения (Предложения) -> бутон 'Добави знания'.");

        return self::SUCCESS;
    }

    private function resolveCompany(?string $arg): ?Company
    {
        if ($arg !== null && $arg !== '' && ctype_digit($arg)) {
            return Company::find((int) $arg);
        }

        $needle = ($arg !== null && $arg !== '') ? $arg : 'Game sport';

        return Company::where('name', 'like', '%'.$needle.'%')->orderBy('id')->first();
    }

    private function taskExists(Company $company, string $title): bool
    {
        return AssistantTask::where('title', $title)
            ->whereHas('orgMember', fn ($q) => $q->where('company_id', $company->id))
            ->exists();
    }

    /**
     * member_id → функционален домейн (директор: свой; асистент: на директора му), lower-case.
     * Същото мапване като DecisionBoxService::deck().
     *
     * @return array<int, string>
     */
    private function domainByMember(Company $company): array
    {
        $version = $company->activeOrgVersion;
        if (! $version) {
            return [];
        }

        $map = [];
        foreach ($version->directors()->get() as $d) {
            if ($d->org_member_id && $d->domain) {
                $map[$d->org_member_id] = mb_strtolower((string) $d->domain);
            }
        }
        foreach ($version->assistants()->with('director')->get() as $a) {
            if ($a->org_member_id && $a->director?->domain) {
                $map[$a->org_member_id] = mb_strtolower((string) $a->director->domain);
            }
        }

        return $map;
    }

    /**
     * Избира асистент по предпочитан домейн (ключови думи), с graceful fallback: първо
     * неизползван домейн-мач, после какъвто и да е домейн-мач, после първи неизползван, после първи.
     *
     * @param  Collection<int, OrgMember>  $assistants
     * @param  array<int, string>  $domainByMember
     * @param  array<int, string>  $keywords
     * @param  array<int, int>  $usedIds
     */
    private function pickAssistant(Collection $assistants, array $domainByMember, array $keywords, array $usedIds): OrgMember
    {
        $matches = fn (OrgMember $m) => $this->domainMatches($domainByMember[$m->id] ?? '', $keywords);

        return $assistants->first(fn (OrgMember $m) => $matches($m) && ! in_array($m->id, $usedIds, true))
            ?? $assistants->first($matches)
            ?? $assistants->first(fn (OrgMember $m) => ! in_array($m->id, $usedIds, true))
            ?? $assistants->first();
    }

    /** @param  array<int, string>  $keywords */
    private function domainMatches(string $domain, array $keywords): bool
    {
        if ($domain === '') {
            return false;
        }
        foreach ($keywords as $kw) {
            if (str_contains($domain, $kw)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Двете предложения за физически спортен център — различни домейни, различна липсваща
     * информация. sourceability private/existing блокират до качване; едно public демонстрира
     * бутона „Позволи търсене в интернет".
     *
     * @return array<int, array{title: string, description: string, domain_keywords: array<int, string>, requirements: array<int, array{label: string, query: string, sourceability: string, how_to_provide: string}>}>
     */
    private function definitions(): array
    {
        return [
            [
                'title' => 'Автоматизирано посрещане и записване на нови членове',
                'description' => 'Асистентът съставя посрещащо съобщение и помага на нови членове да изберат абонамент и да се запишат за първа тренировка според наличните зали, треньори и графици на центъра.',
                'domain_keywords' => ['операц', 'обслужв', 'клиент', 'operation', 'service', 'support', 'customer', 'админ', 'рецепц', 'reception'],
                'requirements' => [
                    [
                        'label' => 'Зали, кортове и съоръжения',
                        'query' => 'зали кортове съоръжения капацитет оборудване работни часове',
                        'sourceability' => 'existing',
                        'how_to_provide' => 'Изброй всички зали/кортове — вид спорт, капацитет, оборудване и работни часове — като бележка в базата знания.',
                    ],
                    [
                        'label' => 'Треньорски състав и графици',
                        'query' => 'треньори специалности график дни часове тренировки',
                        'sourceability' => 'private',
                        'how_to_provide' => 'Опиши треньорите — имена, специалности и дните/часовете, в които водят тренировки.',
                    ],
                    [
                        'label' => 'Абонаментни планове и цени',
                        'query' => 'абонамент план цена месечна годишна карта включени услуги отстъпки',
                        'sourceability' => 'existing',
                        'how_to_provide' => 'Въведи всички абонаментни планове с цени, включени услуги и условия (напр. месечна/годишна карта, студентски отстъпки).',
                    ],
                    [
                        'label' => 'Правила за резервация и отказ',
                        'query' => 'резервация отказ правила записване неявяване срокове',
                        'sourceability' => 'existing',
                        'how_to_provide' => 'Опиши как се резервира тренировка/зала, сроковете за отказ и правилата при неявяване.',
                    ],
                ],
            ],
            [
                'title' => 'Месечен план за съдържание и промоции в социалните мрежи',
                'description' => 'Маркетинг асистентът изготвя месечен календар със съдържание и промоции за Facebook/Instagram на центъра — съобразен с бранда, активните кампании и предстоящите събития.',
                'domain_keywords' => ['маркет', 'market', 'реклам', 'бранд', 'brand', 'social', 'съдържан', 'content', 'комуникац', 'pr'],
                'requirements' => [
                    [
                        'label' => 'Бранд глас, тон и ключови послания',
                        'query' => 'бранд тон глас ключови послания ценности отличие център',
                        'sourceability' => 'private',
                        'how_to_provide' => 'Опиши как звучи марката — тон, ценности, ключови послания и с какво се отличава центърът.',
                    ],
                    [
                        'label' => 'Активни промоции и кампании',
                        'query' => 'промоции отстъпки кампании оферти партньорства',
                        'sourceability' => 'existing',
                        'how_to_provide' => 'Изброй текущите промоции, отстъпки и партньорства, които да рекламираме.',
                    ],
                    [
                        'label' => 'Календар на събития и турнири',
                        'query' => 'събития турнири лагери открити дни график дати',
                        'sourceability' => 'existing',
                        'how_to_provide' => 'Въведи предстоящите събития, турнири, лагери и открити дни с техните дати.',
                    ],
                    [
                        'label' => 'Данни за конкурентни центрове',
                        'query' => 'конкурентни спортни центрове цени услуги пазар',
                        'sourceability' => 'public',
                        'how_to_provide' => '',
                    ],
                ],
            ],
        ];
    }
}
