<?php

namespace App\Jobs\Org;

use App\Models\Persona;
use App\Services\Org\AvatarService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Портретният аватар на члена (§1.3) — `org` queue (ComfyUI рендерът е бавен).
 * Идемпотентен: стабилен файл + demography-обвързан seed. Спрян ComfyUI → 'pending'
 * (по-късен retry през AvatarService::redispatchPending).
 */
class GenerateMemberAvatarJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 2;

    // materialize() диспечи jobs вътре в DB::transaction — изчакай commit, за да няма
    // orphan job за член, който е бил rollback-нат. $afterCommit идва от Queueable
    // (нетипизиран, default null) — задаваме го в конструктора, за да не пада
    // композицията на trait-а.
    public function __construct(public int $personaId)
    {
        $this->afterCommit = true;
    }

    public function handle(AvatarService $avatars): void
    {
        $persona = Persona::find($this->personaId);
        if ($persona) {
            $avatars->generateFor($persona);
        }
    }
}
