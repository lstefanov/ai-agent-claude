<?php

namespace App\Services\Org;

use App\Models\OrgMember;

/**
 * Персона слой (§5). Във Фаза 0.5 имплементираме само compileSystemPrompt — персона
 * блокът, инжектиран в runtime промпта на org-flow нодовете. Демография→черти,
 * deriveKnobs и архетипите идват във Фаза 1.
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
