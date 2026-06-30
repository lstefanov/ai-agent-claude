{{-- Възел в картата на уменията (skills-forward, по-плътен от roster картата).
     $m = member card; $title; $stats?; $vis = payload за клиентското филтриране (skillMap.memberVisible). --}}
@props(['m', 'title' => null, 'stats' => null, 'vis' => []])
@php($c = $m['color'] ?? 'blue')
<a href="{{ route('client.org.member', $m['id']) }}"
   x-show="memberVisible(@js($vis))" x-transition.opacity.duration.150ms
   aria-label="{{ $m['name'] }} — отвори профил"
   class="flex flex-col rounded-xl border border-line bg-surface p-3.5 transition hover:border-line-strong focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
    <div class="flex items-start gap-2.5">
        @include('client.org._member-avatar', ['m' => $m, 'size' => 'sm'])
        <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold text-ink truncate">{{ $m['name'] }}</p>
            <p class="text-xs text-muted truncate">{{ $title ?: ($m['role'] ?? '') }}</p>
        </div>
        <x-stars :count="$m['stars'] ?? 1" class="shrink-0" />
    </div>

    @if (! empty($m['skills']))
        <div class="mt-2.5 flex flex-wrap gap-1">
            @foreach ($m['skills'] as $skill)
                @include('client.org._skill-chip', ['skill' => $skill, 'color' => $c])
            @endforeach
        </div>
    @endif

    @if (! empty($stats))
        <div class="mt-auto pt-2.5 flex flex-wrap items-center gap-x-2 gap-y-1 border-t border-line text-[11px] text-muted">
            <span><span class="font-semibold text-ink tabular-nums">{{ $stats['flows_total'] }}</span> flows</span>
            @if (($stats['active'] ?? 0) > 0)
                <span class="inline-flex items-center gap-1 text-accent"><span class="h-1.5 w-1.5 rounded-full bg-accent animate-pulse"></span>{{ $stats['active'] }} активни</span>
            @endif
            @if (($stats['completed'] ?? 0) > 0)<span class="text-success-strong tabular-nums">{{ $stats['completed'] }} ✓</span>@endif
            @if (($stats['failed'] ?? 0) > 0)<span class="text-danger tabular-nums">{{ $stats['failed'] }} ✗</span>@endif
        </div>
    @endif
</a>
