<?php

namespace App\Providers;

use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    private const FLOWS_QUEUE_HEARTBEAT_KEY = 'queue.heartbeat.flows';

    private const FLOWS_QUEUE_HEARTBEAT_TTL = 180;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(Looping::class, function (Looping $event): void {
            if ($this->queueIncludesFlows((string) $event->queue)) {
                $this->markFlowsWorkerAlive();
            }
        });

        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            $queue = method_exists($event->job, 'getQueue') ? (string) $event->job->getQueue() : '';

            if ($queue === 'flows') {
                $this->markFlowsWorkerAlive();
            }
        });
    }

    private function queueIncludesFlows(string $queue): bool
    {
        return in_array('flows', array_map('trim', explode(',', $queue)), true);
    }

    private function markFlowsWorkerAlive(): void
    {
        Cache::put(self::FLOWS_QUEUE_HEARTBEAT_KEY, now()->timestamp, self::FLOWS_QUEUE_HEARTBEAT_TTL);
    }
}
