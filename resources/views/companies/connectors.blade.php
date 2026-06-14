@extends('layouts.app')

@section('title', 'Свързани системи — ' . $company->name)

@section('content')
<div x-data="companyConnectors(@js($config))" x-init="init()">

    {{-- Header --}}
    <div class="flex items-start justify-between mb-6 flex-wrap gap-3">
        <div>
            <a :href="config.backUrl" class="inline-flex items-center gap-1 text-sm text-muted hover:text-ink transition">
                <x-icon name="arrow-left" size="4" /> {{ $company->name }}
            </a>
            <h1 class="text-2xl font-display font-bold text-ink mt-2">Свързани системи</h1>
            <p class="text-muted mt-1 text-sm max-w-prose">Свържи Gmail, Sheets, Drive, Slack, Notion, Airtable или HTTP API — агентите ще четат и пишат в тях.</p>
        </div>
    </div>

    {{-- Свързани --}}
    <div class="text-sm font-medium text-muted mb-2">Свързани</div>
    <div class="mb-8">
        <template x-if="!loading && connectors.length === 0">
            <x-card :padding="false">
                <x-empty-state icon="puzzle-piece" title="Няма свързани системи" message="Избери услуга от каталога долу." />
            </x-card>
        </template>

        <div class="space-y-2">
            <template x-for="c in connectors" :key="c.id">
                <div class="flex items-center gap-4 bg-surface border border-line rounded-xl px-5 py-3.5">
                    <div class="w-10 h-10 rounded-lg bg-surface-subtle border border-line flex items-center justify-center text-lg shrink-0" x-text="iconFor(c.connector_type)"></div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="font-medium text-ink" x-text="c.display_name || c.connector_type"></span>
                            <span class="text-xs font-mono text-subtle" x-text="c.connector_type"></span>
                        </div>
                        <div class="text-xs text-subtle mt-0.5">
                            <span x-show="c.last_tested_at">Проверен: <span x-text="c.last_tested_at"></span></span>
                            <span x-show="c.last_error" class="text-danger" x-text="(c.last_tested_at ? ' · ' : '') + c.last_error"></span>
                        </div>
                    </div>
                    <span class="inline-flex items-center text-xs px-2 py-0.5 rounded-md font-medium" :class="badgeClass(c.status)" x-text="statusLabel(c.status)"></span>
                    <div class="flex items-center gap-1 shrink-0">
                        <button @click="testConn(c)" :disabled="c._busy" title="Провери" aria-label="Провери"
                                class="p-2 rounded-md text-subtle hover:text-primary hover:bg-info-soft transition disabled:opacity-50">
                            <x-icon name="arrow-path" size="4" ::class="c._busy ? 'animate-spin' : ''" />
                        </button>
                        <button @click="openHistory(c)" title="История" aria-label="История"
                                class="p-2 rounded-md text-subtle hover:text-ink hover:bg-surface-subtle transition">
                            <x-icon name="clock" size="4" />
                        </button>
                        <button @click="remove(c)" title="Прекъсни" aria-label="Прекъсни"
                                class="p-2 rounded-md text-subtle hover:text-danger hover:bg-danger-soft transition">
                            <x-icon name="x-mark" size="4" />
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </div>

    {{-- Добави услуга (каталог) --}}
    <div class="text-sm font-medium text-muted mb-2">Добави услуга</div>
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 mb-8">
        <template x-for="t in types" :key="t.type">
            <div class="bg-surface border border-line rounded-xl p-4 flex flex-col">
                <div class="flex items-center gap-2 mb-1">
                    <span class="text-lg" x-text="t.icon"></span>
                    <span class="font-medium text-ink text-sm" x-text="t.label"></span>
                </div>
                <div class="text-xs text-muted mb-3" x-text="t.hint"></div>
                <div class="mt-auto space-y-1.5">
                    <span x-show="connectedCount(t.type)" class="inline-flex items-center gap-1 text-xs text-success-strong font-medium">
                        <x-icon name="check" size="3.5" /><span x-text="connectedCount(t.type)"></span> свързан(и)
                    </span>
                    <template x-if="t.auth === 'oauth2'">
                        <a :href="oauthUrl(t)"
                           class="block text-center bg-primary hover:bg-primary-hover text-primary-fg text-sm font-medium px-3 py-1.5 rounded-md transition">
                            <span x-text="connectedCount(t.type) ? '+ Нов акаунт' : (t.provider === 'google' ? 'Свържи с Google' : 'Свържи')"></span>
                        </a>
                    </template>
                    <template x-if="t.auth !== 'oauth2'">
                        <button @click="openApiKey(t)"
                                class="w-full bg-surface border border-line-strong hover:bg-surface-subtle text-ink text-sm font-medium px-3 py-1.5 rounded-md transition">
                            <span x-text="connectedCount(t.type) ? '+ Добави още' : 'Добави ключ'"></span>
                        </button>
                    </template>
                </div>
            </div>
        </template>
    </div>

    {{-- Connect-modes hint --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <div class="bg-surface-subtle border border-line rounded-lg px-4 py-3 text-xs text-muted leading-relaxed">
            <span class="text-ink font-medium">OAuth (Google / Slack)</span><br>Един клик → вход → готово. Общ FlowAI app.
        </div>
        <div class="bg-surface-subtle border border-line rounded-lg px-4 py-3 text-xs text-muted leading-relaxed">
            <span class="text-ink font-medium">API ключ (Notion / Airtable …)</span><br>Постави токена → „Провери" → готово.
        </div>
        <div class="bg-surface-subtle border border-line rounded-lg px-4 py-3 text-xs text-muted leading-relaxed">
            <span class="text-ink font-medium">Употреба във Flow</span><br>Builder → „MCP Действие" node → конектор + действие.
        </div>
    </div>

    {{-- API-key modal --}}
    <div x-show="apiType" x-cloak @keydown.escape.window="apiType = null"
         class="fixed inset-0 z-50 flex items-center justify-center bg-ink/50 p-4">
        <div class="bg-surface rounded-xl shadow-popover w-full max-w-md p-6" @click.outside="apiType = null">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-medium text-ink">Свържи <span x-text="apiType?.label"></span></h3>
                <button @click="apiType = null" aria-label="Затвори" class="text-subtle hover:text-ink rounded-md p-1">
                    <x-icon name="x-mark" size="5" />
                </button>
            </div>
            <form @submit.prevent="submitApiKey()">
                <label class="block text-sm font-medium text-ink mb-1">Име (по избор)</label>
                <input x-model="apiForm.display_name" type="text" placeholder="напр. Фирмен акаунт"
                       class="w-full rounded-lg border border-line hover:border-line-strong bg-surface px-3 py-2 text-sm text-ink mb-3 focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary">
                <template x-for="f in (apiType?.fields || [])" :key="f.key">
                    <div class="mb-3">
                        <label class="block text-sm font-medium text-ink mb-1" x-text="f.label"></label>
                        <input :type="f.type || 'text'" x-model="apiForm.credentials[f.key]"
                               class="w-full rounded-lg border border-line hover:border-line-strong bg-surface px-3 py-2 text-sm text-ink font-mono focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary">
                    </div>
                </template>
                <p x-show="apiError" x-text="apiError" class="text-sm text-danger mb-2"></p>
                <div class="flex justify-end gap-2 mt-2">
                    <button type="button" @click="apiType = null" class="px-4 h-10 text-sm font-medium text-muted hover:text-ink hover:bg-surface-subtle rounded-md transition">Отказ</button>
                    <button type="submit" :disabled="saving"
                            class="inline-flex items-center justify-center bg-primary hover:bg-primary-hover text-primary-fg px-4 h-10 rounded-md text-sm font-medium transition disabled:opacity-60">
                        <span x-text="saving ? 'Свързвам…' : 'Свържи и провери'"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- History modal --}}
    <div x-show="showHistory" x-cloak @keydown.escape.window="showHistory = false"
         class="fixed inset-0 z-50 flex items-center justify-center bg-ink/50 p-4">
        <div class="bg-surface rounded-xl shadow-popover w-full max-w-2xl p-6" @click.outside="showHistory = false">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-medium text-ink">История на действията</h3>
                <button @click="showHistory = false" aria-label="Затвори" class="text-subtle hover:text-ink rounded-md p-1">
                    <x-icon name="x-mark" size="5" />
                </button>
            </div>
            <div class="max-h-96 overflow-y-auto text-sm" style="overscroll-behavior: contain;">
                <template x-if="historyLogs.length === 0">
                    <p class="text-subtle py-6 text-center">Няма записани действия още.</p>
                </template>
                <template x-for="l in historyLogs" :key="l.id">
                    <div class="flex items-start gap-3 py-2 border-b border-line">
                        <x-icon name="check-circle" size="4" class="text-success shrink-0 mt-0.5" x-show="l.status === 'ok'" />
                        <x-icon name="x-circle" size="4" class="text-danger shrink-0 mt-0.5" x-show="l.status !== 'ok'" />
                        <div class="min-w-0 flex-1">
                            <span class="font-mono text-xs text-ink" x-text="l.tool"></span>
                            <span class="text-muted" x-text="' — ' + (l.result_summary || l.error || '')"></span>
                        </div>
                        <span class="text-xs text-subtle shrink-0 tabular-nums" x-text="l.created_at + ' · ' + (l.duration_ms || 0) + 'ms'"></span>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function companyConnectors(config) {
    return {
        config,
        loading: true,
        saving: false,
        connectors: [],
        types: [],
        apiType: null,
        apiForm: { display_name: '', credentials: {} },
        apiError: '',
        showHistory: false,
        historyLogs: [],

        async init() { await this.load(); },

        async load() {
            this.loading = true;
            const res = await fetch(this.config.base + '/data', { headers: { 'Accept': 'application/json' } });
            const json = await res.json();
            this.connectors = (json.connectors || []).map(c => ({ ...c, _busy: false }));
            this.types = json.types || [];
            this.loading = false;
        },

        isConnected(type) { return this.connectors.some(c => c.connector_type === type); },
        connectedCount(type) { return this.connectors.filter(c => c.connector_type === type).length; },

        oauthUrl(t) {
            if (t.provider === 'google') return `${this.config.googleRedirect}?service=${t.service}`;
            if (t.provider === 'slack') return this.config.slackRedirect;
            return '#';
        },

        openApiKey(t) { this.apiType = t; this.apiForm = { display_name: '', credentials: {} }; this.apiError = ''; },

        async submitApiKey() {
            this.saving = true; this.apiError = '';
            try {
                const res = await this.req(this.config.base, 'POST', {
                    connector_type: this.apiType.type,
                    auth_type: this.apiType.auth,
                    display_name: this.apiForm.display_name || null,
                    credentials: this.apiForm.credentials,
                });
                if (!res.ok) { this.apiError = 'Грешка при свързване.'; return; }
                this.apiType = null;
                await this.load();
            } finally { this.saving = false; }
        },

        async testConn(c) {
            c._busy = true;
            const res = await this.req(`${this.config.base}/${c.id}/test`, 'POST');
            const json = await res.json().catch(() => ({}));
            if (json.connector) Object.assign(c, json.connector, { _busy: false });
            c._busy = false;
        },

        async remove(c) {
            if (!confirm('Прекъсни тази връзка? Flows, които я ползват, ще спрат да работят.')) return;
            await this.req(`${this.config.base}/${c.id}`, 'DELETE');
            await this.load();
        },

        async openHistory(c) {
            this.historyLogs = [];
            this.showHistory = true;
            const res = await fetch(`${this.config.base}/${c.id}/logs`, { headers: { 'Accept': 'application/json' } });
            const json = await res.json();
            this.historyLogs = json.logs || [];
        },

        req(url, method, body) {
            return fetch(url, {
                method,
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.config.csrf },
                body: body ? JSON.stringify(body) : undefined,
            });
        },

        iconFor(type) {
            return ({ gmail: '📧', google_sheets: '', google_drive: '📁', slack: '💬', notion: '📝', airtable: '🗂', http_api: '🔗' })[type] || '🧩';
        },
        statusLabel(s) { return ({ active: 'Активен', expired: 'Изтекъл', error: 'Грешка', revoked: 'Отнет' })[s] || s; },
        badgeClass(s) {
            return s === 'active' ? 'bg-success-soft text-success-strong'
                 : s === 'expired' ? 'bg-warning-soft text-warning-strong'
                 : 'bg-danger-soft text-danger-strong';
        },
    };
}
</script>
@endpush
@endsection
