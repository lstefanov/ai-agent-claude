<?php

namespace App\Console\Commands\Org;

use App\Models\PersonaArchetype;
use App\Services\Org\AvatarService;
use Illuminate\Console\Command;

/**
 * Еднократно генериране на готовите портрети за casting-кандидатите (примерни Управители).
 * Синхронно през ComfyUI → стабилни файлове в storage/app/public/archetypes/. Идемпотентно
 * (презаписва). Изисква работещ ComfyUI; иначе репортва skip за всеки кандидат.
 */
class GenerateArchetypeAvatarsCommand extends Command
{
    protected $signature = 'org:archetype-avatars {--role=manager : Persona archetype role to render portraits for}';

    protected $description = 'Render ComfyUI portraits for casting persona archetypes (one-off, idempotent)';

    public function handle(AvatarService $avatars): int
    {
        $archetypes = PersonaArchetype::where('role', (string) $this->option('role'))
            ->whereNotNull('avatar_path')
            ->get();

        if ($archetypes->isEmpty()) {
            $this->warn('No archetypes with avatar_path found for role '.$this->option('role').'.');

            return self::SUCCESS;
        }

        $ready = 0;
        foreach ($archetypes as $arch) {
            if ($avatars->generateArchetype($arch)) {
                $this->info("✓ {$arch->name} → {$arch->avatar_path}");
                $ready++;
            } else {
                $this->warn("✗ {$arch->name} — портретът не е генериран (ComfyUI спрян или грешка).");
            }
        }

        $this->info("Archetype portraits generated: {$ready}/{$archetypes->count()}");

        return $ready === $archetypes->count() ? self::SUCCESS : self::FAILURE;
    }
}
