<?php

namespace App\Providers;

use App\Services\Org\Billing\AdminSimulatedPaymentProvider;
use App\Services\Org\Billing\PaymentProvider;
use App\Services\Org\Billing\StripePaymentProvider;
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
        // Платежният слой е зад интерфейс (§0.5.4). Drop-in (§6.2): Stripe когато
        // STRIPE_SECRET е зададен, иначе админ-симулиран. Останалата кредитна логика не се пипа.
        $this->app->singleton(PaymentProvider::class, fn ($app) => config('billing.stripe.secret')
            ? $app->make(StripePaymentProvider::class)
            : $app->make(AdminSimulatedPaymentProvider::class));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Worker-ите стрелят Looping само МЕЖДУ jobs — при един зает worker с
        // дълъг node (crawl/LLM) heartbeat-ът би изтекъл. Supervisor процесът
        // на Horizon обаче се върти непрекъснато, независимо от заетостта.
        // Опресняваме heartbeat за flows И за org (§0.5.8) от същия hook.
        Event::listen(SupervisorLooped::class, function (SupervisorLooped $event): void {
            $this->refreshHeartbeats((string) $event->supervisor->options->queue);
        });

        Event::listen(Looping::class, function (Looping $event): void {
            $this->refreshHeartbeats((string) $event->queue);
        });

        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            $queue = method_exists($event->job, 'getQueue') ? (string) $event->job->getQueue() : '';
            $this->refreshHeartbeats($queue);
        });
    }

    /** Опреснява heartbeat-ите за опашките, които този супервайзор/worker обслужва. */
    private function refreshHeartbeats(string $queue): void
    {
        $queues = array_map('trim', explode(',', $queue));

        if (in_array('flows', $queues, true)) {
            QueueHeartbeat::markFlowsAlive();
        }
        if (in_array('org', $queues, true)) {
            QueueHeartbeat::markOrgAlive();
        }
    }
}
