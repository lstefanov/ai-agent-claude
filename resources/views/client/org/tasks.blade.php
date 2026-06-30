@extends('layouts.client')

@section('title', 'Задачи')

@section('content')
@php
    $readyCount = $generating->count() + $ready->count();
@endphp
<div x-data="taskActions()" class="space-y-6">
    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-ink">Дневник на задачите</h1>
            <p class="text-muted">Готови и генериращи се задачи, плюс история на изпълненията.</p>
        </div>
        <x-button :href="route('client.org.tasks.new')" icon="plus">Нова задача</x-button>
    </div>

    {{-- Lifecycle лещи (не глобална навигация) --}}
    <div x-data="{ tab: @js($initialTab ?? 'ready') }">
        <div class="inline-flex rounded-lg border border-line bg-surface-subtle p-0.5" role="tablist">
            <button type="button" role="tab"
                    @click="tab = 'ready'"
                    :aria-selected="tab === 'ready'"
                    :class="tab === 'ready' ? 'bg-surface text-ink shadow-card' : 'text-muted hover:text-ink'"
                    class="px-3 py-1.5 text-sm font-medium rounded-md transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
                За изпълнение
                <span class="ml-1 tabular-nums text-xs text-subtle">{{ $readyCount }}</span>
            </button>
            @if ($proposed->isNotEmpty())
                <button type="button" role="tab"
                        @click="tab = 'proposed'"
                        :aria-selected="tab === 'proposed'"
                        :class="tab === 'proposed' ? 'bg-surface text-ink shadow-card' : 'text-muted hover:text-ink'"
                        class="px-3 py-1.5 text-sm font-medium rounded-md transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
                    Чака преглед на flow
                    <span class="ml-1 tabular-nums text-xs text-subtle">{{ $proposed->count() }}</span>
                </button>
            @endif
            <button type="button" role="tab"
                    @click="tab = 'executed'"
                    :aria-selected="tab === 'executed'"
                    :class="tab === 'executed' ? 'bg-surface text-ink shadow-card' : 'text-muted hover:text-ink'"
                    class="px-3 py-1.5 text-sm font-medium rounded-md transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
                Изпълнени
                <span class="ml-1 tabular-nums text-xs text-subtle">{{ $executed->count() }}</span>
            </button>
        </div>

        {{-- За изпълнение (generating + ready) --}}
        <div x-show="tab === 'ready'" role="tabpanel" class="mt-4 space-y-3">
            @if ($generating->isEmpty() && $ready->isEmpty())
                <x-empty-state
                    title="Няма задачи за изпълнение"
                    :description="($pendingProposals ?? 0) > 0
                        ? 'Има идеи в Предложения — одобри ги там, за да се появят тук (първо се генерира flow).'
                        : 'Одобрените задачи и тези в процес на генериране се появяват тук.'">
                    @if (($pendingProposals ?? 0) > 0)
                        <x-button :href="route('client.org.decisions')">
                            Към Предложения ({{ $pendingProposals }})
                        </x-button>
                    @endif
                </x-empty-state>
            @else
                @foreach ($generating as $task)
                    @include('client.org._task-card', ['task' => $task, 'mode' => 'generating'])
                @endforeach
                @foreach ($ready as $task)
                    @include('client.org._task-card', ['task' => $task, 'mode' => 'ready'])
                @endforeach
            @endif
        </div>

        {{-- Чака преглед на flow (само при pending_approval) --}}
        @if ($proposed->isNotEmpty())
            <div x-show="tab === 'proposed'" role="tabpanel" x-cloak class="mt-4 space-y-3">
                <p class="text-sm text-muted">Flow-ът е генериран от асистент, но чака твоето одобрение на дизайна. Същите задачи са и в <a href="{{ route('client.org.decisions') }}" class="text-primary hover:text-primary-hover">Предложения</a>.</p>
                @foreach ($proposed as $task)
                    @include('client.org._task-card', ['task' => $task, 'mode' => 'proposed'])
                @endforeach
            </div>
        @endif

        {{-- Изпълнени --}}
        <div x-show="tab === 'executed'" role="tabpanel" x-cloak class="mt-4 space-y-3">
            @forelse ($executed as $task)
                @include('client.org._task-card', ['task' => $task, 'mode' => 'executed'])
            @empty
                <x-empty-state title="Още няма изпълнени задачи" description="Завършените изпълнения се появяват тук с резултат и цена." />
            @endforelse
        </div>
    </div>

    {{-- Странични: отхвърлени/неактивни --}}
    @if ($rejected->isNotEmpty())
        <details class="rounded-xl border border-line bg-surface">
            <summary class="cursor-pointer px-4 py-3 text-sm font-medium text-muted hover:text-ink">Отхвърлени / неактивни ({{ $rejected->count() }})</summary>
            <div class="border-t border-line p-4 space-y-3">
                @foreach ($rejected as $task)
                    @include('client.org._task-card', ['task' => $task, 'mode' => 'rejected'])
                @endforeach
            </div>
        </details>
    @endif

    {{-- Reject modal --}}
    <div x-show="rejecting" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @keydown.escape.window="rejecting = null">
        <div class="w-full max-w-md rounded-xl bg-surface border border-line shadow-popover p-5 space-y-3" @click.outside="rejecting = null">
            <h2 class="text-base font-semibold text-ink">Защо не одобряваме?</h2>
            <p class="text-sm text-muted">Причината помага на служителя да предлага по-добре следващия път.</p>
            <textarea x-model="reason" rows="3" class="w-full rounded-md border border-line bg-surface px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40" placeholder="Кратка причина…"></textarea>
            <div class="flex justify-end gap-2">
                <x-button variant="ghost" size="sm" x-on:click="rejecting = null">Отказ</x-button>
                <x-button variant="danger" size="sm" x-on:click="submitReject()">Отхвърли задачата</x-button>
            </div>
        </div>
    </div>

    <div x-show="error" x-cloak class="fixed bottom-4 right-4 z-50 rounded-lg bg-danger-soft text-danger-strong px-4 py-2 text-sm shadow-card" x-text="error"></div>
</div>

@include('client.org._knowledge-modal')
@include('client.org._task-gen-poll-script')

@push('scripts')
<script>
function taskActions() {
    return {
        rejecting: null,
        reason: '',
        error: '',
        _csrf() { return document.querySelector('meta[name=csrf-token]').content; },
        async _post(url, body) {
            this.error = '';
            try {
                const r = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this._csrf(), 'Accept': 'application/json' },
                    body: JSON.stringify(body),
                });
                const d = await r.json().catch(() => ({}));
                if (!r.ok) { this.error = d.message || d.error || 'Грешка.'; return null; }
                return d;
            } catch (e) { this.error = 'Мрежова грешка.'; return null; }
        },
        async approve(id, run) {
            const d = await this._post(@js(route('client.org.decisions.approve')), { kind: 'assistant_task', id, run });
            if (d) window.location.href = d.tasks_url || @js(route('client.org.tasks.index', ['tab' => 'ready']));
        },
        reject(id) { this.rejecting = id; this.reason = ''; },
        async submitReject() {
            if (!this.reason.trim()) return;
            const d = await this._post(@js(route('client.org.decisions.reject')), { kind: 'assistant_task', id: this.rejecting, reason: this.reason });
            if (d) window.location.reload();
        },
        async run(id) {
            const url = @js(route('client.org.tasks.run', ['task' => '__TASK__'])).replace('__TASK__', id);
            this.error = '';
            try {
                const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this._csrf(), 'Accept': 'application/json' }, body: '{}' });
                const d = await r.json().catch(() => ({}));
                if (r.status === 422 && d.needs_knowledge) {
                    window.dispatchEvent(new CustomEvent('knowledge-open', { detail: { taskId: id, requirements: d.requirements || [] } }));
                    return;
                }
                if (!r.ok) { this.error = d.message || d.error || 'Грешка.'; return; }
                window.location.reload();
            } catch (e) { this.error = 'Мрежова грешка.'; }
        },
    };
}
</script>
@endpush
@endsection
