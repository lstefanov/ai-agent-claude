@php
    // status → [color, icon, label]. color maps to <x-badge> semantic tones.
    $map = [
        // Run / node statuses
        'completed' => ['success', 'check-circle',        'Завършен'],
        'failed'    => ['danger',  'x-circle',            'Неуспешен'],
        'running'   => ['accent',  'arrow-path',          'Изпълнява се'],
        'pending'   => ['neutral',  'clock',              'Чакащ'],
        'skipped'   => ['neutral',  'minus-circle',       'Пропуснат'],
        'waiting_approval' => ['warning', 'hand-raised',  'Чака одобрение'],
        // Flow statuses
        'active'    => ['success', 'check-circle',        'Активен'],
        'draft'     => ['warning', 'pencil-square',       'Чернова'],
        'paused'    => ['neutral',  'pause-circle',       'Пауза'],
    ];
    [$color, $icon, $defaultLabel] = $map[$status ?? ''] ?? ['neutral', null, ucfirst($status ?? '—')];
    $isRunning = ($status ?? '') === 'running' || ($status ?? '') === 'waiting_approval';
@endphp
<x-badge :color="$color" :icon="$icon" :pulse="$isRunning" @class([$class ?? '']) >
    {{ $label ?? $defaultLabel }}
</x-badge>
