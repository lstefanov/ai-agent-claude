{{-- Плътен ред в потока ($item). Клик избира реда → детайлът отива в десния панел
     (desktop) и се разгъва inline под реда (mobile). Без карта — control-room лента. --}}
@php
    $tone = ['danger' => 'text-danger', 'success' => 'text-success-strong', 'muted' => 'text-muted'][$item['amount_tone'] ?? ''] ?? 'text-muted';
@endphp
<div class="flex cursor-pointer items-center gap-3 border-b border-line px-3 py-2.5 transition"
     data-key="{{ $item['key'] }}"
     x-on:click="select($event)" x-on:keydown.enter="select($event)"
     role="button" tabindex="0"
     :class="selectedKey === @js($item['key']) ? 'bg-info-soft' : 'hover:bg-surface-subtle'">
    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md {{ $item['color_classes'] }}">
        <x-icon :name="$item['icon']" size="4" />
    </span>
    <div class="min-w-0 flex-1">
        <p class="truncate text-sm font-medium"
           :class="selectedKey === @js($item['key']) ? 'text-primary' : 'text-ink'"><x-prose :text="$item['title']" inline /></p>
        @if (! empty($item['context']))
            <p class="mt-0.5 truncate text-xs text-muted">{{ $item['context'] }}</p>
        @endif
    </div>
    @if (! empty($item['amount']))
        <span class="shrink-0 font-mono text-xs font-medium tabular-nums {{ $tone }}">{{ $item['amount'] }}</span>
    @endif
    <span class="w-10 shrink-0 text-right font-mono text-xs tabular-nums text-subtle">{{ $item['time'] }}</span>
    <x-icon name="chevron-right" size="4" class="shrink-0 text-subtle" />
    {{-- Източник за десния панел (инертен <template> → не се рендира, чете се innerHTML). --}}
    <template data-detail>@include('client.org._chronicle-detail', ['item' => $item])</template>
</div>
{{-- Mobile inline разгъване (десният панел е скрит под lg). --}}
<div class="border-b border-line bg-surface-subtle px-3 py-3 lg:hidden" x-show="selectedKey === @js($item['key'])" x-cloak>
    @include('client.org._chronicle-detail', ['item' => $item])
</div>
