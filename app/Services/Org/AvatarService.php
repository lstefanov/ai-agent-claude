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
    private const SEED_ATTEMPT_STEP = 1_000_003;

    private const SEED_REGEN_STEP = 7_919_191;

    public function __construct(
        private ComfyUIService $comfy,
        private AvatarQualityGate $qualityGate,
    ) {}

    /**
     * Детерминистичен английски SD портретен промпт САМО от демографията (gender/age/
     * ethnicity) — БЕЗ role/tone (§B4): иначе портретът би трябвало да се сменя при смяна
     * на титла/тон, но seed-ът и regen-тригерът не реагират на тях → разсинхрон.
     */
    public function portraitPrompt(Persona $p): string
    {
        $age = $p->age ? (int) $p->age : null;
        $ethnicity = $this->ethnicityWord($p->ethnicity);
        $gender = $this->genderWord($p->gender);

        $subject = trim(implode(' ', array_filter([
            $age ? "{$age}-year-old" : null,
            $ethnicity ?: null,
            $gender,
        ])));
        if ($subject === '') {
            $subject = 'person';
        }

        return "professional corporate close-up headshot portrait of exactly one {$subject}, single person, "
            .'one face only, centered face and shoulders, looking at camera, neutral confident expression, '
            .'full color photograph, soft studio lighting, clean solid neutral background, photorealistic, '
            .'sharp focus, DSLR portrait, no duplicate person, no second face, no split image, no contact sheet, '
            .'no frame, no border, no collage, no grid';
    }

    /**
     * Стабилен seed per член = детерминистичен хеш на org_member_id + демографския подпис
     * (gender|age|ethnicity) → стабилен портрет при ре-рендер, но СЕ СМЕНЯ щом демографията се смени.
     */
    public function seedFor(Persona $p, int $attempt = 0): int
    {
        $signature = $p->org_member_id.'|'.$p->gender.'|'.$p->age.'|'.$p->ethnicity;
        $base = (int) (hexdec(substr(md5($signature), 0, 7)) % 999_999_999) + 1;
        $salt = (int) (is_array($p->avatar_meta) ? ($p->avatar_meta['regen_salt'] ?? 0) : 0);

        return $base + ($salt * self::SEED_REGEN_STEP) + ($attempt * self::SEED_ATTEMPT_STEP);
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
            $p->update([
                'avatar_url' => null,
                'avatar_status' => 'pending',
            ]);
            $this->clearMemberAvatar($p);

            return;
        }

        try {
            $prompt = $this->portraitPrompt($p);
            $result = $this->renderWithQualityRetries($prompt, fn (int $seed) => $this->seedFor($p, $seed));

            if ($result === null) {
                $p->update([
                    'avatar_url' => null,
                    'avatar_status' => 'failed',
                    'avatar_meta' => array_merge(is_array($p->avatar_meta) ? $p->avatar_meta : [], [
                        'quality_reason' => 'max_attempts_exceeded',
                        'attempts' => (int) config('services.comfyui.portrait_max_attempts', 6),
                    ]),
                ]);
                $this->clearMemberAvatar($p);

                return;
            }

            $disk = Storage::disk('public');
            $dst = "avatars/member_{$p->org_member_id}.png";
            $disk->put($dst, $disk->get($result['src']));
            $stableUrl = $disk->url($dst);

            $p->update([
                'avatar_url' => $stableUrl,
                'avatar_prompt' => $prompt,
                'avatar_seed' => $result['seed'],
                'avatar_status' => 'ready',
                'avatar_meta' => array_merge(is_array($p->avatar_meta) ? $p->avatar_meta : [], [
                    'checkpoint' => config('services.comfyui.portrait_checkpoint'),
                    'prompt_id' => $result['prompt_id'],
                    'attempts' => $result['attempt'],
                    'quality_reason' => null,
                ]),
            ]);

            $p->orgMember?->update(['avatar_url' => $stableUrl]);
        } catch (\Throwable $e) {
            Log::warning('[Avatar] generate failed for persona '.$p->id.': '.$e->getMessage());
            $p->update([
                'avatar_url' => null,
                'avatar_status' => 'failed',
                'avatar_meta' => array_merge(is_array($p->avatar_meta) ? $p->avatar_meta : [], [
                    'quality_reason' => 'generation_exception',
                    'error' => mb_substr($e->getMessage(), 0, 240),
                ]),
            ]);
            $this->clearMemberAvatar($p);
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
            $proxy = new Persona([
                'age' => $arch->age,
                'gender' => $arch->gender,
                'ethnicity' => $arch->ethnicity,
            ]);

            $prompt = $this->portraitPrompt($proxy);
            $baseSeed = (int) (hexdec(substr(md5('archetype|'.$arch->name.'|'.$arch->gender.'|'.$arch->age.'|'.$arch->ethnicity), 0, 7)) % 999_999_999) + 1;

            $result = $this->renderWithQualityRetries(
                $prompt,
                fn (int $attempt) => $baseSeed + ($attempt * self::SEED_ATTEMPT_STEP),
            );

            if ($result === null) {
                return false;
            }

            $disk = Storage::disk('public');
            if ($disk->exists($result['src'])) {
                $disk->put($arch->avatar_path, $disk->get($result['src']));

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

    /**
     * @param  callable(int): int  $seedForAttempt  attempt index → seed
     * @return array{src: string, seed: int, prompt_id: string, attempt: int}|null
     */
    private function renderWithQualityRetries(string $prompt, callable $seedForAttempt): ?array
    {
        $maxAttempts = max(1, (int) config('services.comfyui.portrait_max_attempts', 4));
        $disk = Storage::disk('public');
        $lastReason = null;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $seed = $seedForAttempt($attempt);

            $workflow = $this->comfy->buildWorkflow($prompt, [
                'seed' => $seed,
                'checkpoint' => (string) config('services.comfyui.portrait_checkpoint'),
                'negative' => (string) config('services.comfyui.portrait_negative'),
                'width' => (int) config('services.comfyui.portrait_width'),
                'height' => (int) config('services.comfyui.portrait_height'),
                'steps' => (int) config('services.comfyui.portrait_steps'),
                'cfg' => (float) config('services.comfyui.portrait_cfg'),
                'sampler_name' => (string) config('services.comfyui.portrait_sampler'),
                'scheduler' => (string) config('services.comfyui.portrait_scheduler'),
            ]);

            $promptId = $this->comfy->generate($workflow);
            $url = $this->comfy->getResult($promptId);

            if (! $url) {
                $lastReason = 'comfyui_timeout';

                continue;
            }

            $src = "generated/{$promptId}.png";
            if (! $disk->exists($src)) {
                $lastReason = 'missing_output';

                continue;
            }

            $absolute = $disk->path($src);
            $check = $this->qualityGate->passes($absolute);
            if (! $check['ok']) {
                $lastReason = $check['reason'];
                Log::info("[Avatar] quality reject attempt {$attempt}: {$lastReason} (seed {$seed})");

                continue;
            }

            return [
                'src' => $src,
                'seed' => $seed,
                'prompt_id' => $promptId,
                'attempt' => $attempt + 1,
            ];
        }

        Log::warning('[Avatar] all attempts failed'.($lastReason ? ": {$lastReason}" : ''));

        return null;
    }

    private function clearMemberAvatar(Persona $p): void
    {
        $p->orgMember()->update(['avatar_url' => null]);
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

    /** Произход → английска дума за SD (CLIP не разбира кирилица). */
    private function ethnicityWord(?string $ethnicity): string
    {
        $raw = mb_strtolower(trim((string) $ethnicity));
        if ($raw === '') {
            return '';
        }

        $map = [
            'българин' => 'Bulgarian',
            'българка' => 'Bulgarian',
            'български' => 'Bulgarian',
            'bulgarian' => 'Bulgarian',
            'румънин' => 'Romanian',
            'румънка' => 'Romanian',
            'romanian' => 'Romanian',
            'грък' => 'Greek',
            'гъркиня' => 'Greek',
            'greek' => 'Greek',
            'германец' => 'German',
            'германка' => 'German',
            'german' => 'German',
            'турчин' => 'Turkish',
            'туркиня' => 'Turkish',
            'turkish' => 'Turkish',
            'сърбин' => 'Serbian',
            'сръбкиня' => 'Serbian',
            'serbian' => 'Serbian',
        ];

        if (isset($map[$raw])) {
            return $map[$raw];
        }

        if (preg_match('/[\p{Cyrillic}]/u', $raw)) {
            return 'Eastern European';
        }

        $ascii = preg_replace('/[^\p{L}\s\-]/u', '', $raw);

        return trim(mb_substr((string) $ascii, 0, 40));
    }
}
