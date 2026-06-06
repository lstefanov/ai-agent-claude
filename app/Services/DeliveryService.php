<?php

namespace App\Services;

use App\Models\FlowRun;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Delivers a completed run's final_output to the flow's configured channel
 * (email / Slack / webhook / file). Invoked best-effort from
 * GraphFlowExecutor::finalize — a delivery failure never fails the run; the
 * outcome is recorded in flow_runs.context['delivery'] for the run viewer.
 *
 * Channel + target live in flows.settings['delivery'] (set on the flow page).
 */
class DeliveryService
{
    public function deliver(FlowRun $flowRun): void
    {
        $config = (array) ($flowRun->flow->settings['delivery'] ?? []);
        $channel = (string) ($config['channel'] ?? 'none');

        if ($channel === '' || $channel === 'none') {
            return;
        }

        $output = (string) ($flowRun->final_output ?? '');
        if (trim($output) === '') {
            $this->record($flowRun, $channel, false, 'Няма финален изход за доставка.');

            return;
        }

        try {
            $detail = match ($channel) {
                'email' => $this->deliverEmail($flowRun, $config, $output),
                'slack' => $this->deliverSlack($config, $output),
                'webhook' => $this->deliverWebhook($flowRun, $config, $output),
                'file' => $this->deliverFile($flowRun, $output),
                default => throw new RuntimeException("Непознат канал за доставка: {$channel}."),
            };
            $this->record($flowRun, $channel, true, $detail);
        } catch (Throwable $e) {
            Log::warning("[Delivery] Flow {$flowRun->flow_id} run {$flowRun->id} delivery failed: ".$e->getMessage());
            $this->record($flowRun, $channel, false, $e->getMessage());
        }
    }

    private function deliverEmail(FlowRun $flowRun, array $config, string $output): string
    {
        $to = trim((string) ($config['target'] ?? ''));
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Невалиден email адрес за доставка.');
        }
        $subject = trim((string) ($config['subject'] ?? '')) ?: ('Резултат от flow: '.$flowRun->flow->name);

        Mail::raw($output, function ($message) use ($to, $subject) {
            $message->to($to)->subject($subject);
        });

        return "Изпратено до {$to}";
    }

    private function deliverSlack(array $config, string $output): string
    {
        $url = $this->validUrl($config['target'] ?? '');
        // Slack message text has a generous but finite limit — keep it safe.
        Http::timeout(10)->post($url, ['text' => mb_substr($output, 0, 38000)])->throw();

        return 'Изпратено към Slack webhook.';
    }

    private function deliverWebhook(FlowRun $flowRun, array $config, string $output): string
    {
        $url = $this->validUrl($config['target'] ?? '');
        Http::timeout(15)->post($url, [
            'flow_run_id' => $flowRun->id,
            'flow_id' => $flowRun->flow_id,
            'flow_name' => $flowRun->flow->name,
            'final_output' => $output,
            'completed_at' => $flowRun->completed_at?->toISOString(),
        ])->throw();

        return 'POST към webhook успешен.';
    }

    private function deliverFile(FlowRun $flowRun, string $output): string
    {
        $path = "deliveries/run-{$flowRun->id}.md";
        Storage::put($path, $output);

        return 'Записано във файл: '.Storage::path($path);
    }

    /** SSRF guard — only http/https webhooks (mirrors WebhookSenderAgent). */
    private function validUrl(mixed $url): string
    {
        $url = trim((string) $url);
        if (! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new RuntimeException('Невалиден webhook URL — трябва да започва с http:// или https://.');
        }

        return $url;
    }

    private function record(FlowRun $flowRun, string $channel, bool $ok, string $detail): void
    {
        $context = $flowRun->fresh()->context ?? [];
        $context['delivery'] = [
            'channel' => $channel,
            'ok' => $ok,
            'detail' => $detail,
            'at' => now()->toISOString(),
        ];
        $flowRun->update(['context' => $context]);
    }
}
