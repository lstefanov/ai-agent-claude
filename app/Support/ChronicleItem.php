<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Един нормализиран запис в единния поток на Хрониката — резултатът от сливането
 * на разнородните източници (org_events / flow_runs / knowledge_events / credit_ledger)
 * в общ вид. Service-ът сглобява и сортира тези обекти; `toArray()` ги предава на
 * Blade слоя (партиалите четат масив, по конвенцията на OrgGraphService::memberCard()).
 */
final readonly class ChronicleItem
{
    /**
     * @param  string  $source  org_event|flow_run|knowledge|credit
     * @param  int  $sourceRank  приоритет на източника за развързване на равни секунди
     * @param  ?array<string,mixed>  $member  memberCard()-shape за _member-avatar (или null)
     * @param  array<string,mixed>  $detail  payload за разгъването (meta/цена/връзки)
     */
    public function __construct(
        public string $source,
        public int $dbId,
        public int $sourceRank,
        public string $type,
        public Carbon $occurredAt,
        public string $title,
        public ?string $actorLabel,
        public ?array $member,
        public ?string $href,
        public array $detail = [],
        public ?string $context = null,
        public ?string $amount = null,
        public ?string $amountTone = null,
    ) {}

    /** Стабилен DOM ключ: "flow_run:123". */
    public function key(): string
    {
        return $this->source.':'.$this->dbId;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $p = ChronicleType::presentation($this->type);

        return [
            'key' => $this->key(),
            'source' => $this->source,
            'type' => $this->type,
            'group' => $p['group'],
            'icon' => $p['icon'],
            'color' => $p['color'],
            'color_classes' => ChronicleType::colorClasses($p['color']),
            'label' => $p['label'],
            'at' => $this->occurredAt,
            'day' => $this->occurredAt->format('Y-m-d'),
            'time' => $this->occurredAt->format('H:i'),
            'title' => $this->title,
            'context' => $this->context,
            'amount' => $this->amount,
            'amount_tone' => $this->amountTone,
            'actor' => $this->actorLabel,
            'member' => $this->member,
            'href' => $this->href,
            'detail' => $this->detail,
        ];
    }
}
