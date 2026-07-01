<?php

namespace App\Services\Org;

use App\Jobs\Org\GenerateMemberAvatarJob;
use App\Models\OrgMember;
use App\Models\Persona;
use App\Models\PersonaArchetype;
use Illuminate\Support\Collection;

/**
 * Двигателят на персоните (§5): демография → стартови черти (само ПОДСКАЗВА, §5.3),
 * черти → runtime knobs, персона блок за system prompt (характер, НЕ компетентност),
 * upsert на персоната + регенерация на портрета при смяна на демографията.
 */
class PersonaService
{
    private const DEFAULT_TRAITS = ['risk' => 50, 'creativity' => 50, 'precision' => 50, 'autonomy' => 60, 'tempo' => 55];

    /** Български етикети на познатите черти (0–100). */
    private const TRAIT_LABELS = [
        'risk' => 'риск',
        'creativity' => 'креативност',
        'precision' => 'прецизност',
        'autonomy' => 'автономност',
        'tempo' => 'темпо',
    ];

    public function __construct(private AvatarService $avatars) {}

    /**
     * Демография → стартови черти (0–100). Само ПОДСКАЗВА (§5.3): 24 г. → +креативност/
     * +риск/−формалност; 59 г. → +прецизност/−риск. Характерът оформя подхода, не компетентността.
     *
     * @return array<string, int>
     */
    public function seedTraitsFromDemographics(?int $age, ?string $gender, ?string $background): array
    {
        // Неутрална база.
        $t = self::DEFAULT_TRAITS;

        if ($age !== null) {
            if ($age <= 30) {
                $t['risk'] += 25;
                $t['creativity'] += 25;
                $t['precision'] -= 10;
                $t['tempo'] += 15;
            } elseif ($age >= 55) {
                $t['risk'] -= 20;
                $t['creativity'] -= 5;
                $t['precision'] += 30;
                $t['tempo'] -= 10;
            } elseif ($age >= 45) {
                $t['precision'] += 12;
                $t['risk'] -= 8;
            }
        }

        // Бекграунд подсказва (творчески vs финансов/инженерен).
        $bg = mb_strtolower((string) $background);
        if ($bg !== '') {
            if (preg_match('/creativ|market|design|медиа|маркетинг|творч/u', $bg)) {
                $t['creativity'] += 12;
            }
            if (preg_match('/financ|account|engineer|финанс|счетов|инженер|оператив/u', $bg)) {
                $t['precision'] += 12;
                $t['risk'] -= 8;
            }
        }

        return array_map(fn ($v) => max(0, min(100, (int) $v)), $t);
    }

    /**
     * Сглобява ПЕРСОНА БЛОК за члена — характер/ценности/стил, НЕ компетентност (§5.3).
     * Празен низ, ако членът няма персона (тогава инжекцията е no-op).
     */
    public function compileSystemPrompt(OrgMember $member): string
    {
        $persona = $member->persona;
        if (! $persona) {
            return '';
        }

        $lines = ['[ПЕРСОНА]'];

        $name = $persona->name ?: $member->display_name;
        $intro = "Ти си {$name}, {$member->display_name}.";
        if ($persona->age) {
            $intro .= " На {$persona->age} години.";
        }
        $lines[] = $intro;

        if ($persona->bio) {
            $lines[] = $persona->bio;
        }
        if ($persona->tone) {
            $lines[] = "Тон: {$persona->tone}.";
        }

        $traits = $this->formatTraits((array) $persona->traits);
        if ($traits !== '') {
            $lines[] = "Черти (как подхождаш, не колко си компетентен): {$traits}.";
        }

        if (($runtime = $this->runtimePrompt($member)) !== '') {
            $lines[] = $runtime;
        }

        // Инвариант: характерът оформя КАК, не качеството/верността на резултата.
        $lines[] = 'Дръж този характер и тон последователно. Компетентността и качеството на '.
            'резултата остават водещи — характерът оформя подхода, не верността.';

        return implode("\n", $lines);
    }

    /**
     * Черти → реални runtime настройки (§5.2). Тези стойности се четат от задачите,
     * планера, директорските цикли, чата и изпълнението на възлите.
     *
     * @return array<string, mixed>
     */
    public function deriveKnobs(Persona $persona): array
    {
        $tr = $this->normalizeTraits((array) $persona->traits);
        $creativity = (int) ($tr['creativity'] ?? 50);
        $precision = (int) ($tr['precision'] ?? 50);
        $risk = (int) ($tr['risk'] ?? 50);
        $autonomy = (int) ($tr['autonomy'] ?? 60);
        $tempo = (int) ($tr['tempo'] ?? 55);

        // Температура от креативност (clamp 0.4..1.0).
        $temperature = round(max(0.4, min(1.0, 0.4 + $creativity / 100 * 0.6)), 2);
        $creativeTemperature = round(max(0.45, min(1.1, $temperature + (($risk - 50) / 100 * 0.12))), 2);
        $factualTemperature = round(max(0.1, min(0.65, $temperature - ($precision / 100 * 0.45))), 2);
        $plannerTemperature = round(max(0.2, min(0.8, ($creativeTemperature + $factualTemperature) / 2)), 2);

        // Висока прецизност реално повишава наследеното ниво на задачите.
        $starHint = $precision >= 80 ? 'high' : ($precision >= 55 ? 'medium' : 'low');

        // Агресивност на одобренията от риск/автономност (по-смел → по-самостоятелен).
        $boldness = ($risk + $autonomy) / 2;
        $approval = $boldness >= 70 ? 'auto' : ($boldness >= 45 ? 'approve_first_then_auto' : 'approve_each');
        $parallelism = $tempo >= 70 ? 3 : ($tempo >= 45 ? 2 : 1);
        $qaThreshold = $precision >= 85 ? 75 : ($precision >= 65 ? 68 : ($precision <= 35 ? 55 : 60));
        $proposalLimit = $risk >= 70 ? 3 : ($risk >= 45 ? 2 : 1);

        return [
            'temperature' => $temperature,
            'creative_temperature' => $creativeTemperature,
            'factual_temperature' => $factualTemperature,
            'planner_temperature' => $plannerTemperature,
            'star_tier' => $starHint,
            'tool_bias' => $creativity >= $precision ? 'creative' : 'analytical',
            'parallelism' => $parallelism,
            'director_task_limit' => $parallelism,
            'qa_threshold' => $qaThreshold,
            'proposal_limit' => $proposalLimit,
            'approval_aggressiveness' => $approval,
            'approval_policy' => $approval,
        ];
    }

    /**
     * Runtime policy за член. Връща същите ключове като derived_knobs плюс нормализирани
     * traits; изчислява се от текущите traits, за да няма разминаване при редакция.
     *
     * @return array<string, mixed>
     */
    public function runtimePolicy(OrgMember $member): array
    {
        $persona = $member->persona;
        if (! $persona) {
            return $this->neutralPolicy();
        }

        return $this->deriveKnobs($persona) + [
            'traits' => $this->normalizeTraits((array) $persona->traits),
        ];
    }

    /** Структуриран prompt блок за реалното влияние на чертите. */
    public function runtimePrompt(OrgMember $member): string
    {
        $persona = $member->persona;
        if (! $persona) {
            return '';
        }

        $policy = $this->runtimePolicy($member);
        $traits = (array) $policy['traits'];

        $risk = (int) $traits['risk'];
        $creativity = (int) $traits['creativity'];
        $precision = (int) $traits['precision'];
        $autonomy = (int) $traits['autonomy'];
        $tempo = (int) $traits['tempo'];

        $approval = match ($policy['approval_policy']) {
            'auto' => 'при ясна задача действа самостоятелно и активира позволеното без излишно чакане',
            'approve_first_then_auto' => 'за нови задачи иска първо одобрение, после работи по-самостоятелно',
            default => 'пита често и чака потвърждение преди нова посока',
        };
        $toolBias = ($policy['tool_bias'] ?? 'analytical') === 'creative'
            ? 'търси повече варианти, формулировки и творчески решения'
            : 'предпочита проверени факти, подредени аргументи и по-строга проверка';

        return "[ПОВЕДЕНИЕ ОТ ЧЕРТИ]\n"
            ."- Риск {$risk}/100: ".($risk >= 70 ? 'смело предлага промени и инициативи' : ($risk <= 35 ? 'избягва несигурни ходове' : 'балансира предпазливост и инициатива')).".\n"
            ."- Креативност {$creativity}/100: ".($creativity >= 70 ? 'пише по-свободно и предлага повече варианти' : ($creativity <= 35 ? 'следва по-стриктно формата' : 'смесва яснота с умерена изобретателност')).".\n"
            ."- Прецизност {$precision}/100: ".($precision >= 70 ? 'проверява фактите и формата по-строго' : ($precision <= 35 ? 'предпочита бързина пред подробна проверка' : 'пази разумна точност')).".\n"
            ."- Автономност {$autonomy}/100: {$approval}.\n"
            ."- Темпо {$tempo}/100: ".($tempo >= 70 ? 'движи няколко неща в един цикъл' : ($tempo <= 35 ? 'работи последователно и внимателно' : 'поддържа умерен ритъм')).".\n"
            ."- Инструменти и подход: {$toolBias}.";
    }

    /**
     * Създава/обновява персоната на члена (upsert по org_member_id). Ако портретите са
     * включени и демографията (gender/age/ethnicity) е нова/променена → нулира
     * avatar_status='pending' и диспечира GenerateMemberAvatarJob (§1.1).
     *
     * @param  array<string, mixed>  $fields
     */
    public function attachTo(OrgMember $member, array $fields): Persona
    {
        $existing = $member->persona;
        $oldSignature = $existing ? $this->demographicSignature($existing) : null;

        // Черти: подадени явно или изведени от демографията (само подсказка).
        $traits = $fields['traits'] ?? $this->seedTraitsFromDemographics(
            $fields['age'] ?? null,
            $fields['gender'] ?? null,
            $fields['background'] ?? null,
        );

        $persona = Persona::updateOrCreate(
            ['org_member_id' => $member->id],
            [
                'name' => $fields['name'] ?? $member->display_name,
                'ethnicity' => $fields['ethnicity'] ?? ($existing->ethnicity ?? null),
                'age' => $fields['age'] ?? ($existing->age ?? null),
                'gender' => $fields['gender'] ?? ($existing->gender ?? null),
                'background' => $fields['background'] ?? ($existing->background ?? null),
                'education' => $fields['education'] ?? ($existing->education ?? null),
                'bio' => $fields['bio'] ?? ($existing->bio ?? null),
                'tone' => $fields['tone'] ?? ($existing->tone ?? null),
                'traits' => $this->normalizeTraits((array) $traits),
                // Стабилни умения (§10.2) — генерирани при дизайн, редактируеми после.
                'skills' => array_key_exists('skills', $fields) ? array_values((array) $fields['skills']) : ($existing->skills ?? null),
                'archetype_key' => $fields['archetype_key'] ?? ($existing->archetype_key ?? null),
            ],
        );

        // Runtime knobs от чертите.
        $persona->update(['derived_knobs' => $this->deriveKnobs($persona)]);

        // Портрет: регенерирай само при нова/променена демография (§1.1/§7.5). Ако членът е
        // нает от готов casting-кандидат (archetype_key + съвпадаща демография) → копираме
        // готовия портрет вместо нов ComfyUI рендер.
        $newSignature = $this->demographicSignature($persona);
        if (config('organization.persona.portraits') && $newSignature !== $oldSignature) {
            if (! $this->avatars->reuseArchetypeAvatar($persona)) {
                $persona->update(['avatar_status' => 'pending']);
                GenerateMemberAvatarJob::dispatch($persona->id, (string) Str::uuid())->onQueue('org');
            }
        }

        return $persona->fresh();
    }

    /**
     * Кандидат-архетипи за casting (по роля и по избор вертикал). Embedding-match е
     * по-нататъшно подобрение — тук филтрираме по роля/вертикал.
     *
     * @return Collection<int, PersonaArchetype>
     */
    public function archetypes(string $vertical, string $role): Collection
    {
        return PersonaArchetype::where('role', $role)
            ->where(fn ($q) => $q->whereNull('vertical')->orWhere('vertical', $vertical))
            ->get();
    }

    /** Демографският подпис, който задейства регенерация на портрета. */
    private function demographicSignature(Persona $persona): string
    {
        return implode('|', [$persona->gender, $persona->age, $persona->ethnicity]);
    }

    /** „риск 78/100, креативност 85/100, …" от traits масива. */
    private function formatTraits(array $traits): string
    {
        $parts = [];
        foreach ($this->normalizeTraits($traits) as $key => $value) {
            $label = self::TRAIT_LABELS[$key] ?? $key;
            $parts[] = "{$label} {$value}/100";
        }

        return implode(', ', $parts);
    }

    /** @return array<string, int> */
    private function normalizeTraits(array $traits): array
    {
        $normalized = [];
        foreach (self::DEFAULT_TRAITS as $key => $default) {
            $value = $traits[$key] ?? $default;
            $normalized[$key] = max(0, min(100, (int) $value));
        }

        return $normalized;
    }

    /** @return array<string, mixed> */
    private function neutralPolicy(): array
    {
        $persona = new Persona(['traits' => self::DEFAULT_TRAITS]);

        return $this->deriveKnobs($persona) + ['traits' => self::DEFAULT_TRAITS];
    }
}
