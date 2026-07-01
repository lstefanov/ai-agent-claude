@extends('layouts.app')

@section('title', 'Статистика — ' . $company->name)

@section('content')
<div x-data="companyStats()" x-init="init()" class="space-y-6">

    {{-- ── Заглавие + навигация назад ───────────────────────────────── --}}
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <a href="{{ route('companies.show', $company) }}"
               class="inline-flex items-center gap-1 text-sm text-muted hover:text-ink transition mb-2">
                <x-icon name="arrow-left" size="4" /> {{ $company->name }}
            </a>
            <h1 class="text-2xl font-display font-bold text-ink">Статистика</h1>
            <p class="text-sm text-muted mt-0.5">LLM разходи, кредити и история на потреблението</p>
        </div>
    </div>

    {{-- ── Филтър бар ────────────────────────────────────────────────── --}}
    <div class="bg-surface border border-line rounded-xl p-4 flex flex-wrap gap-3 items-end">
        <div class="min-w-[120px] flex-1">
            <label class="block text-xs text-muted mb-1">Провайдър</label>
            <select x-model="filters.provider" @change="reloadAll()"
                    class="w-full text-sm border border-line rounded-lg px-2 py-1.5 bg-surface">
                <option value="">Всички</option>
                @foreach($filterOptions['providers'] as $p)
                    <option value="{{ $p }}">{{ $p }}</option>
                @endforeach
            </select>
        </div>
        <div class="min-w-[160px] flex-1">
            <label class="block text-xs text-muted mb-1">Услуга</label>
            <select x-model="filters.context_type" @change="reloadAll()"
                    class="w-full text-sm border border-line rounded-lg px-2 py-1.5 bg-surface">
                <option value="">Всички</option>
                @foreach($filterOptions['context_types'] as $ct)
                    <option value="{{ $ct }}">{{ \App\Support\StatsLabels::label($ct) }}</option>
                @endforeach
            </select>
        </div>
        <div class="min-w-[130px] flex-1">
            <label class="block text-xs text-muted mb-1">От</label>
            <input type="date" x-model="filters.from" @change="reloadAll()"
                   class="w-full text-sm border border-line rounded-lg px-2 py-1.5 bg-surface">
        </div>
        <div class="min-w-[130px] flex-1">
            <label class="block text-xs text-muted mb-1">До</label>
            <input type="date" x-model="filters.to" @change="reloadAll()"
                   class="w-full text-sm border border-line rounded-lg px-2 py-1.5 bg-surface">
        </div>
        <button @click="clearFilters()"
                class="text-sm text-muted hover:text-ink transition px-3 py-1.5 border border-line rounded-lg">
            Изчисти
        </button>
    </div>

    {{-- ── Executive summary лента ───────────────────────────────────── --}}
    <div x-show="overview" class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-5 gap-3">
        <div class="bg-surface border border-line rounded-xl px-4 py-3">
            <p class="text-xs text-muted mb-0.5">Баланс</p>
            <p class="text-xl font-bold text-ink tabular-nums" x-text="fmt(overview?.summary?.balance) + ' кр.'">—</p>
        </div>
        <div class="bg-surface border border-line rounded-xl px-4 py-3">
            <p class="text-xs text-muted mb-0.5">Похарчени (кредити)</p>
            <p class="text-xl font-bold text-ink tabular-nums" x-text="fmt(overview?.summary?.credits?.spent) + ' кр.'">—</p>
        </div>
        <div class="bg-surface border border-line rounded-xl px-4 py-3">
            <p class="text-xs text-muted mb-0.5">Реален USD</p>
            <p class="text-xl font-bold text-ink tabular-nums" x-text="'$' + fmtUsd(overview?.summary?.total_cost)">—</p>
        </div>
        <div class="bg-surface border border-line rounded-xl px-4 py-3">
            <p class="text-xs text-muted mb-0.5">Нетаксуван USD</p>
            <p class="text-xl font-bold text-warning-strong tabular-nums" x-text="'$' + fmtUsd(overview?.summary?.unbilled_usd)">—</p>
        </div>
        <div class="bg-surface border border-line rounded-xl px-4 py-3 col-span-2 md:col-span-1">
            <p class="text-xs text-muted mb-0.5">Заявки</p>
            <p class="text-xl font-bold text-ink tabular-nums" x-text="fmt(overview?.summary?.total_requests)">—</p>
        </div>
    </div>

    {{-- ── Таб навигация ─────────────────────────────────────────────── --}}
    <div class="border-b border-line">
        <nav class="-mb-px flex gap-1 overflow-x-auto">
            @foreach([
                ['overview',  'Преглед'],
                ['credits',   'Кредити'],
                ['services',  'По услуги'],
                ['flows',     'Flows'],
                ['org',       'Организация'],
                ['unbilled',  'Нетаксувани'],
                ['external',  'Външни API'],
                ['knowledge', 'Знания'],
                ['ocr',       'OCR'],
                ['grid',      'Всички заявки'],
            ] as [$tab, $label])
            <button @click="switchTab('{{ $tab }}')"
                    :class="activeTab === '{{ $tab }}' ? 'border-primary text-primary' : 'border-transparent text-muted hover:text-ink hover:border-line'"
                    class="whitespace-nowrap border-b-2 px-4 py-2.5 text-sm font-medium transition">
                {{ $label }}
            </button>
            @endforeach
        </nav>
    </div>

    {{-- ── Таб: Преглед ──────────────────────────────────────────────── --}}
    <div x-show="activeTab === 'overview'" x-cloak>
        <template x-if="!overview">
            <div class="text-center py-12 text-muted text-sm">Зарежда се…</div>
        </template>
        <template x-if="overview">
            <div class="space-y-6">
                {{-- Summary карти --}}
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="bg-surface border border-line rounded-xl px-5 py-4">
                        <p class="text-xs text-muted mb-1">Общо USD</p>
                        <p class="text-2xl font-bold text-ink tabular-nums" x-text="'$' + fmtUsd(overview.summary.total_cost)"></p>
                        <p class="text-xs text-muted mt-1" x-text="'Днес: $' + fmtUsd(overview.summary.today_cost)"></p>
                    </div>
                    <div class="bg-surface border border-line rounded-xl px-5 py-4">
                        <p class="text-xs text-muted mb-1">Баланс</p>
                        <p class="text-2xl font-bold text-ink tabular-nums" x-text="fmt(overview.summary.balance) + ' кр.'"></p>
                        <p class="text-xs text-muted mt-1" x-text="'Похарчено: ' + fmt(overview.summary.credits.spent) + ' кр.'"></p>
                    </div>
                    <div class="bg-surface border border-line rounded-xl px-5 py-4">
                        <p class="text-xs text-muted mb-1">Токени (платени)</p>
                        <p class="text-2xl font-bold text-ink tabular-nums" x-text="fmtK(overview.summary.total_tokens)"></p>
                        <p class="text-xs text-muted mt-1" x-text="overview.summary.total_requests + ' заявки'"></p>
                    </div>
                    <div class="bg-surface border border-line rounded-xl px-5 py-4">
                        <p class="text-xs text-muted mb-1">Нетаксуван USD</p>
                        <p class="text-2xl font-bold text-warning-strong tabular-nums" x-text="'$' + fmtUsd(overview.summary.unbilled_usd)"></p>
                        <p class="text-xs text-muted mt-1">без резервация</p>
                    </div>
                </div>

                {{-- Reconciliation --}}
                <div class="bg-surface border border-line rounded-xl px-5 py-4">
                    <h3 class="text-sm font-semibold text-ink mb-3">Reconciliation</h3>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                        <div>
                            <p class="text-muted text-xs mb-0.5">Реален USD (общо)</p>
                            <p class="font-semibold text-ink tabular-nums" x-text="'$' + fmtUsd(overview.reconciliation.real_usd)"></p>
                        </div>
                        <div>
                            <p class="text-muted text-xs mb-0.5">Похарчени кредити</p>
                            <p class="font-semibold text-ink tabular-nums" x-text="fmt(overview.reconciliation.spent_credits) + ' кр.'"></p>
                        </div>
                        <div>
                            <p class="text-muted text-xs mb-0.5">Ориентировъчна стойност <span class="text-warning-strong">≈</span></p>
                            <p class="font-semibold text-ink tabular-nums" x-text="overview.reconciliation.indicative_credit_usd !== null ? '$' + fmtUsd(overview.reconciliation.indicative_credit_usd) : '—'"></p>
                        </div>
                        <div>
                            <p class="text-muted text-xs mb-0.5">Нетаксуван USD</p>
                            <p class="font-semibold text-warning-strong tabular-nums" x-text="'$' + fmtUsd(overview.reconciliation.unbilled_usd)"></p>
                        </div>
                    </div>
                    <p class="text-xs text-muted mt-3">Ориентировъчната стойност е груба оценка (реален USD ÷ markup) — не отразява точно star multipliers и flat тарифи.</p>
                </div>

                {{-- Динамични чартове (Chart.js) --}}
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div class="bg-surface border border-line rounded-xl px-5 py-4">
                        <h3 class="text-sm font-semibold text-ink mb-3">Разход по ден (USD)</h3>
                        <div class="h-56"><canvas x-ref="chartSpend"></canvas></div>
                    </div>
                    <div class="bg-surface border border-line rounded-xl px-5 py-4">
                        <h3 class="text-sm font-semibold text-ink mb-3">Кредитни движения по ден</h3>
                        <div class="h-56"><canvas x-ref="chartCredits"></canvas></div>
                    </div>
                    <div class="bg-surface border border-line rounded-xl px-5 py-4">
                        <h3 class="text-sm font-semibold text-ink mb-3">USD ↔ кредити по услуга</h3>
                        <div class="h-56"><canvas x-ref="chartService"></canvas></div>
                    </div>
                    <div class="bg-surface border border-line rounded-xl px-5 py-4">
                        <h3 class="text-sm font-semibold text-ink mb-3">Разход по провайдър</h3>
                        <div class="h-56"><canvas x-ref="chartProvider"></canvas></div>
                    </div>
                    <div class="bg-surface border border-line rounded-xl px-5 py-4 lg:col-span-2">
                        <h3 class="text-sm font-semibold text-ink mb-3">Топ модели по разход (USD)</h3>
                        <div class="h-56"><canvas x-ref="chartModel"></canvas></div>
                    </div>
                </div>

                {{-- Provider breakdown --}}
                <div class="bg-surface border border-line rounded-xl px-5 py-4">
                    <h3 class="text-sm font-semibold text-ink mb-3">По провайдър</h3>
                    <div class="space-y-2">
                        <template x-for="p in overview.providers" :key="p.provider">
                            <div class="flex items-center gap-3 text-sm">
                                <span class="w-28 text-muted truncate" x-text="p.provider"></span>
                                <div class="flex-1 bg-line rounded-full h-1.5 overflow-hidden">
                                    <div class="bg-primary h-1.5 rounded-full"
                                         :style="'width:' + pct(p.cost, maxProviderCost()) + '%'"></div>
                                </div>
                                <span class="w-20 text-right tabular-nums text-ink" x-text="'$' + fmtUsd(p.cost)"></span>
                                <span class="w-16 text-right tabular-nums text-muted" x-text="p.requests + ' зап.'"></span>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Разходи по услуга --}}
                <div class="bg-surface border border-line rounded-xl px-5 py-4">
                    <h3 class="text-sm font-semibold text-ink mb-3">По услуга</h3>
                    <div class="space-y-2">
                        <template x-for="row in overview.charts.costByService.rows" :key="row.key">
                            <div class="flex items-center gap-3 text-sm">
                                <span class="w-44 text-muted truncate" x-text="row.label"></span>
                                <div class="flex-1 bg-line rounded-full h-1.5 overflow-hidden">
                                    <div class="bg-primary h-1.5 rounded-full"
                                         :style="'width:' + pct(row.cost_usd, maxServiceCost()) + '%'"></div>
                                </div>
                                <span class="w-20 text-right tabular-nums text-ink" x-text="'$' + fmtUsd(row.cost_usd)"></span>
                                <span class="w-20 text-right tabular-nums text-muted"
                                      x-text="row.spent_credits > 0 ? fmt(row.spent_credits) + ' кр.' : '≈' + fmt(row.est_credits) + ' кр.'"></span>
                                <span x-show="row.spent_credits === 0" class="text-xs text-warning-strong">≈</span>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </template>
    </div>

    {{-- ── Таб: Кредити ──────────────────────────────────────────────── --}}
    <div x-show="activeTab === 'credits'" x-cloak>
        <div x-show="creditsLoading" class="text-center py-12 text-muted text-sm">Зарежда се…</div>
        <div x-show="!creditsLoading && creditsData" class="space-y-4">
            {{-- Summary карти --}}
            <div class="grid grid-cols-3 md:grid-cols-6 gap-3">
                <div class="bg-surface border border-line rounded-xl px-4 py-3">
                    <p class="text-xs text-muted mb-0.5">Баланс</p>
                    <p class="font-bold text-ink tabular-nums" x-text="fmt(creditsData?.summary?.balance) + ' кр.'">—</p>
                </div>
                <div class="bg-surface border border-line rounded-xl px-4 py-3">
                    <p class="text-xs text-muted mb-0.5">Похарчено</p>
                    <p class="font-bold text-ink tabular-nums" x-text="fmt(creditsData?.summary?.spent) + ' кр.'">—</p>
                </div>
                <div class="bg-surface border border-line rounded-xl px-4 py-3">
                    <p class="text-xs text-muted mb-0.5">Грант</p>
                    <p class="font-bold text-success-strong tabular-nums" x-text="fmt(creditsData?.summary?.granted) + ' кр.'">—</p>
                </div>
                <div class="bg-surface border border-line rounded-xl px-4 py-3">
                    <p class="text-xs text-muted mb-0.5">Зареждане</p>
                    <p class="font-bold text-success-strong tabular-nums" x-text="fmt(creditsData?.summary?.topped_up) + ' кр.'">—</p>
                </div>
                <div class="bg-surface border border-line rounded-xl px-4 py-3">
                    <p class="text-xs text-muted mb-0.5">Върнато</p>
                    <p class="font-bold text-ink tabular-nums" x-text="fmt(creditsData?.summary?.refunded) + ' кр.'">—</p>
                </div>
                <div class="bg-surface border border-line rounded-xl px-4 py-3">
                    <p class="text-xs text-muted mb-0.5">Овърдрафт</p>
                    <p class="font-bold text-danger-strong tabular-nums" x-text="fmt(creditsData?.summary?.overage) + ' кр.'">—</p>
                </div>
            </div>

            {{-- Таблица --}}
            <div class="bg-surface border border-line rounded-xl overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="border-b border-line bg-surface-alt">
                        <tr>
                            <th class="px-4 py-2.5 text-left text-xs font-medium text-muted">Дата</th>
                            <th class="px-4 py-2.5 text-left text-xs font-medium text-muted">Тип</th>
                            <th class="px-4 py-2.5 text-left text-xs font-medium text-muted">Произход</th>
                            <th class="px-4 py-2.5 text-right text-xs font-medium text-muted">Сума</th>
                            <th class="px-4 py-2.5 text-right text-xs font-medium text-muted">Баланс след</th>
                            <th class="px-4 py-2.5 text-left text-xs font-medium text-muted">Услуга</th>
                            <th class="px-4 py-2.5 text-left text-xs font-medium text-muted">Субект</th>
                            <th class="px-4 py-2.5 text-right text-xs font-medium text-muted">USD</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <template x-for="row in creditsData?.rows ?? []" :key="row.id">
                            <tr class="hover:bg-surface-alt transition cursor-pointer"
                                @click="row.reservation_id && openReservation(row.reservation_id)">
                                <td class="px-4 py-2.5 text-muted tabular-nums whitespace-nowrap" x-text="row.created_at"></td>
                                <td class="px-4 py-2.5">
                                    <span :class="{
                                        'text-success-strong': row.direction === 'credit',
                                        'text-danger-strong': row.direction === 'debit' && row.amount > 0,
                                        'text-muted': row.direction === 'debit' && row.amount === 0
                                    }" x-text="row.type_label"></span>
                                </td>
                                <td class="px-4 py-2.5">
                                    <span class="text-xs px-1.5 py-0.5 rounded bg-surface-alt text-muted"
                                          x-text="row.origin_label"></span>
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums font-medium"
                                    :class="row.amount === 0 ? 'text-muted' : (row.direction === 'debit' ? 'text-danger-strong' : 'text-success-strong')"
                                    x-text="(row.amount === 0 ? '' : (row.direction === 'debit' ? '−' : '+')) + fmt(row.amount) + ' кр.'"></td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-muted"
                                    x-text="row.wallet_balance_after !== null ? fmt(row.wallet_balance_after) + ' кр.' : '—'"></td>
                                <td class="px-4 py-2.5 text-muted" x-text="row.service_label || '—'"></td>
                                <td class="px-4 py-2.5 text-muted text-xs truncate max-w-[160px]" x-text="row.subject_label || '—'"></td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-muted"
                                    x-text="row.cost_usd !== null ? '$' + fmtUsd(row.cost_usd) : '—'"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <div class="px-4 py-3 border-t border-line flex items-center justify-between text-sm text-muted">
                    <span x-text="'Общо: ' + (creditsData?.total ?? 0) + ' записа'"></span>
                    <div class="flex gap-2">
                        <button @click="creditsPage > 1 && loadCredits(creditsPage - 1)"
                                :disabled="creditsPage <= 1"
                                class="px-3 py-1 border border-line rounded-lg disabled:opacity-40">‹</button>
                        <span x-text="'Стр. ' + creditsPage"></span>
                        <button @click="creditsPage * creditsLimit < (creditsData?.total ?? 0) && loadCredits(creditsPage + 1)"
                                :disabled="creditsPage * creditsLimit >= (creditsData?.total ?? 0)"
                                class="px-3 py-1 border border-line rounded-lg disabled:opacity-40">›</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Табове: По услуги, Flows, Организация, Нетаксувани, Външни, Знания, OCR, Grid ── --}}
    @php
        $tabDefs = [
            'services' => ['title' => 'По услуга', 'cols' => [
                ['l' => 'Услуга', 'f' => 'label', 'fmt' => 'text', 'tip' => 'Видът AI дейност, която фирмата ползва'],
                ['l' => 'Заявки', 'f' => 'call_count', 'fmt' => 'int', 'tip' => 'Брой LLM повиквания'],
                ['l' => 'Токени', 'f' => 'tokens', 'fmt' => 'k', 'tip' => 'Общо обработени токени (вход + изход)'],
                ['l' => 'USD', 'f' => 'cost_usd', 'fmt' => 'usd', 'tip' => 'Реалната ти цена за доставчика (твой разход)'],
                ['l' => 'Кредити', 'f' => 'spent_credits', 'fmt' => 'credits_smart', 'tip' => 'Кредити, таксувани на фирмата (≈ = приблизителни, без резервация)'],
            ]],
            'flows' => ['title' => 'По Flow', 'cols' => [
                ['l' => 'Flow', 'f' => 'flow_name', 'fmt' => 'text', 'tip' => 'Името на flow-а'],
                ['l' => 'Изпълнения', 'f' => 'run_count', 'fmt' => 'int', 'tip' => 'Брой пускания'],
                ['l' => 'Заявки', 'f' => 'call_count', 'fmt' => 'int', 'tip' => 'LLM повиквания'],
                ['l' => 'USD', 'f' => 'cost_usd', 'fmt' => 'usd', 'tip' => 'Реален разход (твой)'],
                ['l' => 'Кредити', 'f' => 'spent_credits', 'fmt' => 'credits', 'tip' => 'Таксувани на фирмата'],
            ]],
            'org' => ['title' => 'По член', 'cols' => [
                ['l' => 'Член', 'f' => 'member_name', 'fmt' => 'text', 'tip' => 'Член на екипа'],
                ['l' => 'Вид', 'f' => 'member_kind', 'fmt' => 'text', 'tip' => 'Управител / директор / асистент'],
                ['l' => 'Кредити', 'f' => 'spent_credits', 'fmt' => 'credits', 'tip' => 'Таксувани на фирмата'],
                ['l' => 'USD', 'f' => 'cost_usd', 'fmt' => 'usd', 'tip' => 'Реален разход (твой)'],
            ]],
            'unbilled' => ['title' => 'Нетаксувани', 'cols' => [
                ['l' => 'Услуга', 'f' => 'service_label', 'fmt' => 'text', 'tip' => 'Дейност без резервация (нетаксувана с кредити)'],
                ['l' => 'Заявки', 'f' => 'call_count', 'fmt' => 'int', 'tip' => 'LLM повиквания'],
                ['l' => 'Токени', 'f' => 'tokens', 'fmt' => 'k', 'tip' => 'Общо токени'],
                ['l' => 'USD', 'f' => 'cost_usd', 'fmt' => 'usd', 'tip' => 'Реален разход (твой), без таксуване'],
                ['l' => '≈Кредити', 'f' => 'est_credits', 'fmt' => 'credits', 'tip' => 'Приблизителни кредити (по разход)'],
            ]],
            'external' => ['title' => 'Външни API', 'cols' => [
                ['l' => 'Дата', 'f' => 'created_at', 'fmt' => 'text', 'tip' => ''],
                ['l' => 'Провайдър', 'f' => 'provider_label', 'fmt' => 'text', 'tip' => 'Brave / Perplexity / Google Places'],
                ['l' => 'Заявка', 'f' => 'query', 'fmt' => 'text', 'tip' => 'Търсене / заявка'],
                ['l' => 'USD', 'f' => 'cost_usd', 'fmt' => 'usd', 'tip' => 'Реален разход (твой)'],
                ['l' => 'Кредити', 'f' => 'credits', 'fmt' => 'credits', 'tip' => 'Таксувани на фирмата'],
            ]],
            'knowledge' => ['title' => 'Знания', 'cols' => [
                ['l' => 'Дата', 'f' => 'created_at', 'fmt' => 'text', 'tip' => ''],
                ['l' => 'Цел', 'f' => 'purpose_label', 'fmt' => 'text', 'tip' => 'Embeddings / синтез / чат / OCR'],
                ['l' => 'Провайдър', 'f' => 'provider', 'fmt' => 'text', 'tip' => ''],
                ['l' => 'Токени', 'f' => 'tokens', 'fmt' => 'k', 'tip' => 'Общо токени'],
                ['l' => 'USD', 'f' => 'cost_usd', 'fmt' => 'usd', 'tip' => 'Реален разход (твой)'],
            ]],
            'ocr' => ['title' => 'OCR', 'cols' => [
                ['l' => 'Дата', 'f' => 'created_at', 'fmt' => 'text', 'tip' => ''],
                ['l' => 'Документ', 'f' => 'document', 'fmt' => 'text', 'tip' => 'Сканиран документ'],
                ['l' => 'Стр.', 'f' => 'pages', 'fmt' => 'int', 'tip' => 'Брой страници'],
                ['l' => 'USD', 'f' => 'cost_usd', 'fmt' => 'usd', 'tip' => 'Реален разход (твой)'],
            ]],
            'grid' => ['title' => 'Всички заявки', 'cols' => [
                ['l' => 'Тип', 'f' => 'row_type', 'fmt' => 'text', 'tip' => 'Изпълнение (run) / генерация (gen)'],
                ['l' => 'Дата', 'f' => 'created_at', 'fmt' => 'text', 'tip' => ''],
                ['l' => 'Провайдър', 'f' => 'provider', 'fmt' => 'text', 'tip' => ''],
                ['l' => 'Flow', 'f' => 'flow', 'fmt' => 'text', 'tip' => ''],
                ['l' => 'Заявки', 'f' => 'call_count', 'fmt' => 'int', 'tip' => 'LLM повиквания в групата'],
                ['l' => 'USD', 'f' => 'cost_usd', 'fmt' => 'usd', 'tip' => 'Реален разход (твой)'],
            ]],
        ];
    @endphp
    @foreach($tabDefs as $tab => $def)
    <div x-show="activeTab === '{{ $tab }}'" x-cloak>
        <div class="bg-surface border border-line rounded-xl p-8 text-center text-muted text-sm" x-show="!tabData['{{ $tab }}']">
            Зарежда се…
        </div>
        <div x-show="tabData['{{ $tab }}']" class="bg-surface border border-line rounded-xl overflow-hidden">
            <div class="px-5 py-3 border-b border-line flex items-center justify-between gap-3 flex-wrap">
                <h3 class="font-semibold text-ink text-sm">{{ $def['title'] }}</h3>
                <span class="text-xs text-muted">
                    <span class="font-medium text-ink">USD</span> = твой разход ·
                    <span class="font-medium text-ink">Кредити</span> = таксувани на фирмата ·
                    <span class="text-warning-strong">≈</span> приблизителни ·
                    <span class="font-medium text-ink">Токени</span> = вход+изход
                </span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-line bg-surface-alt">
                        <tr>
                            @foreach($def['cols'] as $col)
                            <th class="px-4 py-2.5 text-left text-xs font-medium text-muted whitespace-nowrap"
                                @if($col['tip']) title="{{ $col['tip'] }}" @endif>
                                {{ $col['l'] }}@if($col['tip'])<span class="text-subtle ml-0.5 cursor-help">ⓘ</span>@endif
                            </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <template x-for="(row, i) in tabData['{{ $tab }}']?.rows ?? []" :key="i">
                            <tr class="hover:bg-surface-alt transition">
                                @foreach($def['cols'] as $col)
                                <td class="px-4 py-2.5 text-muted truncate max-w-[220px]"
                                    @if($tab === 'services' && $col['f'] === 'label') :title="serviceDesc(row.service_key)" @endif
                                    x-text="cell(row, @js($col))"></td>
                                @endforeach
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3 border-t border-line flex items-center justify-between text-sm text-muted">
                <span x-text="'Общо: ' + (tabData['{{ $tab }}']?.total ?? 0)"></span>
                <div class="flex gap-2 items-center">
                    <button @click="loadTab('{{ $tab }}', Math.max(1, (tabPages['{{ $tab }}'] || 1) - 1))"
                            :disabled="(tabPages['{{ $tab }}'] || 1) <= 1"
                            class="px-3 py-1 border border-line rounded-lg disabled:opacity-40">‹</button>
                    <span x-text="'Стр. ' + (tabPages['{{ $tab }}'] || 1)"></span>
                    <button @click="loadTab('{{ $tab }}', (tabPages['{{ $tab }}'] || 1) + 1)"
                            :disabled="((tabPages['{{ $tab }}'] || 1) * 25) >= (tabData['{{ $tab }}']?.total ?? 0)"
                            class="px-3 py-1 border border-line rounded-lg disabled:opacity-40">›</button>
                </div>
            </div>
        </div>
    </div>
    @endforeach

    {{-- ── Модал: детайл на резервация ─────────────────────────────── --}}
    <div x-show="resModal.open" x-cloak
         class="fixed inset-0 z-50 flex items-end md:items-center justify-center bg-black/40 backdrop-blur-sm"
         @click.self="resModal.open = false">
        <div class="bg-surface rounded-2xl shadow-xl w-full max-w-2xl max-h-[90vh] flex flex-col mx-4">
            <div class="flex items-center justify-between px-6 py-4 border-b border-line">
                <h3 class="font-semibold text-ink">Резервация #<span x-text="resModal.id"></span></h3>
                <button @click="resModal.open = false" class="text-muted hover:text-ink transition">
                    <x-icon name="x-mark" size="5" />
                </button>
            </div>
            <div class="flex-1 overflow-y-auto px-6 py-4 space-y-4">
                <template x-if="resModal.loading">
                    <p class="text-center text-muted text-sm py-8">Зарежда се…</p>
                </template>
                <template x-if="!resModal.loading && resModal.data">
                    <div class="space-y-4">
                        {{-- Хедър --}}
                        <div class="space-y-3">
                            <div class="flex items-start justify-between gap-4">
                                <p class="font-semibold text-ink text-sm" x-text="resModal.data.reservation.context_label"></p>
                                <span class="shrink-0 inline-flex items-center text-xs font-medium px-2 py-0.5 rounded-md"
                                      :class="resStatusClass(resModal.data.reservation.status)"
                                      x-text="resStatusLabel(resModal.data.reservation.status)"></span>
                            </div>
                            <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted">
                                <span><span class="text-subtle">Субект:</span> <span class="text-ink" x-text="resModal.data.reservation.subject_label || '—'"></span></span>
                                <span><span class="text-subtle">Произход:</span> <span class="text-ink" x-text="resModal.data.reservation.origin_label"></span></span>
                                <span x-show="resModal.data.reservation.created_at"><span class="text-subtle">Дата:</span> <span class="text-ink tabular-nums" x-text="resModal.data.reservation.created_at"></span></span>
                            </div>
                            <div class="grid grid-cols-3 gap-3">
                                <div class="bg-surface border border-line rounded-xl px-4 py-3">
                                    <p class="text-xs text-muted mb-0.5">Резервирано</p>
                                    <p class="font-bold text-ink tabular-nums" x-text="fmt(resModal.data.reservation.estimated_credits) + ' кр.'"></p>
                                </div>
                                <div class="bg-surface border border-line rounded-xl px-4 py-3">
                                    <p class="text-xs text-muted mb-0.5">Похарчено</p>
                                    <p class="font-bold text-danger-strong tabular-nums" x-text="fmt(resModal.data.reservation.spent_credits) + ' кр.'"></p>
                                </div>
                                <div class="bg-surface border border-line rounded-xl px-4 py-3">
                                    <p class="text-xs text-muted mb-0.5">Върнато</p>
                                    <p class="font-bold text-success-strong tabular-nums"
                                       x-text="fmt(Math.max(0, resModal.data.reservation.estimated_credits - resModal.data.reservation.spent_credits)) + ' кр.'"></p>
                                </div>
                            </div>
                        </div>
                        {{-- Ledger --}}
                        <div>
                            <h4 class="text-xs font-semibold text-muted uppercase tracking-wide mb-2">Ledger редове</h4>
                            <x-table :headers="['Дата', 'Тип', 'Сума', 'Баланс след']">
                                <template x-for="e in resModal.data.ledger" :key="e.id">
                                    <tr class="text-xs">
                                        <td class="px-4 py-2 text-muted tabular-nums whitespace-nowrap" x-text="e.created_at"></td>
                                        <td class="px-4 py-2 text-ink" x-text="e.type_label"></td>
                                        <td class="px-4 py-2 text-right tabular-nums font-medium"
                                            :class="e.direction === 'debit' ? 'text-danger-strong' : 'text-success-strong'"
                                            x-text="(e.direction === 'debit' ? '−' : '+') + fmt(e.amount) + ' кр.'"></td>
                                        <td class="px-4 py-2 text-right tabular-nums text-muted"
                                            x-text="e.wallet_balance_after !== null ? fmt(e.wallet_balance_after) + ' кр.' : '—'"></td>
                                    </tr>
                                </template>
                            </x-table>
                        </div>
                        {{-- LLM заявки --}}
                        <div>
                            <h4 class="text-xs font-semibold text-muted uppercase tracking-wide mb-2">LLM заявки</h4>
                            <template x-if="(resModal.data.requests ?? []).length === 0">
                                <p class="text-sm text-muted">Няма LLM заявки за тази резервация.</p>
                            </template>
                            <template x-if="(resModal.data.requests ?? []).length > 0">
                                <x-table :headers="['Дата', 'Модел', 'Токени', 'USD']">
                                    <template x-for="req in resModal.data.requests" :key="req.id">
                                        <tr class="text-xs">
                                            <td class="px-4 py-2 text-muted tabular-nums whitespace-nowrap" x-text="req.created_at"></td>
                                            <td class="px-4 py-2 text-ink truncate max-w-[200px]" x-text="req.provider + '/' + req.model"></td>
                                            <td class="px-4 py-2 text-right tabular-nums text-muted"
                                                x-text="(req.prompt_tokens || 0) + '+' + (req.completion_tokens || 0) + ' tok'"></td>
                                            <td class="px-4 py-2 text-right tabular-nums text-ink" x-text="'$' + fmtUsd(req.cost_usd)"></td>
                                        </tr>
                                    </template>
                                </x-table>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>

</div>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
function companyStats() {
    const baseUrl = '{{ route('companies.stats.data.overview', $company) }}'.replace('/data/overview', '');
    const PALETTE = ['#2563eb', '#0ea5e9', '#14b8a6', '#a855f7', '#f59e0b', '#ec4899', '#10b981', '#ef4444', '#6366f1', '#84cc16'];
    const GRID = 'rgba(148,163,184,0.15)';

    return {
        activeTab: 'overview',
        filters: {
            provider: '{{ $filters['provider'] ?? '' }}',
            context_type: '{{ $filters['context_type'] ?? '' }}',
            from: '{{ $filters['from'] ?? '' }}',
            to: '{{ $filters['to'] ?? '' }}',
        },

        overview: null,
        charts: {},
        labels: @js($statsLabels ?? []),
        creditsData: null,
        creditsLoading: false,
        creditsPage: 1,
        creditsLimit: 25,
        tabData: {},
        tabPages: {},
        loadedTabs: new Set(),

        resModal: { open: false, id: null, loading: false, data: null },

        init() {
            this.loadOverview();
        },

        buildParams(extra = {}) {
            const p = new URLSearchParams();
            if (this.filters.provider) p.set('provider', this.filters.provider);
            if (this.filters.context_type) p.set('context_type', this.filters.context_type);
            if (this.filters.from) p.set('from', this.filters.from);
            if (this.filters.to) p.set('to', this.filters.to);
            Object.entries(extra).forEach(([k, v]) => p.set(k, v));
            return p.toString();
        },

        async fetch(path, extra = {}) {
            const qs = this.buildParams(extra);
            const res = await window.fetch(`${baseUrl}/${path}${qs ? '?' + qs : ''}`);
            return res.json();
        },

        async loadOverview() {
            this.overview = await this.fetch('data/overview');
            this.$nextTick(() => this.renderCharts());
        },

        renderCharts() {
            if (typeof Chart === 'undefined' || !this.overview) return;
            const c = this.overview.charts || {};
            Object.values(this.charts).forEach(ch => { try { ch.destroy(); } catch (e) {} });
            this.charts = {};

            const base = {
                responsive: true, maintainAspectRatio: false, animation: false,
                plugins: { legend: { labels: { boxWidth: 12, font: { size: 11 } } } },
                scales: { x: { grid: { color: GRID }, ticks: { font: { size: 10 } } }, y: { grid: { color: GRID }, ticks: { font: { size: 10 } }, beginAtZero: true } },
            };
            const mk = (ref, cfg) => { const el = this.$refs[ref]; if (el) this.charts[ref] = new Chart(el, cfg); };

            // Разход по ден — линия (при малко дни по-големи точки, за да се виждат)
            const sd = c.spendByDay || { labels: [], cost: [] };
            const spendPointRadius = (sd.labels?.length ?? 0) <= 3 ? 5 : 2;
            mk('chartSpend', { type: 'line', data: { labels: sd.labels, datasets: [{ label: 'USD', data: sd.cost, borderColor: PALETTE[0], backgroundColor: PALETTE[0] + '22', fill: true, tension: 0.3, pointRadius: spendPointRadius, pointHoverRadius: spendPointRadius + 2 }] }, options: base });

            // Кредитни движения по ден — stacked bar
            const cd = c.creditsByDay || { labels: [], datasets: [] };
            mk('chartCredits', { type: 'bar', data: { labels: cd.labels, datasets: (cd.datasets || []).map((d, i) => ({ label: d.label, data: d.data, backgroundColor: PALETTE[i % PALETTE.length] })) }, options: { ...base, scales: { x: { stacked: true, grid: { color: GRID } }, y: { stacked: true, grid: { color: GRID }, beginAtZero: true } } } });

            // USD ↔ кредити по услуга — grouped bar (двойна ос)
            const cs = c.costByService || { labels: [], cost: [], credits: [] };
            mk('chartService', { type: 'bar', data: { labels: cs.labels, datasets: [
                { label: 'USD', data: cs.cost, backgroundColor: PALETTE[0], yAxisID: 'y' },
                { label: 'Кредити', data: cs.credits, backgroundColor: PALETTE[3], yAxisID: 'y1' },
            ] }, options: { ...base, scales: {
                x: { grid: { color: GRID }, ticks: { font: { size: 9 } } },
                y: { position: 'left', grid: { color: GRID }, beginAtZero: true, title: { display: true, text: 'USD', font: { size: 9 } } },
                y1: { position: 'right', grid: { display: false }, beginAtZero: true, title: { display: true, text: 'кр.', font: { size: 9 } } },
            } } });

            // Разход по провайдър — doughnut
            const pv = this.overview.providers || [];
            mk('chartProvider', { type: 'doughnut', data: { labels: pv.map(p => p.provider), datasets: [{ data: pv.map(p => p.cost), backgroundColor: PALETTE }] }, options: { responsive: true, maintainAspectRatio: false, animation: false, plugins: { legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 } } } } } });

            // Топ модели — хоризонтален bar (x = USD стойности, y = категории)
            const bm = c.byModel || { labels: [], data: [] };
            mk('chartModel', { type: 'bar', data: { labels: bm.labels, datasets: [{ label: 'USD', data: bm.data, backgroundColor: PALETTE[1] }] }, options: {
                responsive: true, maintainAspectRatio: false, animation: false,
                indexAxis: 'y',
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, grid: { color: GRID }, ticks: { font: { size: 10 }, callback: v => '$' + Number(v).toFixed(4) } },
                    y: { grid: { color: GRID }, ticks: { font: { size: 10 } } },
                },
            } });
        },

        async loadCredits(page = 1) {
            this.creditsLoading = true;
            this.creditsPage = page;
            this.creditsData = await this.fetch('data/credits', { page, limit: this.creditsLimit, sort: 'created_at', dir: 'desc' });
            this.creditsLoading = false;
        },

        async loadTab(tab, page = 1) {
            this.tabPages[tab] = page;
            const data = await this.fetch(`data/${tab}`, { page, limit: 25, sort: 'created_at', dir: 'desc' });
            this.tabData = { ...this.tabData, [tab]: data };
        },

        switchTab(tab) {
            this.activeTab = tab;
            if (tab === 'credits' && !this.creditsData) {
                this.loadCredits(1);
            } else if (tab !== 'overview' && tab !== 'credits' && !this.tabData[tab]) {
                this.loadTab(tab, 1);
            }
        },

        reloadAll() {
            this.overview = null;
            this.creditsData = null;
            this.tabData = {};
            this.loadOverview();
            if (this.activeTab === 'credits') this.loadCredits(1);
            else if (this.activeTab !== 'overview') this.loadTab(this.activeTab, 1);
        },

        clearFilters() {
            this.filters = { provider: '', context_type: '', from: '', to: '' };
            this.reloadAll();
        },

        async openReservation(id) {
            this.resModal = { open: true, id, loading: true, data: null };
            const data = await this.fetch('reservation-detail', { id });
            this.resModal.loading = false;
            this.resModal.data = data;
        },

        maxProviderCost() {
            const providers = this.overview?.providers ?? [];
            return Math.max(...providers.map(p => p.cost), 0.001);
        },

        maxServiceCost() {
            const rows = this.overview?.charts?.costByService?.rows ?? [];
            return Math.max(...rows.map(r => r.cost_usd), 0.001);
        },

        pct(val, max) {
            if (!max) return 0;
            return Math.min(100, Math.round((val / max) * 100));
        },

        fmt(v) {
            if (v === null || v === undefined) return '—';
            return Number(v).toLocaleString('bg-BG');
        },

        fmtUsd(v) {
            if (v === null || v === undefined) return '—';
            return Number(v).toFixed(4);
        },

        resStatusLabel(status) {
            return {
                reserved: 'Резервирано',
                settled: 'Уредено',
                refunded: 'Върнато',
                expired: 'Изтекло',
            }[status] ?? status;
        },

        resStatusClass(status) {
            return {
                reserved: 'bg-warning-soft text-warning-strong',
                settled: 'bg-success-soft text-success-strong',
                refunded: 'bg-info-soft text-info-strong',
                expired: 'bg-neutral-soft text-neutral-strong',
            }[status] ?? 'bg-neutral-soft text-neutral-strong';
        },

        fmtK(v) {
            if (!v) return '0';
            if (v >= 1_000_000) return (v / 1_000_000).toFixed(1) + 'M';
            if (v >= 1000) return (v / 1000).toFixed(1) + 'K';
            return String(v);
        },

        // Кратко описание на услугата (tooltip в колоната „Услуга").
        serviceDesc(key) {
            return this.labels?.services?.[key]?.desc ?? '';
        },

        // Форматира една клетка по дефиницията на колоната {f: поле, fmt: формат}.
        cell(row, col) {
            const v = row[col.f];
            switch (col.fmt) {
                case 'int': return this.fmt(v);
                case 'k': return this.fmtK(v);
                case 'usd': return (v === null || v === undefined) ? '—' : '$' + this.fmtUsd(v);
                case 'credits': return (v === null || v === undefined || v === '') ? '—' : this.fmt(v) + ' кр.';
                case 'credits_smart':
                    return row.credit_type === 'estimated'
                        ? '≈' + this.fmt(row.est_credits) + ' кр.'
                        : this.fmt(v) + ' кр.';
                default: return (v === null || v === undefined || v === '') ? '—' : v;
            }
        },
    };
}
</script>
@endpush
@endsection
