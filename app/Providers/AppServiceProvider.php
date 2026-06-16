<?php

namespace App\Providers;

use App\Support\QueueHeartbeat;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Events\SupervisorLooped;

class AppServiceProvider extends ServiceProvider
{
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
        // Worker-ите стрелят Looping само МЕЖДУ jobs — при един зает worker с
        // дълъг node (crawl/LLM) heartbeat-ът би изтекъл. Supervisor процесът
        // на Horizon обаче се върти непрекъснато, независимо от заетостта.
        Event::listen(SupervisorLooped::class, function (SupervisorLooped $event): void {
            if ($this->queueIncludesFlows((string) $event->supervisor->options->queue)) {
                $this->markFlowsWorkerAlive();
            }
        });

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
        QueueHeartbeat::markFlowsAlive();
    }
}
