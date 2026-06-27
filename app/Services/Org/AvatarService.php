<?php

namespace App\Services\Org;

use App\Jobs\Org\GenerateMemberAvatarJob;
use App\Models\Company;
use App\Models\Persona;
use App\Models\PersonaArchetype;
use App\Services\ComfyUIService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Портретният аватар на члена (§5.1) — ИЗЦЯЛО козметичен, не влияе на поведението
 * (решение 7 / §5.3). Аватарите са на измислени персони. Преизползва ComfyUIService
 * (с детерминистични overrides), не го преимплементира. Мек деградейшън при спрян ComfyUI.
 */
class AvatarService
{
    public function __construct(private ComfyUIService $comfy) {}

    /**
     * Детерминистичен английски SD портретен промпт САМО от демографията (gender/age/
     * ethnicity) — БЕЗ role/tone (§B4): иначе портретът би трябвало да се сменя при смяна
     * на титла/тон, но seed-ът и regen-тригерът не реагират на тях → разсинхрон.
     */
    public function portraitPrompt(Persona $p): string
    {
        $age = $p->age ? (int) $p->age : null;
        $ethnicity = $this->sanitize($p->ethnicity);
        $gender = $this->genderWord($p->gender);

        $subject = trim(implode(' ', array_filter([
            $age ? "{$age}-year-old" : null,
            $ethnicity ?: null,
            $gender,
        ])));
        if ($subject === '') {
            $subject = 'person';
        }

        // Изражението е ФИКСИРАНО/неутрално — не идва от tone.
        return "professional corporate headshot portrait of a {$subject}, neutral confident expression, "
            .'soft studio lighting, neutral background, photorealistic, sharp focus, 4K, DSLR portrait';
    }

    /**
     * Стабилен seed per член = детерминистичен хеш на org_member_id + демографския подпис
     * (gender|age|ethnicity) → стабилен портрет при ре-рендер, но СЕ СМЕНЯ щом демографията се смени.
     */
    public function seedFor(Persona $p): int
    {
        $signature = $p->org_member_id.'|'.$p->gender.'|'.$p->age.'|'.$p->ethnicity;

        return (int) (hexdec(substr(md5($signature), 0, 7)) % 999_999_999) + 1;
    }

    /**
     * Оркестрира портрета през ComfyUIService. Спрян ComfyUI → avatar_status='pending'
     * (UI fallback инициали). Иначе детерминистичен workflow → стабилен файл
     * avatars/member_{id}.png (URL постоянен между ре-рендери). Грешка → 'failed'. Идемпотентен.
     */
    public function generateFor(Persona $p): void
    {
        if (! config('organization.persona.portraits')) {
            return;
        }

        if (! $this->comfy->isAvailable()) {
            $p->update(['avatar_status' => 'pending']);   // UI fallback инициали + по-късен retry

            return;
        }

        try {
            $prompt = $this->portraitPrompt($p);
            $seed = $this->seedFor($p);

            $workflow = $this->comfy->buildWorkflow($prompt, [
                'seed' => $seed,
                'checkpoint' => (string) config('services.comfyui.portrait_checkpoint'),
                'negative' => (string) config('services.comfyui.portrait_negative'),
            ]);

            $promptId = $this->comfy->generate($workflow);
            $url = $this->comfy->getResult($promptId);

            if (! $url) {
                $p->update(['avatar_status' => 'failed']);

                return;
            }

            // Копираме рендера към СТАБИЛЕН път, за да е URL-ът постоянен между ре-рендери.
            $disk = Storage::disk('public');
            $src = "generated/{$promptId}.png";
            $dst = "avatars/member_{$p->org_member_id}.png";
            if ($disk->exists($src)) {
                $disk->put($dst, $disk->get($src));
            }
            $stableUrl = $disk->url($dst);

            $p->update([
                'avatar_url' => $stableUrl,
                'avatar_prompt' => $prompt,
                'avatar_seed' => $seed,
                'avatar_status' => 'ready',
                'avatar_meta' => [
                    'checkpoint' => config('services.comfyui.portrait_checkpoint'),
                    'prompt_id' => $promptId,
                ],
            ]);

            // Денормализиран avatar_url в org_members (за бързи roster заявки).
            $p->orgMember?->update(['avatar_url' => $stableUrl]);
        } catch (\Throwable $e) {
            Log::warning('[Avatar] generate failed for persona '.$p->id.': '.$e->getMessage());
            $p->update(['avatar_status' => 'failed']);
        }
    }

    /**
     * Re-dispatch на висящи/провалени аватари (§B4) — щом ComfyUI е наличен. Идемпотентно
     * (стабилен файл + demography-seed), безопасно за повторно викане. Без ComfyUI → no-op.
     */
    public function redispatchPending(?Company $company = null): int
    {
        if (! $this->comfy->isAvailable()) {
            return 0;
        }

        $query = Persona::whereIn('avatar_status', ['pending', 'failed']);
        if ($company) {
            $query->whereHas('orgMember', fn ($m) => $m->where('company_id', $company->id));
        }

        $count = 0;
        foreach ($query->get() as $persona) {
            GenerateMemberAvatarJob::dispatch($persona->id)->onQueue('org');
            $count++;
        }

        return $count;
    }

    /**
     * Генерира готов портрет за casting-архетип (примерен Управител) и го запазва на стабилен
     * път ($arch->avatar_path) на public диска. Преизползва същия демография-промпт като членовете.
     * Спрян ComfyUI / липсващ път / провал → false (no-op, без хвърляне). Идемпотентен (презапис).
     */
    public function generateArchetype(PersonaArchetype $arch): bool
    {
        if (! $arch->avatar_path || ! $this->comfy->isAvailable()) {
            return false;
        }

        try {
            // Транзитна персона само за да преизползваме portraitPrompt() (демография → промпт).
            $proxy = new Persona([
                'age' => $arch->age,
                'gender' => $arch->gender,
                'ethnicity' => $arch->ethnicity,
            ]);

            $prompt = $this->portraitPrompt($proxy);
            $seed = (int) (hexdec(substr(md5('archetype|'.$arch->name.'|'.$arch->gender.'|'.$arch->age.'|'.$arch->ethnicity), 0, 7)) % 999_999_999) + 1;

            $workflow = $this->comfy->buildWorkflow($prompt, [
                'seed' => $seed,
                'checkpoint' => (string) config('services.comfyui.portrait_checkpoint'),
                'negative' => (string) config('services.comfyui.portrait_negative'),
            ]);

            $promptId = $this->comfy->generate($workflow);
            $url = $this->comfy->getResult($promptId);

            if (! $url) {
                return false;
            }

            $disk = Storage::disk('public');
            $src = "generated/{$promptId}.png";
            if ($disk->exists($src)) {
                $disk->put($arch->avatar_path, $disk->get($src));

                return true;
            }

            return false;
        } catch (\Throwable $e) {
            Log::warning('[Avatar] archetype generate failed for '.$arch->name.': '.$e->getMessage());

            return false;
        }
    }

    /**
     * Наемане от готов кандидат → преизползваме готовия портрет вместо нов ComfyUI рендер.
     * Намираме архетипа по archetype_key; ако демографията съвпада (gender/age/ethnicity) И
     * файлът съществува → копираме archetypes/...png → avatars/member_{id}.png и сетваме 'ready'.
     * Иначе false → извикващият пуска нормалната генерация.
     */
    public function reuseArchetypeAvatar(Persona $p): bool
    {
        if (! $p->archetype_key) {
            return false;
        }

        $arch = PersonaArchetype::where('name', $p->archetype_key)
            ->whereNotNull('avatar_path')
            ->first();
        if (! $arch) {
            return false;
        }

        // Демографията трябва да съвпада — иначе готовият портрет вече не отговаря → рендираме наново.
        $same = (int) $p->age === (int) $arch->age
            && mb_strtolower(trim((string) $p->gender)) === mb_strtolower(trim((string) $arch->gender))
            && mb_strtolower(trim((string) $p->ethnicity)) === mb_strtolower(trim((string) $arch->ethnicity));
        if (! $same) {
            return false;
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($arch->avatar_path)) {
            return false;
        }

        $dst = "avatars/member_{$p->org_member_id}.png";
        $disk->put($dst, $disk->get($arch->avatar_path));
        $url = $disk->url($dst);

        $p->update([
            'avatar_url' => $url,
            'avatar_prompt' => $this->portraitPrompt($p),
            'avatar_seed' => $this->seedFor($p),
            'avatar_status' => 'ready',
            'avatar_meta' => ['source' => 'archetype', 'archetype' => $arch->name],
        ]);
        $p->orgMember?->update(['avatar_url' => $url]);

        return true;
    }

    /** Превежда пол → английска дума за промпта (whitelist). */
    private function genderWord(?string $gender): string
    {
        return match (mb_strtolower(trim((string) $gender))) {
            'male', 'man', 'мъж', 'мъжки', 'м' => 'man',
            'female', 'woman', 'жена', 'женски', 'ж' => 'woman',
            default => 'person',
        };
    }

    /** Whitelist санитизация на свободни демографски стрингове преди SD промпта. */
    private function sanitize(?string $value): string
    {
        $clean = preg_replace('/[^\p{L}\s\-]/u', '', (string) $value);

        return trim(mb_substr((string) $clean, 0, 40));
    }
}
