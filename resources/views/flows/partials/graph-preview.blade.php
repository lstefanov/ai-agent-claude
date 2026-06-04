{{--
    Flow graph dashboard preview.
    Pure HTML/CSS — no Drawflow, no JS library required.
    Renders flow_nodes in horizontal order (left→right by pos_x).
    Boundary nodes (Старт/Край) show as pills; agents as compact cards.
--}}
@php
    $nodes       = $graphPreviewConfig['nodes'] ?? [];
    $hasGraph    = $graphPreviewConfig['hasGraph'] ?? count($nodes) > 0;
    $builderUrl  = $graphPreviewConfig['builderUrl'];
    $agentTypes  = collect($graphPreviewConfig['agentTypes'] ?? []);

    $roleColor = [
        'body'       => 'border-indigo-400',
        'hidden'     => 'border-sky-400',
        'processing' => 'border-sky-400',
        'appendix'   => 'border-purple-400',
        'quality'    => 'border-amber-400',
    ];

    $resolveRole = function (array $node) use ($agentTypes) {
        if (! empty($node['output_role'])) return $node['output_role'];
        return $agentTypes->firstWhere('type', $node['type'])['output_role'] ?? 'body';
    };

    $isBoundary = fn (array $n) => in_array($n['type'] ?? '', ['flow_start', 'flow_end'], true);
@endphp

<div class="bg-white rounded-xl border border-gray-200 mb-8 overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between bg-gray-50">
        <h2 class="text-base font-semibold text-gray-900">Граф на агентите</h2>
        <a href="{{ $builderUrl }}"
           class="text-sm px-3 py-1.5 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 font-semibold transition">
            ✎ Отвори Граф Редактора
        </a>
    </div>

    @if (! $hasGraph || empty($nodes))
        <div class="px-6 py-10 text-center text-gray-400 text-sm">
            <p class="text-3xl mb-2">🕸️</p>
            Няма изграден граф.
            <a href="{{ $builderUrl }}" class="text-indigo-600 hover:underline">Отвори редактора</a>,
            за да добавиш или генерираш агенти.
        </div>
    @else
        <a href="{{ $builderUrl }}" class="block hover:bg-gray-50 transition" title="Отвори Граф Редактора">
            <div class="px-6 py-6 overflow-x-auto">
                <div class="flex items-center gap-0 w-max min-w-full">
                    @foreach ($nodes as $i => $node)
                        @if ($isBoundary($node))
                            {{-- Pill for Start / End --}}
                            <div class="flex items-center justify-center px-4 py-2 rounded-full border border-gray-300 bg-white shadow-sm text-sm font-bold text-gray-600 whitespace-nowrap shrink-0">
                                <span class="mr-1.5">{{ $node['icon'] ?? ($node['type'] === 'flow_start' ? '▶' : '■') }}</span>
                                {{ $node['name'] }}
                            </div>
                        @else
                            @php
                                $role  = $resolveRole($node);
                                $color = $roleColor[$role] ?? 'border-gray-300';
                                $label = $agentTypes->firstWhere('type', $node['type'])['label'] ?? $node['type'];
                            @endphp
                            {{-- Agent card --}}
                            <div class="bg-white rounded-xl border border-gray-200 border-l-4 {{ $color }} shadow-sm px-4 py-3 w-52 shrink-0">
                                <div class="flex items-center gap-2 mb-1 min-w-0">
                                    <span class="text-lg leading-none">{{ $node['icon'] ?? '🤖' }}</span>
                                    <span class="text-sm font-bold text-gray-900 truncate">{{ $node['name'] }}</span>
                                </div>
                                <div class="text-xs text-gray-400 truncate">{{ $label }}</div>
                            </div>
                        @endif

                        {{-- Arrow between nodes --}}
                        @if (! $loop->last)
                            <div class="text-gray-300 text-lg px-2 shrink-0 select-none">→</div>
                        @endif
                    @endforeach
                </div>
            </div>
        </a>
    @endif
</div>
