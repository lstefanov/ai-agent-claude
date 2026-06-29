{{-- Член-чип за Кутията за решения: портретен аватар + fallback цветни инициали, име/роля,
     по желание звезди (= ниво) и тон. $m = memberCard масив (DecisionBoxService::memberCard).
     Кликаем → отваря профилния modal (openMember). Опции: $label (малък етикет
     „Предложил"/„Възложено на"), $detailed (повече детайли). --}}
@php
    $c = $m['color'] ?? 'blue';
    $stars = (int) ($m['stars'] ?? 1);
    $label = $label ?? null;
    $detailed = $detailed ?? false;
    $mjson = \Illuminate\Support\Js::from($m);
@endphp
<div role="button" tabindex="0"
     x-on:click="openMember({{ $mjson }})"
     x-on:keydown.enter.prevent="openMember({{ $mjson }})"
     x-on:keydown.space.prevent="openMember({{ $mjson }})"
     title="Виж профила на {{ $m['name'] }}"
     class="group cursor-pointer rounded-lg border border-line bg-surface-subtle/60 p-3 transition hover:border-line-strong hover:bg-surface-subtle focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
    @if ($label)
        <p class="mb-2 text-[10px] font-mono uppercase tracking-wider text-subtle">{{ $label }}</p>
    @endif
    <div class="flex items-start gap-3">
        @if (! empty($m['avatar_url']))
            <img src="{{ $m['avatar_url'] }}" alt="{{ $m['name'] }}" class="h-10 w-10 shrink-0 rounded-full object-cover ring-2 ring-char-{{ $c }}-soft">
        @else
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-char-{{ $c }}-soft text-char-{{ $c }}-strong font-semibold ring-2 ring-char-{{ $c }}-soft">{{ $m['initial'] }}</span>
        @endif
        <div class="min-w-0 flex-1">
            <div class="flex items-center gap-1.5">
                <p class="font-medium text-ink truncate">{{ $m['name'] }}</p>
                @if (! empty($m['retired']))
                    <span class="shrink-0 rounded bg-surface-subtle px-1.5 py-0.5 text-[10px] text-subtle">пенсиониран</span>
                @endif
            </div>
            <p class="text-xs text-muted truncate">{{ $m['role'] }}@if ($detailed && ! empty($m['age'])) · {{ $m['age'] }}г.@endif</p>
            @if ($detailed)
                <span class="mt-1 inline-block text-xs tabular-nums" title="Ниво: {{ $m['tier_label'] ?? $m['tier'] }}">
                    @for ($i = 1; $i <= 5; $i++)<span class="{{ $i <= $stars ? 'text-star' : 'text-subtle' }}">★</span>@endfor
                </span>
                @if (! empty($m['tone']))
                    <p class="mt-0.5 text-xs text-subtle truncate"><x-prose :text="$m['tone']" inline /></p>
                @endif
            @endif
        </div>
        <x-icon name="arrow-up-right" size="4" class="shrink-0 self-center text-subtle opacity-0 transition group-hover:opacity-100" />
    </div>
</div>
