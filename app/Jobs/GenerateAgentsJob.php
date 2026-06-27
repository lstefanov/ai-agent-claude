<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

/**
 * Генерация на агентите за един flow през Horizon (`org` queue) вместо detached `exec`
 * (§8.3) — наблюдаемо, без риск от стар код/конфиг, под worker дисциплината на repo-то.
 * Polling contract-ът остава непроменен (кешът `agent_gen_{token}` се пише от командата).
 */
class GenerateAgentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Дълга planner операция — собствен timeout (като ScheduledTaskJob). */
    public int $timeout = 1200;

    /** Без слепи retry-та: командата сама записва failed + refund при провал. */
    public int $tries = 1;

    public function __construct(public string $token) {}

    public function handle(): void
    {
        Artisan::call('flows:generate-agents', ['token' => $this->token]);
    }
}
