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
            if (! $disk->exists($path)) {
                continue;
            }

            $checked++;
            $result = $gate->passes($disk->path($path));

            if ($result['ok']) {
                continue;
            }

            $bad++;
            $label = "member_{$persona->org_member_id} (persona {$persona->id}): {$result['reason']}";
            $this->warn("✗ {$label}");

            if ($dryRun) {
                continue;
            }

            $persona->update(['avatar_status' => 'pending']);
            GenerateMemberAvatarJob::dispatch($persona->id)->onQueue('org');
            $this->info('  → queued for regeneration');
        }

        $this->info("Checked {$checked} avatars, {$bad} failed quality".($dryRun ? ' (dry-run)' : ' and queued'));

        return self::SUCCESS;
    }
}
