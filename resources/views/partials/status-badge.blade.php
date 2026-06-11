@php
$map = [
    // Run statuses
    'completed' => ['label' => 'Завършен',     'class' => 'bg-green-100 text-green-700'],
    'failed'    => ['label' => 'Неуспешен',    'class' => 'bg-red-100 text-red-700'],
    'running'   => ['label' => 'Изпълнява се', 'class' => 'bg-blue-100 text-blue-700 animate-pulse'],
    'pending'   => ['label' => 'Чакащ',        'class' => 'bg-gray-100 text-gray-500'],
    'skipped'   => ['label' => 'Пропуснат',    'class' => 'bg-gray-100 text-gray-400'],
    'waiting_approval' => ['label' => '✋ Чака одобрение', 'class' => 'bg-violet-100 text-violet-700 animate-pulse'],
    // Flow statuses
    'active'    => ['label' => 'Активен',      'class' => 'bg-green-100 text-green-700'],
    'draft'     => ['label' => 'Чернова',      'class' => 'bg-yellow-100 text-yellow-700'],
    'paused'    => ['label' => 'Пауза',        'class' => 'bg-gray-100 text-gray-500'],
];
$cfg   = $map[$status ?? ''] ?? ['label' => ucfirst($status ?? '—'), 'class' => 'bg-gray-100 text-gray-500'];
$label = $label ?? $cfg['label'];
$extra = $class ?? '';
@endphp
<span class="inline-flex items-center text-xs px-2 py-1 rounded-full font-medium {{ $cfg['class'] }} {{ $extra }}">
    {{ $label }}
</span>
