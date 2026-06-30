{{-- Карта на задача (§5.2). $task = AssistantTask (с orgMember.persona, flow.latestRun).
     $mode = 'proposed' | 'ready' | 'generating' | 'executed' | 'rejected' — определя действията. --}}
@php
    $levels = ['low' => '★', 'medium' => '★★', 'high' => '★★★', 'ultra' => '★★★★', 'god' => '★★★★★'];
    $statusMap = [
        'ready' => ['Готова за изпълнение', 'success'],
        'pending_approval' => ['Чака одобрение', 'warning'],
        'generating' => ['Генерира се', 'info'],
        'proposed' => ['Предложена', 'neutral'],
        'rejected' => ['Отхвърлена', 'danger'],
        'failed' => ['Провалена', 'danger'],
        'disabled' => ['Изключена', 'neutral'],
    ];
    $flowStatusMap = [
        'active' => ['flow: активен', 'success'],
        'draft' => ['flow: чернова', 'warning'],
        'inactive' => ['flow: неактивен', 'neutral'],
    ];
    $runStatusMap = [
        'completed' => ['Завършен', 'success'],
        'failed' => ['Провален', 'danger'],
        'running' => ['Изпълнява се', 'info'],
        'pending' => ['Чака', 'neutral'],
        'waiting_approval' => ['Чака одобрение', 'warning'],
    ];
    $member = $task->orgMember;
    $c = $member?->functionColor() ?? 'blue';
    $proposal = (array) ($task->proposal ?? []);
    $steps = (array) ($proposal['steps'] ?? []);
    $tools = (array) ($proposal['tools'] ?? []);
    $warnings = (array) ($proposal['warnings'] ?? []);
    $est = (array) ($proposal['estimated_cost'] ?? []);
    [$stLabel, $stColor] = $statusMap[$task->status] ?? [$task->status, 'neutral'];
    $flow = $task->flow;
    $lastRun = $flow?->latestRun;
    $initial = mb_strtoupper(mb_substr($member?->fullName() ?? '?', 0, 1));
    $needsKnowledge = $task->knowledge_status === 'needs_knowledge';
    $kreqs = $task->relationLoaded('knowledgeRequirements')
        ? $task->knowledgeRequirements->map(fn ($r) => [
            'key' => $r->key, 'label' => $r->label, 'sourceability' => $r->sourceability,
            'status' => $r->status, 'acknowledged' => $r->acknowledged, 'how_to_provide' => $r->how_to_provide,
        ])->values()
        : collect();
@endphp
<div class="rounded-xl border border-line bg-surface p-4 space-y-3">
    {{-- Хедър: служител + ниво + статус --}}
    <div class="flex items-start justify-between gap-3">
        <div class="flex items-start gap-3 min-w-0">
            @if ($member?->persona?->hasReadyAvatar())
                <img src="{{ $member->persona->avatar_url }}" alt="" class="h-10 w-10 rounded-full object-cover ring-2 ring-char-{{ $c }}-soft">
            @else
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-char-{{ $c }}-soft text-char-{{ $c }}-strong font-semibold ring-2 ring-char-{{ $c }}-soft">{{ $initial }}</span>
            @endif
            <div class="min-w-0">
                <p class="font-medium text-ink truncate">{{ $task->title }}</p>
                <p class="text-xs text-muted truncate">
                    <a href="{{ route('client.org.member', $task->org_member_id) }}" class="hover:text-ink">{{ $member?->fullName() }}</a>
                    · {{ $member?->roleTitle() }}
                    · <span class="tabular-nums text-star" title="Ниво">{{ $levels[$task->effectiveStarTier()->value] ?? '★' }}</span>
                </p>
            </div>
        </div>
        <x-badge :color="$stColor">{{ $stLabel }}</x-badge>
    </div>

    {{-- Кратко описание --}}
    @if ($task->description)
        <p class="text-sm text-muted line-clamp-2">{{ $task->description }}</p>
    @endif

    {{-- Мета: flow статус · trigger · инструменти --}}
    <div class="flex flex-wrap items-center gap-2 text-xs">
        @if ($flow)
            @php [$fLabel, $fColor] = $flowStatusMap[$flow->status] ?? [$flow->status, 'neutral']; @endphp
            <x-badge :color="$fColor">{{ $fLabel }}</x-badge>
        @endif
        <span class="text-subtle">тригер: {{ $task->trigger }}</span>
        @foreach (array_slice($tools, 0, 4) as $tool)
            <span class="px-1.5 py-0.5 rounded bg-surface-subtle text-subtle">{{ $tool }}</span>
        @endforeach
        @if (in_array('needs_review', $warnings, true))
            <x-badge color="warning" icon="exclamation-triangle">нужен преглед</x-badge>
        @endif
    </div>

    {{-- Предложение: защо + как (стъпки) + ориентировъчна цена --}}
    @if ($mode === 'proposed')
        @if (! empty($proposal['rationale']))
            <div class="rounded-lg bg-surface-subtle p-3 text-sm text-ink">
                <x-prose :text="$proposal['rationale']" />
                @if (! empty($proposal['expected_impact']))
                    <p class="text-xs text-muted mt-1.5">Очакван ефект: {{ $proposal['expected_impact'] }}</p>
                @endif
            </div>
        @endif
        @if ($steps)
            <ol class="space-y-1 text-sm text-muted">
                @foreach (array_slice($steps, 0, 3) as $s)
                    <li class="flex gap-2"><span class="text-subtle tabular-nums">{{ $s['order'] ?? $loop->iteration }}.</span><span>{{ $s['summary'] ?? $s['node_name'] ?? '' }}</span></li>
                @endforeach
                @if (count($steps) > 3)
                    <li class="text-xs text-subtle">+ още {{ count($steps) - 3 }} стъпки</li>
                @endif
            </ol>
        @endif
        <div class="flex items-center justify-between gap-2 pt-1">
            <span class="text-xs text-subtle tabular-nums">
                @if (isset($est['credits'])) ~{{ $est['credits'] }} кредита @endif
            </span>
            <div class="flex flex-wrap items-center justify-end gap-2">
                <a href="{{ route('client.org.decisions') }}" class="text-xs text-primary hover:text-primary-hover">Виж в Предложения</a>
                <button type="button" x-on:click="reject({{ $task->id }})" class="text-sm text-danger hover:text-danger-strong font-medium">Отхвърли</button>
                <x-button size="sm" variant="secondary" x-on:click="approve({{ $task->id }}, false)">Одобри</x-button>
                <x-button size="sm" x-on:click="approve({{ $task->id }}, true)">Одобри и пусни</x-button>
            </div>
        </div>
    @endif

    {{-- Генерира се: flow в процес на създаване (без „Изпълни") --}}
    @if ($mode === 'generating')
        @php
            $genStatusUrl = $task->gen_token
                ? route('client.org.tasks.gen-status', ['task' => $task->id, 'token' => $task->gen_token])
                : null;
        @endphp
        <div class="rounded-lg bg-surface-subtle p-3 space-y-2"
             x-data="taskGenPoll({ taskId: {{ $task->id }}, token: @js($task->gen_token), statusUrl: @js($genStatusUrl) })">
            <div class="flex items-center justify-between gap-2 text-xs">
                <span class="inline-flex items-center gap-1.5 text-muted">
                    <span class="h-1.5 w-1.5 rounded-full bg-accent animate-pulse"></span>
                    <span x-text="failed ? 'Провалена генерация' : 'Генерира се flow…'"></span>
                </span>
                <span class="tabular-nums text-subtle" x-show="!failed" x-text="Math.round(progress) + '%'"></span>
            </div>
            <p class="text-sm text-ink" x-text="stage"></p>
            <div class="h-1.5 w-full rounded-full bg-surface overflow-hidden" x-show="!failed">
                <div class="h-full rounded-full bg-primary transition-all duration-300 ease-out"
                     :style="'width:' + progress + '%'"></div>
            </div>
            <p class="text-xs text-subtle" x-show="!failed">Остава неизвестно — зависи от сложността на задачата.</p>
            <div class="flex items-center justify-between gap-2 pt-1" x-show="failed">
                <a href="{{ route('client.org.member', $task->org_member_id) }}" class="text-xs text-primary hover:text-primary-hover">Към профила на служителя →</a>
            </div>
        </div>
    @endif

    {{-- Готова: ръчно пускане (или „Добави знания", ако липсва информация — §2-етапни задачи) --}}
    @if ($mode === 'ready')
        <div class="flex items-center justify-between gap-2 pt-1">
            <a href="{{ route('client.org.member', $task->org_member_id) }}" class="text-xs text-primary hover:text-primary-hover">Профил на служителя →</a>
            @if ($needsKnowledge)
                <x-button size="sm" variant="secondary" icon="book-open"
                          x-on:click="$dispatch('knowledge-open', { taskId: {{ $task->id }}, requirements: {{ \Illuminate\Support\Js::from($kreqs) }} })">Добави знания</x-button>
            @elseif ($task->trigger === 'manual')
                <x-button size="sm" x-on:click="run({{ $task->id }})">Изпълни</x-button>
            @else
                <span class="text-xs text-subtle">по график: {{ $task->schedule }}</span>
            @endif
        </div>
    @endif

    {{-- Изпълнени: последен резултат --}}
    @if ($mode === 'executed' && $lastRun)
        @php [$rLabel, $rColor] = $runStatusMap[$lastRun->status] ?? [$lastRun->status, 'neutral']; @endphp
        <div class="flex items-center justify-between gap-2 border-t border-line pt-3">
            <div class="flex items-center gap-2 text-xs text-muted">
                <x-badge :color="$rColor">{{ $rLabel }}</x-badge>
                @if ($lastRun->completed_at)<span class="tabular-nums">{{ $lastRun->completed_at->diffForHumans() }}</span>@endif
            </div>
            <a href="{{ route('client.runs.result', $lastRun->id) }}" class="text-sm text-primary hover:text-primary-hover font-medium">Виж резултата →</a>
        </div>
    @endif

    {{-- Отхвърлени: причина --}}
    @if ($mode === 'rejected' && $task->rejection_reason)
        <p class="text-xs text-danger border-t border-line pt-3">Причина: {{ $task->rejection_reason }}</p>
    @endif
</div>
