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

    public function __construct(public int $personaId) {}

    public function handle(AvatarService $avatars): void
    {
        $persona = Persona::find($this->personaId);
        if ($persona) {
            $avatars->generateFor($persona);
        }
    }
}
