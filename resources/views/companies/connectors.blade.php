@extends('layouts.app')

@section('title', 'Свързани системи — ' . $company->name)

@section('content')
<div x-data="companyConnectors(@js($config))" x-init="init()">

    {{-- ─────────── Header ─────────── --}}
    <div class="flex items-start justify-between mb-6 flex-wrap gap-3">
        <div>
            <a :href="config.backUrl" class="text-indigo-600 hover:underline text-sm">← {{ $company->name }}</a>
            <h1 class="text-3xl font-bold text-gray-900 mt-2 flex items-center gap-3">🔌 Свързани системи</h1>
            <p class="text-gray-500 mt-1 text-sm">Свържи Gmail, Sheets, Drive, Slack, Notion, Airtable или HTTP API — агентите ще четат и пишат в тях.</p>
        </div>
    </div>

    {{-- Flash --}}
    @if(session('success'))
    <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)"
         class="mb-4 px-4 py-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm flex items-center gap-2">
        <span>✓</span> {{ session('success') }}
    </div>
    @endif

    {{-- ─────────── Свързани ─────────── --}}
    <div class="text-sm text-gray-500 mb-2">Свързани</div>
    <div class="mb-8">
        <template x-if="!loading && connectors.length === 0">
            <div class="bg-white rounded-xl border border-dashed border-gray-300 text-center py-10">
                <p class="text-2xl mb-1">🔌</p>
                <p class="text-gray-500 font-medium">Няма свързани системи</p>
                <p class="text-gray-400 text-sm">Избери услуга от каталога долу.</p>
            </div>
        </template>

        <div class="space-y-2">
            <template x-for="c in connectors" :key="c.id">
                <div class="flex items-center gap-4 bg-white rounded-xl border border-gray-200 px-5 py-3.5">
                    <div class="w-10 h-10 rounded-full bg-indigo-50 flex items-center justify-center text-lg shrink-0" x-text="iconFor(c.connector_type)"></div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="font-semibold text-gray-900" x-text="c.display_name || c.connector_type"></span>
                            <span class="text-xs font-mono text-gray-400" x-text="c.connector_type"></span>
                        </div>
                        <div class="text-xs text-gray-400 mt-0.5">
                            <span x-show="c.last_tested_at">Проверен: <span x-text="c.last_tested_at"></span></span>
                            <span x-show="c.last_error" class="text-red-500" x-text="(c.last_tested_at ? ' · ' : '') + c.last_error"></span>
                        </div>
                    </div>
                    <span class="text-xs px-2.5 py-1 rounded-full font-medium" :class="badgeClass(c.status)" x-text="statusLabel(c.status)"></span>
                    <div class="flex items-center gap-1 shrink-0 text-sm">
                        <button @click="testConn(c)" :disabled="c._busy" title="Провери"
                                class="text-gray-500 hover:text-indigo-600 px-2 py-1 rounded hover:bg-gray-50">↺</button>
                        <button @click="openHistory(c)" title="История"
                                class="text-gray-500 hover:text-indigo-600 px-2 py-1 rounded hover:bg-gray-50">История</button>
                        <button @click="remove(c)" title="Прекъсни"
                                class="text-gray-400 hover:text-red-600 px-2 py-1 rounded hover:bg-red-50">✕</button>
                    </div>
                </div>
            </template>
        </div>
    </div>

    {{-- ─────────── Добави услуга (каталог) ─────────── --}}
    <div class="text-sm text-gray-500 mb-2">Добави услуга</div>
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 mb-8">
        <template x-for="t in types" :key="t.type">
            <div class="bg-white rounded-xl border border-gray-200 p-4 flex flex-col">
                <div class="flex items-center gap-2 mb-1">
                    <span class="text-lg" x-text="t.icon"></span>
                    <span class="font-semibold text-gray-800 text-sm" x-text="t.label"></span>
                </div>
                <div class="text-xs text-gray-400 mb-3" x-text="t.hint"></div>
                <div class="mt-auto space-y-1.5">
                    <span x-show="connectedCount(t.type)" class="block text-xs text-green-600 font-medium">
                        ✓ <span x-text="connectedCount(t.type)"></span> свързан(и)
                    </span>
                    <template x-if="t.auth === 'oauth2'">
                        <a :href="oauthUrl(t)"
                           class="block text-center bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-3 py-1.5 rounded-lg">
                            <span x-text="connectedCount(t.type) ? '+ Нов акаунт' : (t.provider === 'google' ? 'Свържи с Google' : 'Свържи')"></span>
                        </a>
                    </template>
                    <template x-if="t.auth !== 'oauth2'">
                        <button @click="openApiKey(t)"
                                class="w-full bg-white border border-gray-300 hover:border-indigo-400 text-gray-700 text-sm font-medium px-3 py-1.5 rounded-lg">
                            <span x-text="connectedCount(t.type) ? '+ Добави още' : 'Добави ключ'"></span>
                        </button>
                    </template>
                </div>
            </div>
        </template>
    </div>

    {{-- Connect-modes hint --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <div class="bg-gray-50 rounded-lg px-4 py-3 text-xs text-gray-500 leading-relaxed">
            <span class="text-gray-800 font-medium">OAuth (Google / Slack)</span><br>Един клик → вход → готово. Общ FlowAI app.
        </div>
        <div class="bg-gray-50 rounded-lg px-4 py-3 text-xs text-gray-500 leading-relaxed">
            <span class="text-gray-800 font-medium">API ключ (Notion / Airtable …)</span><br>Постави токена → „Провери" → готово.
        </div>
        <div class="bg-gray-50 rounded-lg px-4 py-3 text-xs text-gray-500 leading-relaxed">
            <span class="text-gray-800 font-medium">Употреба във Flow</span><br>Builder → „MCP Действие" node → конектор + действие.
        </div>
    </div>

    {{-- ─────────── API-key modal ─────────── --}}
    <div x-show="apiType" x-cloak @keydown.escape.window="apiType = null"
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6" @click.outside="apiType = null">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900">Свържи <span x-text="apiType?.label"></span></h3>
                <button @click="apiType = null" class="text-gray-400 hover:text-gray-600">✕</button>
            </div>
            <form @submit.prevent="submitApiKey()">
                <label class="block text-xs font-medium text-gray-500 mb-1">Име (по избор)</label>
                <input x-model="apiForm.display_name" type="text" placeholder="напр. Фирмен акаунт"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm mb-3">
                <template x-for="f in (apiType?.fields || [])" :key="f.key">
                    <div class="mb-3">
                        <label class="block text-xs font-medium text-gray-500 mb-1" x-text="f.label"></label>
                        <input :type="f.type || 'text'" x-model="apiForm.credentials[f.key]"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono">
                    </div>
                </template>
                <p x-show="apiError" x-text="apiError" class="text-sm text-red-600 mb-2"></p>
                <div class="flex gap-2 mt-2">
                    <button type="button" @click="apiType = null" class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-50 rounded-lg">Отказ</button>
                    <button type="submit" :disabled="saving"
                            class="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-50">
                        <span x-text="saving ? 'Свързвам…' : 'Свържи и провери'"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- ─────────── History modal ─────────── --}}
    <div x-show="showHistory" x-cloak @keydown.escape.window="showHistory = false"
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-2xl p-6" @click.outside="showHistory = false">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900">История на действията</h3>
                <button @click="showHistory = false" class="text-gray-400 hover:text-gray-600">✕</button>
            </div>
            <div class="max-h-96 overflow-y-auto text-sm">
                <template x-if="historyLogs.length === 0">
                    <p class="text-gray-400 py-6 text-center">Няма записани действия още.</p>
                </template>
                <template x-for="l in historyLogs" :key="l.id">
                    <div class="flex items-start gap-3 py-2 border-b border-gray-50">
                        <span x-text="l.status === 'ok' ? '✅' : '❌'"></span>
                        <div class="min-w-0 flex-1">
                            <span class="font-mono text-xs text-gray-700" x-text="l.tool"></span>
                            <span class="text-gray-500" x-text="' — ' + (l.result_summary || l.error || '')"></span>
                        </div>
                        <span class="text-xs text-gray-400 shrink-0" x-text="l.created_at + ' · ' + (l.duration_ms || 0) + 'ms'"></span>
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
            return ({ gmail: '📧', google_sheets: '📊', google_drive: '📁', slack: '💬', notion: '📝', airtable: '🗂', http_api: '🔗' })[type] || '🔌';
        },
        statusLabel(s) { return ({ active: 'Активен', expired: 'Изтекъл', error: 'Грешка', revoked: 'Отнет' })[s] || s; },
        badgeClass(s) {
            return s === 'active' ? 'bg-green-100 text-green-700'
                 : s === 'expired' ? 'bg-amber-100 text-amber-700'
                 : 'bg-red-100 text-red-700';
        },
    };
}
</script>
@endpush
@endsection
