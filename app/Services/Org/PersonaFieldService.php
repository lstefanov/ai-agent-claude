<?php

namespace App\Services\Org;

use App\Models\Company;
use App\Services\GeneratorService;
use Illuminate\Support\Str;

/**
 * „✨ Генерирай с AI" за ЕДНО персона-поле (произход/бекграунд/тон/био). Company-scoped
 * (casting/design-review нямат записана персона) — гради контекст от бизнеса + ролята +
 * вече попълнените полета и връща само стойността, орязана до DB лимита. Един промпт-източник.
 */
class PersonaFieldService
{
    /** Per-field максимуми = DB колоните / input maxlength. */
    private const MAX = ['name' => 80, 'ethnicity' => 40, 'background' => 120, 'tone' => 80, 'bio' => 600];

    public function __construct(private GeneratorService $llm) {}

    /** @param  array<string,mixed>  $context  вече попълнени полета (name/age/tone/traits/...) */
    public function suggest(Company $company, string $field, ?string $role, array $context): string
    {
        $meta = config("persona.fields.{$field}");
        if (! $meta) {
            return '';
        }

        $value = trim($this->llm->assist(
            systemPrompt: $this->systemPrompt($field, $meta),
            userMessage: $this->userMessage($company, $field, $role, $context),
            options: ['temperature' => 0.7, 'num_predict' => $field === 'bio' ? 300 : 80],
            provider: (string) config('persona.assist.provider', 'openai'),
            model: (string) config('persona.assist.model', 'gpt-4o-mini'),
        ));

        // Махни обгръщащи кавички (някои модели ги слагат) + кламп до DB max.
        $value = trim($value, " \t\n\r\"'«»„“");

        return mb_substr($value, 0, self::MAX[$field] ?? 200);
    }

    /** @param  array<string,mixed>  $meta */
    private function systemPrompt(string $field, array $meta): string
    {
        $label = $meta['label'] ?? $field;
        $guidance = $meta['guidance'] ?? '';
        $limit = match ($field) {
            'name' => 'Само собствено и фамилно име (две думи), без титли.',
            'ethnicity' => 'Една-две думи (произход/националност).',
            'background' => 'Кратка фраза, до 120 символа.',
            'tone' => 'Няколко прилагателни, разделени със запетая, до 80 символа.',
            'bio' => 'Едно-две изречения, до ~400 символа.',
            default => 'Кратко.',
        };

        return 'Ти си Управителят на AI организация и съставяш профил за служител в екипа. '
            ."Генерирай САМО стойността за полето «{$label}» — без въведение, без обяснения, без кавички. "
            ."Насока: {$guidance} {$limit} Пиши на български.";
    }

    /** @param  array<string,mixed>  $context */
    private function userMessage(Company $company, string $field, ?string $role, array $context): string
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

        $lines[] = "Дай стойност за «{$field}», уместна за бизнеса и съгласувана с горното.";

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
