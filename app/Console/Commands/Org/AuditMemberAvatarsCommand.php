<?php

namespace App\Console\Commands\Org;

use App\Jobs\Org\GenerateMemberAvatarJob;
use App\Models\Persona;
use App\Services\Org\AvatarQualityGate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Сканира готови org аватари и регенерира тези, които не минават QA (ч/б, колаж, рамка).
 */
class AuditMemberAvatarsCommand extends Command
{
    protected $signature = 'org:avatars-audit {--dry-run : Само отчет, без dispatch на jobs}';

    protected $description = 'Scan ready member avatars and re-queue those that fail quality checks';

    public function handle(AvatarQualityGate $gate): int
    {
        $disk = Storage::disk('public');
        $dryRun = (bool) $this->option('dry-run');
        $bad = 0;
        $checked = 0;

        $personas = Persona::query()
            ->where('avatar_status', 'ready')
            ->whereNotNull('org_member_id')
            ->get();

        foreach ($personas as $persona) {
            $path = "avatars/member_{$persona->org_member_id}.png";

            $checked++;
            $result = $disk->exists($path)
                ? $gate->passes($disk->path($path))
                : ['ok' => false, 'reason' => 'missing_file'];

            if ($result['ok']) {
                continue;
            }

            $bad++;
            $label = "member_{$persona->org_member_id} (persona {$persona->id}): {$result['reason']}";
            $this->warn("✗ {$label}");

            if ($dryRun) {
                continue;
            }

            $meta = is_array($persona->avatar_meta) ? $persona->avatar_meta : [];
            $meta['quality_reason'] = $result['reason'];
            $meta['quality_audited_at'] = now()->toIso8601String();

            $persona->update([
                'avatar_url' => null,
                'avatar_status' => 'pending',
                'avatar_meta' => $meta,
            ]);
            $persona->orgMember()->update(['avatar_url' => null]);

            GenerateMemberAvatarJob::dispatch($persona->id, (string) Str::uuid())->onQueue('org');
            $this->info('  → queued for regeneration');
        }

        $this->info("Checked {$checked} avatars, {$bad} failed quality".($dryRun ? ' (dry-run)' : ' and queued'));

        return self::SUCCESS;
    }
}
