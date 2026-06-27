@extends('layouts.client')

@section('title', 'Карта на уменията')

@section('content')
<div>
    @include('client.org._lens-tabs', ['active' => 'skill-tree'])

    @if (! $graph['version'])
        <x-empty-state title="Още няма карта" description="Управителят трябва да проектира организацията.">
            <x-button :href="route('client.org.design.review')">Проектирай екипа</x-button>
        </x-empty-state>
    @else
        <p class="text-sm text-muted mb-3">Клон = Директор, възел = Служител. Умения = стабилни компетентности; задачите са отделно.</p>

        {{-- Легенда (§10.3) --}}
        <div class="mb-5 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-subtle">
            <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-char-blue"></span> цвят = функция</span>
            <span class="inline-flex items-center gap-1"><span class="text-star">★</span> = ниво</span>
            <span class="inline-flex items-center gap-1"><span class="h-1.5 w-1.5 rounded-full bg-accent"></span> текущо изпълнение</span>
        </div>

        <div class="space-y-6">
            @foreach ($graph['directors'] as $dir)
                @php($assistants = collect($graph['assistants'])->where('director_id', $dir['placement_id']))
                @php($dc = $dir['member']['color'] ?? 'purple')
                <div class="rounded-xl border border-line bg-surface p-4">
                    {{-- Клон: директор --}}
                    <div class="flex items-center gap-2 mb-3 pb-3 border-b border-line">
                        <span class="h-2.5 w-2.5 rounded-full bg-char-{{ $dc }}"></span>
                        <span class="font-medium text-ink">{{ $dir['member']['name'] }}</span>
                        <span class="text-xs text-muted">· {{ $dir['title'] }}</span>
                        <span class="ml-auto text-xs tabular-nums">
                            @for ($i = 1; $i <= 5; $i++)<span class="{{ $i <= ($dir['member']['stars'] ?? 1) ? 'text-star' : 'text-subtle' }}">★</span>@endfor
                        </span>
                    </div>

                    {{-- Възли: служители + техните УМЕНИЯ (≠ задачи) --}}
                    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 pl-4">
                        @forelse ($assistants as $a)
                            @php($ac = $a['member']['color'] ?? 'blue')
                            <a href="{{ route('client.org.member', $a['member']['id']) }}"
                               class="rounded-lg border border-line bg-surface-subtle/50 p-3 transition hover:border-line-strong focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm font-medium text-ink truncate">{{ $a['member']['name'] }}</span>
                                    <span class="text-xs tabular-nums shrink-0">
                                        @for ($i = 1; $i <= 5; $i++)<span class="{{ $i <= ($a['member']['stars'] ?? 1) ? 'text-star' : 'text-subtle' }}">★</span>@endfor
                                    </span>
                                </div>
                                <p class="text-xs text-muted truncate">{{ $a['title'] }}</p>

                                {{-- Умения --}}
                                @if (! empty($a['member']['skills']))
                                    <div class="mt-2 flex flex-wrap gap-1">
                                        @foreach (array_slice($a['member']['skills'], 0, 4) as $skill)
                                            <span class="px-1.5 py-0.5 rounded text-[10px] bg-char-{{ $ac }}-soft text-char-{{ $ac }}-strong">{{ $skill }}</span>
                                        @endforeach
                                    </div>
                                @endif

                                {{-- Задачи (брой) + runs --}}
                                <p class="mt-2 flex flex-wrap items-center gap-x-1.5 text-[10px] text-muted">
                                    <span><span class="font-semibold text-ink tabular-nums">{{ count($a['tasks']) }}</span> задачи</span>
                                    <span>· <span class="tabular-nums">{{ $a['stats']['flows_total'] }}</span> flows</span>
                                    @if ($a['stats']['active'] > 0)<span class="text-accent">· {{ $a['stats']['active'] }} активни</span>@endif
                                    @if ($a['stats']['failed'] > 0)<span class="text-danger">· {{ $a['stats']['failed'] }} ✗</span>@endif
                                </p>
                            </a>
                        @empty
                            <p class="text-sm text-subtle">Няма служители.</p>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
