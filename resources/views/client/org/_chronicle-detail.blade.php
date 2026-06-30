{{-- Историята на едно събитие ($item). Ползва се в десния панел (desktop) и inline (mobile).
     Без външна карта — обвива се от мястото на ползване. --}}
@php
    $d = $item['detail'] ?? [];
    $tone = ['danger' => 'text-danger', 'success' => 'text-success-strong', 'muted' => 'text-muted'][$item['amount_tone'] ?? ''] ?? 'text-muted';
@endphp
<div class="mb-3 flex items-start gap-2.5">
    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg {{ $item['color_classes'] }}">
        <x-icon :name="$item['icon']" size="5" />
    </span>
    <div class="min-w-0 flex-1">
        <p class="text-sm font-semibold text-ink"><x-prose :text="$item['title']" inline /></p>
        <p class="mt-0.5 font-mono text-xs text-subtle">{{ $item['label'] }} · {{ $item['time'] }}</p>
    </div>
    @if (! empty($item['amount']))
        <span class="shrink-0 font-mono text-sm font-medium tabular-nums {{ $tone }}">{{ $item['amount'] }}</span>
    @endif
</div>

@if (! empty($d['story']))
    <p class="mb-3 text-sm leading-relaxed text-muted">{{ $d['story'] }}</p>
@endif

@if (! empty($d['rows']))
    <div>
        @foreach ($d['rows'] as $row)
            <div class="flex items-center justify-between gap-3 border-b border-line py-1.5 text-xs">
                <span class="shrink-0 text-muted">{{ $row['label'] }}</span>
                <span class="text-right font-mono text-ink">{{ $row['value'] }}</span>
            </div>
        @endforeach
    </div>
@endif

@if (! empty($d['note']))
    <p class="mt-3 text-xs leading-relaxed text-muted">{{ $d['note'] }}</p>
@endif

@if (! empty($d['links']))
    <div class="mt-3 flex flex-wrap gap-3">
        @foreach ($d['links'] as $link)
            <a href="{{ $link['href'] }}" class="inline-flex items-center gap-1.5 text-xs font-medium text-primary hover:text-primary-hover">
                <x-icon :name="$link['icon'] ?? 'arrow-right'" size="3.5" />{{ $link['label'] }}
            </a>
        @endforeach
    </div>
@endif
