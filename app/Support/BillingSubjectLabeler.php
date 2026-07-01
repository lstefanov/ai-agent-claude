<?php

namespace App\Support;

use App\Models\AssistantTask;
use App\Models\Company;
use App\Models\Flow;
use App\Models\FlowDraft;
use App\Models\FlowRun;
use App\Models\OrgMember;
use Illuminate\Support\Collection;

/**
 * Превежда полиморфен билинг субект (credit_reservations.subject_type/id) към човешки
 * български етикет за историята на кредитите / org разбивката. Batch resolve (без N+1):
 * групира id-та по тип, прави по една заявка на тип.
 */
class BillingSubjectLabeler
{
    /**
     * @param  iterable<array{0: ?string, 1: ?int}>  $pairs  [subjectType (morph), subjectId]
     * @return array<string, string> ключ "Basename#id" → етикет
     */
    public static function labels(iterable $pairs): array
    {
        $byType = [];
        foreach ($pairs as [$type, $id]) {
            if ($type && $id) {
                $byType[class_basename($type)][] = (int) $id;
            }
        }

        $out = [];
        foreach ($byType as $base => $ids) {
            $ids = array_values(array_unique($ids));
            foreach (self::resolveType($base, $ids) as $id => $label) {
                $out["{$base}#{$id}"] = $label;
            }
        }

        return $out;
    }

    public static function label(?string $type, ?int $id): ?string
    {
        if (! $type || ! $id) {
            return null;
        }

        return self::labels([[$type, $id]])[class_basename($type).'#'.$id] ?? null;
    }

    /** @return array<int, string> id → етикет за един морф тип. */
    private static function resolveType(string $base, array $ids): Collection
    {
        return match ($base) {
            'OrgMember' => OrgMember::whereIn('id', $ids)->get(['id', 'display_name', 'kind'])
                ->mapWithKeys(fn ($m) => [$m->id => 'Член: '.($m->display_name ?: ($m->kind ?: "#{$m->id}"))]),
            'AssistantTask' => AssistantTask::whereIn('id', $ids)->get(['id', 'title'])
                ->mapWithKeys(fn ($t) => [$t->id => 'Задача: '.($t->title ?: "#{$t->id}")]),
            'FlowRun' => FlowRun::whereIn('id', $ids)->with('flow:id,name')->get()
                ->mapWithKeys(fn ($r) => [$r->id => 'Изпълнение: '.($r->flow?->name ?: "run #{$r->id}")]),
            'Flow' => Flow::whereIn('id', $ids)->get(['id', 'name'])
                ->mapWithKeys(fn ($f) => [$f->id => 'Flow: '.($f->name ?: "#{$f->id}")]),
            'FlowDraft' => FlowDraft::whereIn('id', $ids)->get(['id', 'title'])
                ->mapWithKeys(fn ($d) => [$d->id => 'Чернова: '.($d->title ?: "#{$d->id}")]),
            'Company' => Company::whereIn('id', $ids)->get(['id', 'name'])
                ->mapWithKeys(fn ($c) => [$c->id => 'Фирма: '.($c->name ?: "#{$c->id}")]),
            default => collect($ids)->mapWithKeys(fn ($id) => [$id => "{$base} #{$id}"]),
        };
    }
}
