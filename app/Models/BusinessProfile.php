<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class BusinessProfile extends Model
{
    protected $fillable = [
        'company_id', 'research', 'interview_answers', 'interview_transcript',
        'situational_analysis', 'pain_points', 'status',
        'problems', 'needs', 'opportunities', 'synthesis_completed_at',
    ];

    protected $casts = [
        'research' => 'array',
        'interview_answers' => 'array',
        'interview_transcript' => 'array',
        'pain_points' => 'array',
        'problems' => 'array',
        'needs' => 'array',
        'opportunities' => 'array',
        'synthesis_completed_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Човешко-четимите фокус-области на бизнеса — храната за смарт-композицията на екипа
     * (§smart-composition): маркираните опции (преведени от стойност → етикет през въпросите
     * в транскрипта) + извлечените болки, дедуплицирани. Празно → композицията пада към
     * blueprint-а на бранша.
     *
     * @return array<int, string>
     */
    public function focusAreas(): array
    {
        // value → label карта по въпрос (от опциите в транскрипта), за да върнем етикети, не машинни value-та.
        $labelByQuestion = [];
        foreach ((array) $this->interview_transcript as $entry) {
            $q = $entry['question'] ?? null;
            if (! is_array($q) || empty($q['key'])) {
                continue;
            }
            foreach ((array) ($q['options'] ?? []) as $opt) {
                if (isset($opt['value'])) {
                    $labelByQuestion[$q['key']][(string) $opt['value']] = (string) ($opt['label'] ?? $opt['value']);
                }
            }
        }

        $areas = [];
        foreach ((array) $this->interview_answers as $key => $values) {
            foreach ((array) $values as $value) {
                $value = (string) $value;
                $areas[] = $labelByQuestion[$key][$value] ?? $value;   // етикет или суров текст (Друго/свободен)
            }
        }
        foreach ((array) $this->pain_points as $pain) {
            $areas[] = (string) $pain;
        }
        // Синтезираните проблеми + нужди обогатяват композицията (§3-part understanding).
        foreach (array_merge((array) $this->problems, (array) $this->needs) as $item) {
            $areas[] = (string) $item;
        }

        return array_values(array_unique(array_filter(array_map('trim', $areas))));
    }

    /**
     * Домейните, изрично маркирани в обзорния въпрос на интервюто (`areas`) — всеки от тях е
     * каталожен домейн и става ГАРАНТИРАН отдел (§structured-sweep). „Друго"/свободен текст се
     * пропуска тук (той влиза през [[focusAreas]] към LLM композицията).
     *
     * @return array<int, string>
     */
    public function selectedDepartmentDomains(): array
    {
        $known = array_keys((array) config('organization.department_catalog', []));
        $marked = array_map('strval', (array) (($this->interview_answers['areas'] ?? [])));

        return array_values(array_intersect($marked, $known));
    }

    /**
     * Добавя един ход (user/assistant) към транскрипта на интервюто под ред-lock,
     * за да се сериализират едновременни записи (без изгубени ъпдейти на JSON колоната).
     */
    public function appendTranscript(array $entry): void
    {
        DB::transaction(function () use ($entry) {
            $fresh = static::whereKey($this->getKey())->lockForUpdate()->first();
            if (! $fresh) {
                return;
            }
            $transcript = (array) $fresh->interview_transcript;
            $transcript[] = $entry;
            $fresh->update(['interview_transcript' => $transcript]);
            $this->setRawAttributes($fresh->getAttributes(), true);
        });
    }
}
