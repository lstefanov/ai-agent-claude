@extends('layouts.client')

@section('title', 'Кутия за решения')

@section('content')
<div class="max-w-4xl mx-auto px-6 py-8"
     x-data="decisions({
        approveUrl: '{{ route('client.org.decisions.approve') }}',
        rejectUrl: '{{ route('client.org.decisions.reject') }}',
        csrf: '{{ csrf_token() }}',
     })">
    <h1 class="text-2xl font-semibold text-ink mb-1">Кутия за решения</h1>
    <p class="text-muted mb-6">Чакащи предложения от екипа и стъпки за одобрение. Едно място за всичко.</p>

    @if ($pending->isEmpty())
        <x-empty-state title="Няма чакащи решения" description="Когато екипът предложи промяна или задача спре за одобрение, ще се появи тук." />
    @else
        <div class="space-y-3">
            @foreach ($pending as $item)
                <div class="rounded-xl border border-line bg-surface p-4 flex items-start justify-between gap-4"
                     x-show="!done.includes('{{ $item['kind'] }}-{{ $item['id'] ?? ($item['flow_run_id'].'-'.$item['node_key']) }}')">
                    <div class="min-w-0">
                        @if ($item['kind'] === 'proposal')
                            <span class="inline-block text-[11px] font-mono uppercase tracking-wider text-char-purple-strong bg-char-purple-soft rounded px-1.5 py-0.5 mb-1">Предложение · {{ $item['type'] }}</span>
                            <p class="font-medium text-ink">{{ $item['payload']['title'] ?? ucfirst($item['type']) }}</p>
                            @if (! empty($item['payload']['description']))<p class="text-sm text-muted mt-0.5">{{ $item['payload']['description'] }}</p>@endif
                            @if (! empty($item['payload']['proposed_by']))<p class="text-xs text-subtle mt-1">Предложено от {{ $item['payload']['proposed_by'] }}</p>@endif
                        @else
                            <span class="inline-block text-[11px] font-mono uppercase tracking-wider text-warning-strong bg-warning-soft rounded px-1.5 py-0.5 mb-1">Изпълнение чака одобрение</span>
                            <p class="font-medium text-ink">{{ $item['node_name'] }}</p>
                            <p class="text-sm text-muted">Изпълнение #{{ $item['flow_run_id'] }} спря на тази стъпка.</p>
                        @endif
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <x-button size="sm" variant="secondary"
                            x-on:click="act('reject', @js($item))">Отхвърли</x-button>
                        <x-button size="sm" x-on:click="act('approve', @js($item))">Одобри</x-button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>

@push('scripts')
<script>
function decisions(cfg) {
    return {
        done: [],
        act(decision, item) {
            const url = decision === 'approve' ? cfg.approveUrl : cfg.rejectUrl;
            const body = item.kind === 'proposal'
                ? { kind: 'proposal', id: item.id }
                : { kind: 'run_approval', flow_run_id: item.flow_run_id, node_key: item.node_key };
            fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json' }, body: JSON.stringify(body) })
                .then(r => r.json()).then(d => {
                    if (d.ok) { this.done.push(item.kind + '-' + (item.id || (item.flow_run_id + '-' + item.node_key))); }
                    else if (d.superseded) { alert(d.error || 'Организацията се промени — нужно е ре-ревю.'); location.reload(); }
                    else { alert(d.error || 'Грешка.'); }
                });
        },
    };
}
</script>
@endpush
@endsection
