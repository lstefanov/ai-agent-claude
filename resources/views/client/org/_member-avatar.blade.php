{{-- Споделен портрет/fallback за org член ($m = member card array).
     $size: sm (h-8 w-8) | md (h-10 w-10) | lg (h-12 w-12). $ring: показва ring-char-* --}}
@php
    $size = $size ?? 'md';
    $dims = match ($size) {
        'sm' => 'h-8 w-8 text-xs',
        'lg' => 'h-12 w-12 text-base',
        default => 'h-10 w-10 text-sm',
    };
    $c = $m['color'] ?? 'blue';
    $ring = $ring ?? true;
    $ringClass = $ring ? "ring-2 ring-char-{$c}-soft" : '';
@endphp
@if (! empty($m['avatar_url']))
    <img src="{{ $m['avatar_url'] }}" alt="{{ $m['name'] }}" class="{{ $dims }} shrink-0 rounded-full object-cover {{ $ringClass }}">
@else
    <span class="flex {{ $dims }} shrink-0 items-center justify-center rounded-full bg-char-{{ $c }}-soft text-char-{{ $c }}-strong font-semibold {{ $ringClass }}">
        {{ $m['initial'] ?? mb_strtoupper(mb_substr($m['name'] ?? '?', 0, 1)) }}
    </span>
@endif
