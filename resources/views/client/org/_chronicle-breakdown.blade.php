{{-- „Разбивка за периода" — съдържание по подразбиране на десния панел. --}}
@php
    $cbx = $breakdown['credits_by_context'] ?? [];
    $act = $breakdown['activity_by_type'] ?? [];
    $top = $breakdown['top_consumers'] ?? [];
    $maxCredits = collect($cbx)->max('credits') ?: 1;
@endphp
<div class="space-y-5 rounded-xl border border-line bg-surface p-4">
    <p class="text-[11px] font-semibold uppercase tracking-wider text-subtle">Разбивка за периода</p>

    <div>
        <p class="mb-2.5 text-xs text-muted">Кредити по дейност</p>
        @if (count($cbx))
            <div class="space-y-2.5">
                @foreach ($cbx as $c)
                    <div>
                        <div class="mb-1 flex items-center justify-between gap-2 text-xs">
                            <span class="truncate text-ink">{{ $c['label'] }}</span>
                            <span class="shrink-0 font-mono tabular-nums text-muted">{{ number_format($c['credits']) }} кр.</span>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-surface-subtle">
                            <div class="h-full rounded-full bg-primary" style="width: {{ max(4, (int) round($c['credits'] / $maxCredits * 100)) }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-xs text-muted">Няма разход за периода.</p>
        @endif
    </div>

    <div class="border-t border-line pt-4">
        <p class="mb-2 text-xs text-muted">Активност по тип</p>
        <div class="space-y-1.5">
            @foreach ($act as $a)
                <div class="flex items-center justify-between text-xs">
                    <span class="text-ink">{{ $a['label'] }}</span>
                    <span class="font-mono tabular-nums text-muted">{{ number_format($a['count']) }}</span>
                </div>
            @endforeach
        </div>
    </div>

    @if (count($top))
        <div class="border-t border-line pt-4">
            <p class="mb-2.5 text-xs text-muted">Топ консуматори</p>
            <div class="space-y-2.5">
                @foreach ($top as $t)
                    <div class="flex items-center gap-2.5">
                        @include('client.org._member-avatar', ['m' => $t['member'], 'size' => 'sm', 'ring' => false])
                        <span class="min-w-0 flex-1 truncate text-xs text-ink">{{ $t['member']['name'] }}</span>
                        <span class="shrink-0 font-mono text-xs tabular-nums text-muted">{{ number_format($t['credits']) }} кр.</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
