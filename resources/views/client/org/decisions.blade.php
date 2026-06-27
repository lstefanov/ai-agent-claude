@extends('layouts.client')

@section('title', 'Решения')

@section('content')
<div x-data="decisions({
        approveUrl: '{{ route('client.org.decisions.approve') }}',
        rejectUrl: '{{ route('client.org.decisions.reject') }}',
        csrf: '{{ csrf_token() }}',
     })" class="space-y-6">
    <div>
        <h1 class="text-2xl font-semibold text-ink">Решения</h1>
        <p class="text-muted">Чакащи предложения от екипа и стъпки за одобрение. Едно място за всичко.</p>
    </div>

    @if ($pending->isEmpty())
        <x-empty-state title="Няма чакащи решения" description="Когато екипът предложи задача или изпълнение спре за одобрение, ще се появи тук." />
    @else
        <div class="space-y-3">
            @foreach ($pending as $item)
                @php $uid = $item['kind'].'-'.($item['id'] ?? (($item['flow_run_id'] ?? '').'-'.($item['node_key'] ?? ''))); @endphp
                <div class="rounded-xl border border-line bg-surface p-4" x-show="!done.includes('{{ $uid }}')">
                    @if ($item['kind'] === 'assistant_task')
                        @php
                            $proposal = (array) ($item['proposal'] ?? []);
                            $steps = (array) ($proposal['steps'] ?? []);
                            $tools = (array) ($proposal['tools'] ?? []);
                            $est = (array) ($proposal['estimated_cost'] ?? []);
                            $member = $item['member'] ?? null;
                            $c = $member?->functionColor() ?? 'blue';
                        @endphp
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex items-start gap-3 min-w-0">
                                @if ($member?->persona?->hasReadyAvatar())
                                    <img src="{{ $member->persona->avatar_url }}" alt="" class="h-10 w-10 rounded-full object-cover ring-2 ring-char-{{ $c }}-soft">
                                @else
                                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-char-{{ $c }}-soft text-char-{{ $c }}-strong font-semibold ring-2 ring-char-{{ $c }}-soft">{{ mb_strtoupper(mb_substr($member?->fullName() ?? '?', 0, 1)) }}</span>
                                @endif
                                <div class="min-w-0">
                                    <p class="font-medium text-ink truncate">{{ $item['title'] }}</p>
                                    <p class="text-xs text-muted truncate">Предложено от {{ $member?->fullName() }} · {{ $member?->roleTitle() }}</p>
                                </div>
                            </div>
                            <x-badge color="warning">Предложена задача</x-badge>
                        </div>

                        @if (! empty($proposal['rationale']))
                            <div class="mt-3 rounded-lg bg-surface-subtle p-3 text-sm text-ink">
                                <x-prose :text="$proposal['rationale']" />
                                @if (! empty($proposal['expected_impact']))<p class="text-xs text-muted mt-1.5">Очакван ефект: {{ $proposal['expected_impact'] }}</p>@endif
                            </div>
                        @endif

                        @if ($steps)
                            <ol class="mt-3 space-y-1 text-sm text-muted">
                                @foreach (array_slice($steps, 0, 4) as $s)
                                    <li class="flex gap-2"><span class="text-subtle tabular-nums">{{ $s['order'] ?? $loop->iteration }}.</span><span>{{ $s['summary'] ?? $s['node_name'] ?? '' }}</span></li>
                                @endforeach
                            </ol>
                        @endif

                        <div class="mt-3 flex flex-wrap items-center gap-2 text-xs">
                            @foreach (array_slice($tools, 0, 5) as $t)<span class="px-1.5 py-0.5 rounded bg-surface-subtle text-subtle">{{ $t }}</span>@endforeach
                            @if (isset($est['credits']))<span class="text-subtle tabular-nums ml-auto">~{{ $est['credits'] }} кредита</span>@endif
                        </div>

                        <div class="mt-4 flex items-center justify-end gap-2">
                            @if ($item['flow_id'])<a href="{{ route('client.flows.show', $item['flow_id']) }}" class="text-xs text-primary hover:text-primary-hover mr-auto">Прегледай flow-а →</a>@endif
                            <button type="button" x-on:click="rejectTask(@js($item['id']))" class="text-sm text-danger hover:text-danger-strong font-medium">Отхвърли</button>
                            <x-button size="sm" variant="secondary" x-on:click="approveTask(@js($item['id']), false)">Одобри</x-button>
                            <x-button size="sm" x-on:click="approveTask(@js($item['id']), true)">Одобри и пусни</x-button>
                        </div>
                    @elseif ($item['kind'] === 'proposal')
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <x-badge color="accent">Структурно · {{ $item['type'] }}</x-badge>
                                <p class="font-medium text-ink mt-1"><x-prose :text="$item['payload']['title'] ?? ucfirst($item['type'])" inline /></p>
                                @if (! empty($item['payload']['description']))<x-prose :text="$item['payload']['description']" class="text-sm text-muted mt-0.5" />@endif
                                @if (! empty($item['payload']['proposed_by']))<p class="text-xs text-subtle mt-1">Предложено от {{ $item['payload']['proposed_by'] }}</p>@endif
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <x-button size="sm" variant="secondary" x-on:click="act('reject', @js($item))">Отхвърли</x-button>
                                <x-button size="sm" x-on:click="act('approve', @js($item))">Одобри</x-button>
                            </div>
                        </div>
                    @else
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <x-badge color="warning">Изпълнение чака одобрение</x-badge>
                                <p class="font-medium text-ink mt-1">{{ $item['node_name'] }}</p>
                                <p class="text-sm text-muted">Изпълнение #{{ $item['flow_run_id'] }} спря на тази стъпка.</p>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <x-button size="sm" variant="secondary" x-on:click="act('reject', @js($item))">Отхвърли</x-button>
                                <x-button size="sm" x-on:click="act('approve', @js($item))">Одобри</x-button>
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    {{-- Reject reason modal (за предложени задачи) --}}
    <div x-show="rejecting" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @keydown.escape.window="rejecting = null">
        <div class="w-full max-w-md rounded-xl bg-surface border border-line shadow-popover p-5 space-y-3" @click.outside="rejecting = null">
            <h2 class="text-base font-semibold text-ink">Защо не одобряваме?</h2>
            <p class="text-sm text-muted">Причината влиза в паметта на служителя — следващите предложения ще я отчитат.</p>
            <textarea x-model="reason" rows="3" class="w-full rounded-md border border-line bg-surface px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40" placeholder="Кратка причина…"></textarea>
            <div class="flex justify-end gap-2">
                <x-button variant="ghost" size="sm" x-on:click="rejecting = null">Отказ</x-button>
                <x-button variant="danger" size="sm" x-on:click="submitReject()">Отхвърли</x-button>
            </div>
        </div>
    </div>

    <div x-show="error" x-cloak class="fixed bottom-4 right-4 z-50 rounded-lg bg-danger-soft text-danger-strong px-4 py-2 text-sm shadow-card" x-text="error"></div>
</div>

@push('scripts')
<script>
function decisions(cfg) {
    return {
        done: [],
        rejecting: null,
        reason: '',
        error: '',
        async _post(url, body) {
            this.error = '';
            try {
                const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json' }, body: JSON.stringify(body) });
                const d = await r.json().catch(() => ({}));
                if (!r.ok || !d.ok) {
                    if (d.superseded) { alert(d.error || 'Организацията се промени — нужно е ре-ревю.'); location.reload(); return null; }
                    this.error = d.message || d.error || 'Грешка.'; return null;
                }
                return d;
            } catch (e) { this.error = 'Мрежова грешка.'; return null; }
        },
        // Структурни предложения + run approvals.
        async act(decision, item) {
            const url = decision === 'approve' ? cfg.approveUrl : cfg.rejectUrl;
            const body = item.kind === 'proposal'
                ? { kind: 'proposal', id: item.id }
                : { kind: 'run_approval', flow_run_id: item.flow_run_id, node_key: item.node_key };
            const d = await this._post(url, body);
            if (d) this.done.push(item.kind + '-' + (item.id || (item.flow_run_id + '-' + item.node_key)));
        },
        // Предложени задачи.
        async approveTask(id, run) {
            const d = await this._post(cfg.approveUrl, { kind: 'assistant_task', id, run });
            if (d) this.done.push('assistant_task-' + id);
        },
        rejectTask(id) { this.rejecting = id; this.reason = ''; },
        async submitReject() {
            if (!this.reason.trim()) return;
            const id = this.rejecting;
            const d = await this._post(cfg.rejectUrl, { kind: 'assistant_task', id, reason: this.reason });
            if (d) { this.done.push('assistant_task-' + id); this.rejecting = null; }
        },
    };
}
</script>
@endpush
@endsection
