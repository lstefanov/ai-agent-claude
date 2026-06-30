@props(['count' => 1, 'max' => 5])
{{-- Ниво на член (★) — gold за запълнените, subtle за празните. Едно място вместо @for из изгледите. --}}
@php
    $count = max(0, min((int) $count, (int) $max));
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center text-[11px] leading-none tabular-nums']) }}
      role="img" aria-label="ниво {{ $count }} от {{ $max }}">
    @for ($i = 1; $i <= $max; $i++)<span aria-hidden="true" class="{{ $i <= $count ? 'text-star' : 'text-subtle' }}">★</span>@endfor
</span>
