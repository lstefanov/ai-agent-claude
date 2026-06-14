@props([
    'type' => 'info',
    'dismissible' => true,
    'timeout' => null,
])
@php
    $map = [
        'success' => ['bg-success-soft text-success-strong border-success/20', 'check-circle'],
        'error'   => ['bg-danger-soft text-danger-strong border-danger/20',   'x-circle'],
        'warning' => ['bg-warning-soft text-warning-strong border-warning/20', 'exclamation-triangle'],
        'info'    => ['bg-info-soft text-info-strong border-info/20',          'information-circle'],
    ];
    [$tone, $icon] = $map[$type] ?? $map['info'];
@endphp
<div
    @if($dismissible)
        x-data="{ show: true }" x-show="show"
        @if($timeout) x-init="setTimeout(() => show = false, {{ $timeout }})" @endif
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 -translate-y-1"
    @endif
    role="alert" aria-live="polite"
    {{ $attributes->merge(['class' => "flex items-start justify-between gap-3 border rounded-lg px-4 py-3 $tone"]) }}
>
    <div class="flex items-start gap-2 min-w-0">
        <x-icon :name="$icon" size="4" class="shrink-0 mt-0.5" />
        <div class="text-sm min-w-0">{{ $slot }}</div>
    </div>
    @if($dismissible)
        <button type="button" @click="show = false" aria-label="Затвори"
                class="shrink-0 opacity-60 hover:opacity-100 transition rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-current/40">
            <x-icon name="x-mark" size="4" />
        </button>
    @endif
</div>
