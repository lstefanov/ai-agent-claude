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
        'problems', 'needs', 'opportunities', 'synthesis_completed_at', 'synthesis_version',
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
        $research = (array) $this->research;
        foreach ((array) ($research['suggested_areas'] ?? []) as $area) {
            if (! is_array($area)) {
                continue;
            }
            $label = trim((string) ($area['label'] ?? $area['domain'] ?? ''));
            $reason = trim((string) ($area['reason'] ?? ''));
            if ($label !== '') {
                $areas[] = $reason !== '' ? "{$label}: {$reason}" : $label;
            }
        }
        foreach ((array) data_get($research, 'report.likely_needs', []) as $need) {
            $areas[] = (string) $need;
        }
        foreach ((array) data_get($research, 'report.automation_opportunities', []) as $opportunity) {
            $areas[] = (string) $opportunity;
        }
        foreach (array_slice((array) ($research['gaps'] ?? []), 0, 6) as $gap) {
            if (is_array($gap) && ! empty($gap['question'])) {
                $areas[] = 'Да се изясни: '.(string) $gap['question'];
            }
        }
        // Синтезираните проблеми + нужди обогатяват композицията (§3-part understanding).
        foreach (array_merge((array) $this->problems, (array) $this->needs) as $item) {
            $areas[] = (string) $item;
        }

        return array_slice(array_values(array_unique(array_filter(array_map('trim', $areas)))), 0, 40);
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

    /**
     * Последните N разговора като човек-четим чат (Собственик/Управител) — за prompt-ите
     * на followUpReply и depthQuestion. Свободният текст има приоритет; при празен
     * assistant-ход с въпрос — показваме текста на въпроса.
     */
    public function chatHistory(int $limit = 12): string
    {
        $rows = array_slice(array_values((array) $this->interview_transcript), -$limit * 2);
        $lines = [];
        foreach ($rows as $e) {
            $role = ($e['role'] ?? '') === 'user' ? 'Собственик' : 'Управител';
            $text = trim((string) ($e['content'] ?? ''));
            if ($text === '' && ! empty($e['question']['text'])) {
                $text = trim((string) $e['question']['text']);
            }
            if ($text !== '') {
                $lines[] = "{$role}: {$text}";
            }
        }

        return implode("\n", $lines);
    }

    /** Брой записи в транскрипта — за детекция на растеж (re-synthesis). */
    public function transcriptVersion(): int
    {
        return count((array) $this->interview_transcript);
    }
}
