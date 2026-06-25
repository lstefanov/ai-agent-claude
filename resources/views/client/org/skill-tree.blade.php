@extends('layouts.client')

@section('title', 'Skill Tree')

@section('content')
<div class="max-w-6xl mx-auto px-6 py-8">
    @include('client.org._lens-tabs', ['active' => 'skill-tree'])

    @if (! $graph['version'])
        <x-empty-state title="Още няма дърво" description="Управителят трябва да проектира организацията.">
            <x-button :href="route('client.org.design.review')">Проектирай екипа</x-button>
        </x-empty-state>
    @else
        <p class="text-sm text-muted mb-5">Клон = Директор, възел = Асистент. Звездите са нивото на задачата
            (<span class="text-star">★</span> = наследено, <span class="text-primary">⬩</span> = явен per-task override).</p>

        <div class="space-y-6">
            @foreach ($graph['directors'] as $dir)
                @php($assistants = collect($graph['assistants'])->where('director_id', $dir['placement_id']))
                <div class="rounded-xl border border-line bg-surface p-4">
                    {{-- Клон: директор --}}
                    <div class="flex items-center gap-2 mb-3 pb-3 border-b border-line">
                        <span class="h-2.5 w-2.5 rounded-full bg-char-purple"></span>
                        <span class="font-medium text-ink">{{ $dir['member']['name'] }}</span>
                        <span class="text-xs text-muted">· {{ $dir['title'] }}</span>
                        <span class="ml-auto text-xs tabular-nums">
                            @for ($i = 1; $i <= 5; $i++)<span class="{{ $i <= ($dir['member']['stars'] ?? 1) ? 'text-star' : 'text-subtle' }}">★</span>@endfor
                        </span>
                    </div>

                    {{-- Възли: асистенти + техните умения/задачи --}}
                    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 pl-4">
                        @forelse ($assistants as $a)
                            <a href="{{ route('client.org.member', $a['member']['id']) }}"
                               class="rounded-lg border border-line bg-surface-subtle/50 p-3 transition hover:border-line-strong focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm font-medium text-ink truncate">{{ $a['member']['name'] }}</span>
                                    <span class="text-xs tabular-nums shrink-0">
                                        @for ($i = 1; $i <= 5; $i++)<span class="{{ $i <= ($a['member']['stars'] ?? 1) ? 'text-star' : 'text-subtle' }}">★</span>@endfor
                                    </span>
                                </div>
                                <p class="text-xs text-muted truncate">{{ $a['title'] }}</p>
                                <p class="mt-1 flex flex-wrap items-center gap-x-1.5 text-[10px] text-muted">
                                    <span><span class="font-semibold text-ink tabular-nums">{{ $a['stats']['flows_total'] }}</span> flows</span>
                                    @if ($a['stats']['active'] > 0)<span class="text-accent">· {{ $a['stats']['active'] }} активни</span>@endif
                                    @if ($a['stats']['failed'] > 0)<span class="text-danger">· {{ $a['stats']['failed'] }} ✗</span>@endif
                                </p>
                                @if (count($a['tasks']))
                                    <ul class="mt-2 space-y-1">
                                        @foreach ($a['tasks'] as $t)
                                            @php($rs = $t['run']['status'] ?? null)
                                            @php($dot = in_array($rs, ['pending', 'running', 'waiting_approval'], true) ? 'bg-accent animate-pulse' : ($rs === 'completed' ? 'bg-success' : ($rs === 'failed' ? 'bg-danger' : 'bg-subtle')))
                                            <li class="flex items-center gap-1.5 text-xs text-ink">
                                                <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $dot }}" title="{{ $rs ?? $t['status'] }}"></span>
                                                <span class="text-{{ $t['inherits'] ? 'star' : 'primary' }}" title="{{ $t['inherits'] ? 'наследено ниво' : 'явен override' }}">{{ $t['inherits'] ? '★' : '⬩' }}</span>
                                                <span class="truncate">{{ $t['title'] }}</span>
                                                <span class="ml-auto text-[10px] text-subtle uppercase">{{ $t['act_mode'] }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </a>
                        @empty
                            <p class="text-sm text-subtle">Няма асистенти.</p>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
