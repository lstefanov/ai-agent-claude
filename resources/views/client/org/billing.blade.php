@extends('layouts.client')

@section('title', 'Кредити & планове')

@section('content')
@php
    $levelStars = ['low' => '★', 'medium' => '★★', 'high' => '★★★', 'ultra' => '★★★★', 'god' => '★★★★★'];
    $currentPlanKey = $subscription?->plan?->key;
@endphp
<div class="max-w-5xl mx-auto px-6 py-8"
     x-data="billing({
        subscribeUrl: '{{ route('client.org.billing.subscribe') }}',
        topUpUrl: '{{ route('client.org.billing.top-up') }}',
        csrf: '{{ csrf_token() }}',
        balance: {{ (int) $wallet->balance }},
     })">
    <h1 class="text-2xl font-semibold text-ink mb-1">Кредити & планове</h1>
    <p class="text-muted mb-6">Кредитите са горивото — харчат се при всяко пускане. Звездите са мощността (нивото на модела).</p>

    <div class="grid md:grid-cols-3 gap-4 mb-8">
        <div class="rounded-xl border border-line bg-surface p-5">
            <p class="text-xs font-mono uppercase tracking-wider text-muted">Баланс</p>
            <p class="text-3xl font-semibold text-ink tabular-nums mt-1" x-text="balance"></p>
            <p class="text-xs text-subtle">кредита</p>
        </div>
        <div class="rounded-xl border border-line bg-surface p-5">
            <p class="text-xs font-mono uppercase tracking-wider text-muted">Текущ план</p>
            <p class="text-lg font-semibold text-ink mt-1">{{ $subscription?->plan?->name ?? 'Без план' }}</p>
            <p class="text-xs text-subtle">макс. ниво: <span class="text-star tabular-nums">{{ $levelStars[$subscription?->plan?->max_star_tier] ?? '—' }}</span></p>
        </div>
        <div class="rounded-xl border border-line bg-surface p-5">
            <p class="text-xs font-mono uppercase tracking-wider text-muted">Зареди кредити</p>
            <div class="flex items-center gap-2 mt-2">
                <input type="number" min="100" step="100" x-model.number="topUpAmount" class="w-24 rounded-lg border border-line bg-surface px-2 py-1.5 text-sm tabular-nums">
                <x-button size="sm" x-on:click="topUp()" x-bind:disabled="busy">Купи</x-button>
            </div>
            <p x-show="msg" x-text="msg" class="text-xs text-success-strong mt-2"></p>
        </div>
    </div>

    {{-- Планове --}}
    <h2 class="text-sm font-semibold text-ink mb-3">Планове</h2>
    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-8">
        @foreach ($plans as $plan)
            @php($isCurrent = $plan->key === $currentPlanKey)
            <div class="rounded-xl border p-5 flex flex-col {{ $isCurrent ? 'border-primary ring-1 ring-primary/30' : 'border-line' }} bg-surface">
                <p class="font-semibold text-ink">{{ $plan->name }}</p>
                <p class="text-2xl font-semibold text-ink tabular-nums mt-1">{{ number_format($plan->price_cents / 100, 0) }} <span class="text-sm text-muted">лв/мес</span></p>
                <p class="text-xs text-muted mt-2 tabular-nums">{{ number_format($plan->monthly_credits) }} кредита/мес</p>
                <p class="text-xs text-subtle">до <span class="text-star">{{ $levelStars[$plan->max_star_tier] ?? '★' }}</span> ниво</p>
                <div class="mt-auto pt-4">
                    @if ($isCurrent)
                        <span class="text-xs font-medium text-success-strong">✓ Текущ план</span>
                    @else
                        <x-button size="sm" class="w-full" x-on:click="subscribe('{{ $plan->key }}')" x-bind:disabled="busy">Избери</x-button>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    {{-- Разход (ledger) --}}
    <h2 class="text-sm font-semibold text-ink mb-3">Последен разход</h2>
    @if ($ledger->isEmpty())
        <p class="text-sm text-subtle">Още няма движения.</p>
    @else
        <div class="rounded-xl border border-line bg-surface divide-y divide-line">
            @foreach ($ledger as $entry)
                <div class="flex items-center justify-between gap-4 px-4 py-2.5 text-sm">
                    <span class="text-muted">{{ $entry->reason }} · {{ $entry->type }}</span>
                    <span class="tabular-nums {{ $entry->direction === 'credit' ? 'text-success-strong' : 'text-ink' }}">{{ $entry->direction === 'credit' ? '+' : '−' }}{{ $entry->amount }}</span>
                </div>
            @endforeach
        </div>
    @endif
</div>

@push('scripts')
<script>
function billing(cfg) {
    return {
        balance: cfg.balance, topUpAmount: 1000, busy: false, msg: '',
        post(url, body) {
            this.busy = true; this.msg = '';
            return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json' }, body: JSON.stringify(body) })
                .then(r => r.json()).finally(() => this.busy = false);
        },
        topUp() {
            if (!this.topUpAmount || this.topUpAmount <= 0) return;
            this.post(cfg.topUpUrl, { credits: this.topUpAmount }).then(d => { if (d.ok) { this.balance = d.balance; this.msg = 'Заредено!'; } else this.msg = d.error || 'Грешка.'; });
        },
        subscribe(plan) {
            this.post(cfg.subscribeUrl, { plan }).then(d => { if (d.ok) location.reload(); else this.msg = d.error || 'Грешка.'; });
        },
    };
}
</script>
@endpush
@endsection
