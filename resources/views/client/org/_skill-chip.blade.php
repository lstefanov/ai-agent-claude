{{-- Умение-чип (единен речник за картата на уменията). $skill, $color (char hue),
     $count? (брой), $slug? + $filter=true → кликаем филтър-бутон (в скоупа на skillMap()).
     Активното състояние ползва ДВА цели литерални класа (без конкатенация → purge-safe). --}}
@props(['skill', 'color' => 'blue', 'count' => null, 'slug' => null, 'filter' => false])
@php
    $c = $color ?: 'blue';
    $slug = $slug ?? \Illuminate\Support\Str::slug($skill);
    $base = "inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium bg-char-{$c}-soft text-char-{$c}-strong";
@endphp
@if ($filter)
    <button type="button" x-on:click="toggleSkill(@js($slug))"
            class="{{ $base }} transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
            :class="skill === @js($slug) ? 'ring-2 ring-primary' : 'ring-1 ring-transparent'"
            :aria-pressed="skill === @js($slug)">
        <span>{{ $skill }}</span>
        @if ($count !== null)<span class="tabular-nums opacity-70">{{ $count }}</span>@endif
    </button>
@else
    <span class="{{ $base }}">
        <span>{{ $skill }}</span>
        @if ($count !== null)<span class="tabular-nums opacity-70">{{ $count }}</span>@endif
    </span>
@endif
