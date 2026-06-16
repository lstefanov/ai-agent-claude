@php
    $runUrl = route('client.flows.run', $flow);
    $showUrl = route('client.flows.show', $flow);
@endphp
<div class="flex flex-col bg-surface border border-line rounded-xl shadow-card hover:border-line-strong hover:shadow-popover transition"
     x-data="flowCard({ runUrl: '{{ $runUrl }}' })">
    <div class="p-5 flex flex-col flex-1">
        <div class="flex items-start justify-between gap-2 mb-2">
            <h2 class="font-display font-semibold text-ink leading-tight">{{ $flow->name }}</h2>
            <span class="inline-flex items-center gap-1.5 text-xs text-subtle shrink-0" title="Изпълнения">
                <x-icon name="play" size="3.5" /><span class="tabular-nums">{{ $flow->flow_runs_count ?? 0 }}</span>
            </span>
        </div>
        <p class="text-sm text-muted line-clamp-4 flex-1">{{ $flow->description ?: 'Без описание' }}</p>
    </div>

    {{-- Footer: състояния idle / running / under_review / done / failed --}}
    <div class="px-5 py-3 border-t border-line">
        {{-- idle --}}
        <div x-show="state==='idle'" class="flex items-center gap-2">
            <x-button class="flex-1" x-on:click="run()" icon="play">Изпълни</x-button>
            <x-button variant="secondary" :href="$showUrl">Детайли</x-button>
        </div>

        {{-- running --}}
        <div x-show="state==='running'" x-cloak class="space-y-2">
            <div class="flex items-center justify-between text-xs text-muted">
                <span x-text="stepTotal ? ('Стъпка ' + stepIndex + '/' + stepTotal + ' · ' + stepLabel) : stepLabel"></span>
                <span class="tabular-nums" x-text="percent + '%'"></span>
            </div>
            <div class="h-2 rounded-full bg-surface-subtle overflow-hidden" role="progressbar" :aria-valuenow="percent" aria-valuemin="0" aria-valuemax="100">
                <div class="h-full bg-primary transition-all duration-500" :style="`width:${percent}%`"></div>
            </div>
        </div>

        {{-- under_review (waiting_approval) --}}
        <div x-show="state==='under_review'" x-cloak class="flex items-start gap-2 text-sm text-warning-strong">
            <x-icon name="clock" size="4" class="mt-0.5 shrink-0" />
            <span>Изпълнението изисква преглед от човек. Ще продължи след одобрение.</span>
        </div>

        {{-- done --}}
        <div x-show="state==='done'" x-cloak class="flex items-center gap-2">
            <a x-bind:href="resultUrl"
               class="flex-1 inline-flex items-center justify-center gap-2 h-10 px-4 text-sm font-semibold rounded-md bg-primary text-primary-fg hover:bg-primary-hover transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
                <x-icon name="document-text" size="4" /> Резултат
            </a>
            <x-button variant="secondary" x-on:click="run()">Изпълни пак</x-button>
        </div>

        {{-- failed --}}
        <div x-show="state==='failed'" x-cloak class="space-y-2">
            <p class="text-sm text-danger-strong flex items-start gap-1.5">
                <x-icon name="exclamation-triangle" size="4" class="mt-0.5 shrink-0" /><span x-text="errorMsg"></span>
            </p>
            <x-button variant="secondary" class="w-full" x-on:click="run()">Опитай пак</x-button>
        </div>
    </div>
</div>

@include('client.partials.run-card-script')
