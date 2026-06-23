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
    /** Български етикети на познатите черти (0–100). */
    private const TRAIT_LABELS = [
        'risk' => 'риск',
        'creativity' => 'креативност',
        'precision' => 'прецизност',
        'autonomy' => 'автономност',
        'tempo' => 'темпо',
    ];

    /**
     * Демография → стартови черти (0–100). Само ПОДСКАЗВА (§5.3): 24 г. → +креативност/
     * +риск/−формалност; 59 г. → +прецизност/−риск. Характерът оформя подхода, не компетентността.
     *
     * @return array<string, int>
     */
    public function seedTraitsFromDemographics(?int $age, ?string $gender, ?string $background): array
    {
        // Неутрална база.
        $t = ['risk' => 50, 'creativity' => 50, 'precision' => 50, 'autonomy' => 60, 'tempo' => 55];

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
            $lines[] = "Черти (как подхождаш, не дали си компетентен): {$traits}.";
        }

        // Инвариант: характерът оформя КАК, не качеството/верността на резултата.
        $lines[] = 'Дръж този характер и тон последователно. Компетентността и качеството на '.
            'резултата остават водещи — характерът оформя подхода, не верността.';

        return implode("\n", $lines);
    }

    /**
     * Черти → runtime knobs (§5.2). star_tier е само ПОДСКАЗВА (авторитетното ниво е на
     * члена); finalizeOrganization го ползва при сетването на default_star_tier.
     *
     * @return array<string, mixed>
     */
    public function deriveKnobs(Persona $persona): array
    {
        $tr = (array) $persona->traits;
        $creativity = (int) ($tr['creativity'] ?? 50);
        $precision = (int) ($tr['precision'] ?? 50);
        $risk = (int) ($tr['risk'] ?? 50);
        $autonomy = (int) ($tr['autonomy'] ?? 60);
        $tempo = (int) ($tr['tempo'] ?? 55);

        // Температура от креативност (clamp 0.4..1.0).
        $temperature = round(max(0.4, min(1.0, 0.4 + $creativity / 100 * 0.6)), 2);

        // star_tier ПОДСКАЗВА: висока прецизност → по-високо ниво за критичните.
        $starHint = $precision >= 80 ? 'high' : ($precision >= 55 ? 'medium' : 'low');

        // Агресивност на одобренията от риск/автономност (по-смел → по-автономен).
        $boldness = ($risk + $autonomy) / 2;
        $approval = $boldness >= 70 ? 'auto' : ($boldness >= 45 ? 'approve_first_then_auto' : 'approve_each');

        return [
            'temperature' => $temperature,
            'star_tier' => $starHint,
            'tool_bias' => $creativity >= $precision ? 'creative' : 'analytical',
            'parallelism' => $tempo >= 70 ? 3 : ($tempo >= 45 ? 2 : 1),
            'approval_aggressiveness' => $approval,
        ];
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
                'traits' => array_map(fn ($v) => max(0, min(100, (int) $v)), (array) $traits),
                'archetype_key' => $fields['archetype_key'] ?? ($existing->archetype_key ?? null),
            ],
        );

        // Runtime knobs от чертите.
        $persona->update(['derived_knobs' => $this->deriveKnobs($persona)]);

        // Портрет: регенерирай само при нова/променена демография (§1.1/§7.5).
        $newSignature = $this->demographicSignature($persona);
        if (config('organization.persona.portraits') && $newSignature !== $oldSignature) {
            $persona->update(['avatar_status' => 'pending']);
            GenerateMemberAvatarJob::dispatch($persona->id)->onQueue('org');
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
        foreach (self::TRAIT_LABELS as $key => $label) {
            if (isset($traits[$key]) && is_numeric($traits[$key])) {
                $parts[] = "{$label} ".(int) $traits[$key].'/100';
            }
        }

        return implode(', ', $parts);
    }
}
