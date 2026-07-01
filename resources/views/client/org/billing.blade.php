@extends('layouts.client')

@section('title', 'Кредити & планове')

@section('content')
@php
    $levelStars = ['low' => '★', 'medium' => '★★', 'high' => '★★★', 'ultra' => '★★★★', 'god' => '★★★★★'];
    $levelLabels = ['low' => 'ниско', 'medium' => 'средно', 'high' => 'високо', 'ultra' => 'ултра', 'god' => 'флагманско'];
    $currentPlan = $subscription?->plan ?: $plans->firstWhere('key', 'free');
    $currentPlanKey = $currentPlan?->key ?? 'free';
    $currentPlanName = $currentPlan?->name ?? 'Безплатен';
    $currentMaxTier = $currentPlan?->max_star_tier ?? 'medium';
    $currentMonthlyCredits = (int) ($currentPlan?->monthly_credits ?? config('billing.plans.free', 100));
    $planCopy = [
        'free' => [
            'audience' => 'За първи flow и пробни задачи',
            'summary' => 'Пробвай екипа, чата и първите ръчни задачи без ангажимент.',
            'outcome' => 'Малък обем chat/задачи',
            'features' => ['Първи flow от описание', 'Пробни разговори със служители', 'Достатъчно за ориентация в продукта'],
            'icon' => 'sparkles',
            'tone' => 'info',
        ],
        'starter' => [
            'audience' => 'За редовна ръчна работа',
            'summary' => 'Повече flow генерации и задачи за екип, който работи по заявка.',
            'outcome' => 'Повече ръчни пускания',
            'features' => ['Планирани flow-и от описания', 'Ръчни задачи през портала', 'Добър старт за малък екип'],
            'icon' => 'bolt',
            'tone' => 'success',
        ],
        'professional' => [
            'audience' => 'За активен AI екип',
            'summary' => 'По-силни модели за research, синтез и регулярна работа на асистенти.',
            'outcome' => 'По-добър research и синтез',
            'features' => ['High ниво модели за важните стъпки', 'Редовен чат със служители', 'По-надеждни анализи и обобщения'],
            'icon' => 'chart-bar',
            'tone' => 'primary',
        ],
        'business' => [
            'audience' => 'За ежедневна автономна работа',
            'summary' => 'Капацитет за дълги workflows, повече паралелна работа и процеси с конектори.',
            'outcome' => 'Дълги workflows и конектори',
            'features' => ['Ultra ниво модели', 'Ежедневни директорски анализи', 'По-голям запас за автономни задачи'],
            'icon' => 'shield-check',
            'tone' => 'warning',
        ],
    ];
    $toneClasses = [
        'info' => 'bg-info-soft text-info-strong',
        'success' => 'bg-success-soft text-success-strong',
        'primary' => 'bg-primary/10 text-primary',
        'warning' => 'bg-warning-soft text-warning-strong',
    ];
@endphp

<div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 space-y-8"
     x-data="billing({
        subscribeUrl: '{{ route('client.org.billing.subscribe') }}',
        topUpUrl: '{{ route('client.org.billing.top-up') }}',
        csrf: '{{ csrf_token() }}',
        balance: {{ (int) $wallet->balance }},
     })">
    <div class="grid lg:grid-cols-[minmax(0,1.1fr)_minmax(18rem,0.9fr)] gap-6 items-start">
        <div class="space-y-4">
            <div>
                <p class="text-xs font-mono uppercase tracking-wider text-primary mb-2">Кредити & планове</p>
                <h1 class="text-2xl sm:text-3xl font-semibold text-ink tracking-tight">Избери колко работа да върши AI екипът</h1>
                <p class="mt-3 max-w-2xl text-muted leading-relaxed">
                    Кредитите се резервират преди задача, flow генерация, research или чат.
                    Неизползваното се връща след приключване, а по-високите планове отключват повече месечен капацитет и по-силни модели.
                </p>
            </div>

            <div class="grid sm:grid-cols-3 gap-3">
                <div class="rounded-xl border border-line bg-surface p-4">
                    <div class="flex items-center gap-2 text-xs font-mono uppercase tracking-wider text-muted">
                        <x-icon name="banknotes" size="4" class="text-primary" />
                        Баланс
                    </div>
                    <p class="mt-2 text-3xl font-semibold text-ink tabular-nums" x-text="balance"></p>
                    <p class="text-xs text-subtle">кредита за следващата работа</p>
                </div>
                <div class="rounded-xl border border-line bg-surface p-4">
                    <div class="flex items-center gap-2 text-xs font-mono uppercase tracking-wider text-muted">
                        <x-icon name="sparkles" size="4" class="text-primary" />
                        Текущ план
                    </div>
                    <p class="mt-2 text-lg font-semibold text-ink">{{ $currentPlanName }}</p>
                    <p class="text-xs text-subtle">
                        до <span class="text-star tabular-nums">{{ $levelStars[$currentMaxTier] ?? $levelStars['medium'] }}</span>
                        <span>{{ $levelLabels[$currentMaxTier] ?? $levelLabels['medium'] }}</span> ниво
                    </p>
                </div>
                <div class="rounded-xl border border-line bg-surface p-4">
                    <div class="flex items-center gap-2 text-xs font-mono uppercase tracking-wider text-muted">
                        <x-icon name="calendar-days" size="4" class="text-primary" />
                        Включени
                    </div>
                    <p class="mt-2 text-3xl font-semibold text-ink tabular-nums">{{ number_format($currentMonthlyCredits) }}</p>
                    <p class="text-xs text-subtle">кредита месечно по плана</p>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-line bg-surface p-5">
            <div class="flex items-center gap-2 mb-4">
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-info-soft text-primary">
                    <x-icon name="bolt" size="5" />
                </span>
                <div>
                    <h2 class="text-sm font-semibold text-ink">Какво купуваш с кредитите</h2>
                    <p class="text-xs text-muted">Капацитет за реална работа, не просто достъп до екран.</p>
                </div>
            </div>
            <div class="space-y-3 text-sm">
                <div class="flex gap-3">
                    <x-icon name="check-circle" size="4" class="mt-0.5 shrink-0 text-success-strong" />
                    <p><span class="font-medium text-ink">Flow генерации</span> от свободно описание към готов DAG от агенти.</p>
                </div>
                <div class="flex gap-3">
                    <x-icon name="check-circle" size="4" class="mt-0.5 shrink-0 text-success-strong" />
                    <p><span class="font-medium text-ink">AI задачи</span> с research, анализ, синтез и QA според избраното ниво модели.</p>
                </div>
                <div class="flex gap-3">
                    <x-icon name="check-circle" size="4" class="mt-0.5 shrink-0 text-success-strong" />
                    <p><span class="font-medium text-ink">Екип и знания</span> - чат със служители, бизнес проучване, digest-и и работа с knowledge base.</p>
                </div>
            </div>
        </div>
    </div>

    <section class="space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-ink">Планове</h2>
                <p class="text-sm text-muted">Започни безплатно, после увеличи капацитета според темпото на екипа.</p>
            </div>
            <p class="text-xs text-subtle">Можеш да сменяш плана според темпото на работа.</p>
        </div>

        <div class="grid md:grid-cols-2 xl:grid-cols-4 gap-4">
            @foreach ($plans as $plan)
                @php
                    $copy = $planCopy[$plan->key] ?? [
                        'audience' => 'План за AI работа',
                        'summary' => 'Месечен капацитет за задачи и модели.',
                        'outcome' => 'AI работа',
                        'features' => (array) $plan->features,
                        'icon' => 'bolt',
                        'tone' => 'info',
                    ];
                    $isCurrent = $plan->key === $currentPlanKey;
                    $isFeatured = $plan->key === 'professional';
                    $toneClass = $toneClasses[$copy['tone']] ?? $toneClasses['info'];
                    $buttonLabel = $plan->key === 'free' ? 'Започни безплатно' : 'Избери '.$plan->name;
                @endphp
                <article class="relative flex min-h-[34rem] flex-col rounded-xl border bg-surface p-5 transition {{ $isFeatured ? 'border-primary shadow-card ring-1 ring-primary/20' : ($isCurrent ? 'border-success ring-1 ring-success/20' : 'border-line hover:border-line-strong') }}">
                    @if ($isFeatured)
                        <div class="mb-3 inline-flex w-fit rounded-full bg-primary px-2.5 py-1 text-[11px] font-semibold text-primary-fg">
                            Най-добър баланс
                        </div>
                    @endif

                    <div class="mb-5 flex items-center gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg {{ $toneClass }}">
                            <x-icon :name="$copy['icon']" size="5" />
                        </span>
                        <div class="min-w-0">
                            <h3 class="text-base font-semibold text-ink">{{ $plan->name }}</h3>
                            <p class="text-xs text-muted">{{ $copy['audience'] }}</p>
                        </div>
                    </div>

                    <div>
                        <p class="text-3xl font-semibold text-ink tabular-nums">
                            {{ number_format($plan->price_cents / 100, 0) }}
                            <span class="text-sm font-medium text-muted">лв/мес</span>
                        </p>
                        <p class="mt-2 text-sm text-muted">{{ $copy['summary'] }}</p>
                    </div>

                    <div class="mt-5 grid grid-cols-2 gap-2 text-sm">
                        <div class="rounded-lg border border-line bg-surface-subtle p-3">
                            <p class="text-[11px] font-mono uppercase tracking-wider text-subtle">Кредити</p>
                            <p class="mt-1 font-semibold text-ink tabular-nums">{{ number_format($plan->monthly_credits) }}/мес</p>
                        </div>
                        <div class="rounded-lg border border-line bg-surface-subtle p-3">
                            <p class="text-[11px] font-mono uppercase tracking-wider text-subtle">Модели</p>
                            <p class="mt-1 font-semibold text-ink">
                                <span class="text-star tabular-nums">{{ $levelStars[$plan->max_star_tier] ?? $levelStars['medium'] }}</span>
                                <span class="sr-only">{{ $levelLabels[$plan->max_star_tier] ?? $plan->max_star_tier }}</span>
                            </p>
                        </div>
                    </div>

                    <div class="mt-5 rounded-lg bg-surface-subtle px-3 py-2 text-sm font-medium text-ink">
                        {{ $copy['outcome'] }}
                    </div>

                    <ul class="mt-5 space-y-3 text-sm text-muted">
                        @foreach ($copy['features'] as $feature)
                            <li class="flex gap-2">
                                <x-icon name="check-circle" size="4" class="mt-0.5 shrink-0 text-success-strong" />
                                <span>{{ $feature }}</span>
                            </li>
                        @endforeach
                    </ul>

                    <div class="mt-auto pt-6">
                        @if ($isCurrent)
                            <div class="flex h-10 items-center justify-center gap-2 rounded-md border border-success/30 bg-success-soft px-4 text-sm font-medium text-success-strong">
                                <x-icon name="check-circle" size="4" />
                                Текущ план
                            </div>
                        @else
                            <x-button size="md"
                                      variant="{{ $isFeatured ? 'primary' : 'secondary' }}"
                                      class="w-full"
                                      x-on:click="subscribe('{{ $plan->key }}')"
                                      x-bind:disabled="busy">
                                {{ $buttonLabel }}
                            </x-button>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    </section>

    <section class="rounded-xl border border-line bg-surface p-5">
        <div class="grid gap-4 md:grid-cols-[minmax(0,1fr)_auto] md:items-center">
            <div>
                <h2 class="text-base font-semibold text-ink">Нужни са още кредити?</h2>
                <p class="mt-1 text-sm text-muted">
                    Еднократното зареждане е полезно при по-активен месец, без да сменяш плана веднага.
                </p>
                <p x-show="msg" x-text="msg" x-cloak class="mt-2 text-sm text-success-strong"></p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <label for="top-up-amount" class="sr-only">Кредити за зареждане</label>
                <input id="top-up-amount"
                       type="number"
                       min="100"
                       step="100"
                       x-model.number="topUpAmount"
                       class="h-10 w-32 rounded-md border border-line bg-surface px-3 text-sm tabular-nums focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30">
                <x-button x-on:click="topUp()" x-bind:disabled="busy" icon="arrow-up-circle">Купи кредити</x-button>
            </div>
        </div>
    </section>

    <section class="grid gap-3 md:grid-cols-3">
        <div class="rounded-xl border border-line bg-surface p-4">
            <p class="text-sm font-semibold text-ink">Резервация преди работа</p>
            <p class="mt-1 text-sm text-muted">Системата пази ориентировъчен бюджет за задачата, за да не прекъсне flow по средата.</p>
        </div>
        <div class="rounded-xl border border-line bg-surface p-4">
            <p class="text-sm font-semibold text-ink">Реален разход след финал</p>
            <p class="mt-1 text-sm text-muted">След изпълнението кредитите се реконсилират по реалните LLM заявки и tool разходи.</p>
        </div>
        <div class="rounded-xl border border-line bg-surface p-4">
            <p class="text-sm font-semibold text-ink">По-висок план - повече сила</p>
            <p class="mt-1 text-sm text-muted">Нивото на модела определя колко мощен AI може да използва задачата.</p>
        </div>
    </section>
</div>

@push('scripts')
<script>
function billing(cfg) {
    return {
        balance: cfg.balance,
        topUpAmount: 1000,
        busy: false,
        msg: '',
        post(url, body) {
            this.busy = true;
            this.msg = '';
            return fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json' },
                body: JSON.stringify(body),
            }).then(r => r.json()).finally(() => this.busy = false);
        },
        topUp() {
            if (!this.topUpAmount || this.topUpAmount <= 0) return;
            this.post(cfg.topUpUrl, { credits: this.topUpAmount }).then(d => {
                if (d.ok) {
                    this.balance = d.balance;
                    this.msg = 'Кредитите са заредени.';
                } else {
                    this.msg = d.error || 'Грешка при зареждане.';
                }
            });
        },
        subscribe(plan) {
            this.post(cfg.subscribeUrl, { plan }).then(d => {
                if (d.ok) {
                    location.reload();
                } else {
                    this.msg = d.error || 'Грешка при избор на план.';
                }
            });
        },
    };
}
</script>
@endpush
@endsection
