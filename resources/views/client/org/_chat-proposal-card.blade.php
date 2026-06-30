{{-- Карта за чат-предложение (OrgProposal pending). $pr = payload array; $prStatus = pending|approved|rejected|null --}}
<div class="mt-2 rounded-lg border border-line bg-surface p-3"
     x-data="{ status: @js(in_array($prStatus, ['approved', 'rejected', 'superseded'], true) ? $prStatus : 'pending') }">
    <p class="text-xs font-mono uppercase tracking-wider text-subtle">Предложение</p>
    <p class="mt-1 text-sm font-medium text-ink">{{ $pr['title'] }}</p>
    @if (! empty($pr['description']))
        <p class="mt-1 text-xs text-muted line-clamp-3">{{ $pr['description'] }}</p>
    @endif
    <div class="mt-2 flex flex-wrap items-center gap-2" x-show="status === 'pending'">
        <button type="button" class="text-xs font-medium text-danger hover:text-danger-strong"
                x-on:click="rejectProposal({{ $pr['id'] }}).then(ok => { if (ok) status = 'rejected'; })">Откази</button>
        <button type="button" class="text-xs font-medium text-primary hover:text-primary-hover"
                x-on:click="approveProposal({{ $pr['id'] }}, false).then(ok => { if (ok) status = 'approved'; })">Одобри</button>
        <button type="button" class="inline-flex h-8 items-center justify-center rounded-md bg-primary px-3 text-xs font-medium text-primary-fg hover:bg-primary-hover"
                x-on:click="approveProposal({{ $pr['id'] }}, true).then(ok => { if (ok) status = 'approved'; })">Одобри и пусни</button>
    </div>
    <p class="mt-1 text-xs text-success-strong" x-show="status === 'approved'" x-cloak>Одобрено.</p>
    <p class="mt-1 text-xs text-muted" x-show="status === 'rejected'" x-cloak>Отхвърлено.</p>
    <p class="mt-1 text-[11px] text-subtle" x-show="status === 'superseded'" x-cloak>Остаряло — виж в <a href="{{ route('client.org.decisions') }}" class="underline">Предложения</a>.</p>
</div>
