<?php

namespace App\Jobs\Org;

use App\Models\Director;
use App\Services\Org\DirectorAgentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Директорски tick (§4.2) — `org` queue (НЕ flows). DirectorAgentService::tick.
 */
class DirectorTickJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;

    public int $tries = 1;

    public function __construct(public int $directorId, public string $trigger = 'scheduled') {}

    public function handle(DirectorAgentService $directors): void
    {
        $director = Director::with('orgVersion.company', 'orgMember')->find($this->directorId);
        if ($director) {
            $directors->tick($director, $this->trigger);
        }
    }
}
