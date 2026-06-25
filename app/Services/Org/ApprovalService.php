<?php

namespace App\Services\Org;

use App\Models\FlowRun;
use App\Services\GraphFlowExecutor;

/**
 * Единственият resume-after-approval boundary (§0.5.7). И FlowRunController::approval,
 * И DecisionBoxService минават оттук — никакъв controller→controller call. Resume-логиката
 * е една, тук. Approve → resumeAfterApproval; reject → fail() (което settle-ва билинга, §A4).
 */
class ApprovalService
{
    public function __construct(private GraphFlowExecutor $executor) {}

    /**
     * Урежда едно одобрение на паузиран human_approval възел.
     *
     * @return array{ok: bool, status?: string, error?: string}
     */
    public function settle(FlowRun $flowRun, string $nodeKey, bool $approved, ?string $comment = null): array
    {
        $flowRun = $flowRun->fresh();

        if ($flowRun->status !== 'waiting_approval') {
            return ['ok' => false, 'error' => 'Изпълнението не чака одобрение.'];
        }

        $nodeRun = $flowRun->nodeRuns()->where('node_key', $nodeKey)->where('status', 'paused')->first();
        if (! $nodeRun) {
            return ['ok' => false, 'error' => 'Този възел не чака одобрение.'];
        }

        $comment = trim((string) $comment);

        // Одит в контекста на run-а (поллингът го праща към UI-я).
        $context = $flowRun->fresh()->context ?? [];
        $context['approvals'][$nodeKey] = array_merge($context['approvals'][$nodeKey] ?? [], [
            'status' => $approved ? 'approved' : 'rejected',
            'comment' => $comment !== '' ? $comment : null,
            'decided_at' => now()->toISOString(),
        ]);
        $flowRun->update(['context' => $context]);

        if ($approved) {
            $nodeRun->update([
                'status' => 'completed',
                'output' => 'Одобрено от потребителя.'.($comment !== '' ? "\nКоментар: {$comment}" : ''),
                'completed_at' => now(),
            ]);

            $this->executor->resumeAfterApproval($flowRun, $nodeKey);
        } else {
            $message = "Отхвърлено от потребителя на стъпка „{$nodeRun->flowNode?->name}“."
                .($comment !== '' ? " Причина: {$comment}" : '');

            $nodeRun->update([
                'status' => 'failed',
                'error' => $message,
                'completed_at' => now(),
            ]);

            // fail() settle-ва реалното похарчено + refund на остатъка (§A4).
            $this->executor->fail($flowRun->fresh(), $message);
        }

        return ['ok' => true, 'status' => $flowRun->fresh()->status];
    }
}
