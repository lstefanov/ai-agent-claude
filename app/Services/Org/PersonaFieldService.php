<?php

namespace App\Services\Org;

use App\Models\Company;
use App\Services\GeneratorService;
use App\Services\Org\Billing\BillableOperationService;
use Illuminate\Support\Str;

/**
 * „✨ Генерирай с AI" за ЕДНО персона-поле (произход/бекграунд/тон/био). Company-scoped
 * (casting/design-review нямат записана персона) — гради контекст от бизнеса + ролята +
 * вече попълнените полета и връща само стойността, орязана до DB лимита. Един промпт-източник.
 */
class PersonaFieldService
{
    /** Per-field максимуми = DB колоните / input maxlength (синхрон с _persona-fields.blade.php). */
    private const MAX = ['name' => 80, 'ethnicity' => 40, 'background' => 240, 'tone' => 120, 'bio' => 600];

    public function __construct(
        private GeneratorService $llm,
        private BillableOperationService $billable,
    ) {}

    /**
     * @param  array<string,mixed>  $context  вече попълнени полета (name/age/tone/traits/...)
     * @param  string|null  $seed  базов текст (стойност на полето от избрания архетип) — при
     *                             наличие промптът „специализира" базата за бизнеса вместо да гради наново.
     */
    public function suggest(Company $company, string $field, ?string $role, array $context, ?string $seed = null): string
    {
        $meta = config("persona.fields.{$field}");
        if (! $meta) {
            return '';
        }

        $seed = filled($seed) ? trim((string) $seed) : null;

        // Таксуваме реални кредити към фирмата (best-effort — не блокираме при липса).
        $value = trim($this->billable->run(
            companyId: $company->id,
            contextType: 'text_assist',
            subject: null,
            work: fn () => $this->llm->assist(
                systemPrompt: $this->systemPrompt($field, $meta, $seed),
                userMessage: $this->userMessage($company, $field, $role, $context, $seed),
                options: ['temperature' => 0.7, 'num_predict' => match ($field) {
                    'bio' => 400, 'background' => 200, 'tone' => 120, default => 80
                }],
                provider: (string) config('persona.assist.provider', 'openai'),
                model: (string) config('persona.assist.model', 'gpt-4o-mini'),
            ),
            opKey: (string) Str::uuid(),
        ));

        // Махни обгръщащи кавички (някои модели ги слагат) + кламп до DB max.
        $value = trim($value, " \t\n\r\"'«»„“");

        return mb_substr($value, 0, self::MAX[$field] ?? 200);
    }

    /** @param  array<string,mixed>  $meta */
    private function systemPrompt(string $field, array $meta, ?string $seed = null): string
    {
        $label = $meta['label'] ?? $field;
        $guidance = $meta['guidance'] ?? '';
        $limit = match ($field) {
            'name' => 'Само собствено и фамилно име (две думи), без титли.',
            'ethnicity' => 'Една-две думи (произход/националност).',
            'background' => 'Цяло изречение (минимум 12 думи): конкретен опит — години, сфери и постижения; до 240 символа.',
            'tone' => 'Точно 3–4 прилагателни, разделени със запетая (напр. „делови, прецизен, спокоен, прям"); до 120 символа.',
            'bio' => 'Две-три пълни изречения за характера и подхода, до ~400 символа.',
            default => 'Кратко.',
        };

        // Със seed → специализираме базовия текст за бизнеса; иначе градим наново.
        $task = $seed !== null
            ? "Имаш базов вариант за полето «{$label}». Преработи го за конкретния бизнес — запази характера/духа и формата му, но го направи специфичен и обвързан с дейността. Върни САМО новата стойност — без въведение, без обяснения, без кавички. "
            : "Генерирай САМО стойността за полето «{$label}» — без въведение, без обяснения, без кавички. ";

        return 'Ти си Управителят на AI организация и съставяш профил за служител в екипа. '
            .$task
            ."Насока: {$guidance} {$limit} Пиши на български.";
    }

    /** @param  array<string,mixed>  $context */
    private function userMessage(Company $company, string $field, ?string $role, array $context, ?string $seed = null): string
    {
        $lines = ["Бизнес: {$company->name} ({$company->industry})."];

        $profile = $company->businessProfile;
        if ($profile && ! empty($profile->pain_points)) {
            $lines[] = 'Болки: '.Str::limit(implode('; ', (array) $profile->pain_points), 300);
        }
        if (filled($role)) {
            $lines[] = "Роля на служителя: {$role}.";
        }

        // Вече попълнени полета → кохерентност (без текущото поле).
        $labels = ['name' => 'Име', 'age' => 'Възраст', 'gender' => 'Пол', 'ethnicity' => 'Произход', 'background' => 'Бекграунд', 'tone' => 'Тон', 'bio' => 'Био'];
        $known = [];
        foreach ($labels as $k => $label) {
            if ($k !== $field && filled($context[$k] ?? null)) {
                $known[] = "{$label}: ".Str::limit((string) $context[$k], 120);
            }
        }
        if ($known) {
            $lines[] = 'Известно за служителя — '.implode(', ', $known).'.';
        }

        // Чертите 0–100 → ориентир за тона/подхода (риск/креативност/...).
        if ($traits = $this->traitLine($context['traits'] ?? null)) {
            $lines[] = "Черти (0–100): {$traits}.";
        }

        if ($seed !== null) {
            $label = $labels[$field] ?? $field;
            $lines[] = "Базов вариант за «{$label}»: ".Str::limit($seed, 400);
            $lines[] = 'Специализирай този базов вариант за бизнеса — запази характера, но го направи конкретен и обвързан с дейността.';
        } else {
            $lines[] = "Дай стойност за «{$field}», уместна за бизнеса и съгласувана с горното.";
        }

        return implode("\n", $lines);
    }

    /** Черти-обект → „Риск 78, Креативност 85, ..." (само валидните 0–100). */
    private function traitLine(mixed $traits): string
    {
        if (! is_array($traits)) {
            return '';
        }

        $parts = [];
        foreach ((array) config('persona.traits') as $key => $meta) {
            if (is_numeric($traits[$key] ?? null)) {
                $parts[] = ($meta['label'] ?? $key).' '.(int) $traits[$key];
            }
        }

        return implode(', ', $parts);
    }
}
