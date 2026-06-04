@extends('layouts.app')

@section('title', 'Граф редактор — ' . $flow->name)

@push('head')
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/drawflow@0.0.59/dist/drawflow.min.css">
<style>
    body:has(#flow-builder-root) {
        overflow: hidden;
    }

    main:has(#flow-builder-root) {
        padding-top: 1rem;
        padding-bottom: 0;
    }

    #drawflow {
        background: #f8fafc;
        background-image: radial-gradient(#dbe3ef 1px, transparent 1px);
        background-size: 20px 20px;
    }

    #drawflow .drawflow {
        min-width: 100%;
        min-height: 100%;
    }

    .drawflow .drawflow-node,
    .drawflow .drawflow-node:hover,
    .drawflow .drawflow-node.selected {
        background: transparent !important;
        border: none !important;
        box-shadow: none !important;
        padding: 0;
        width: auto;
    }

    .drawflow .drawflow-node .df-node-card {
        position: relative;
        min-width: 260px;
        max-width: 300px;
        border: 1px solid #dbe3ef;
        border-left: 5px solid #64748b;
        border-radius: 16px;
        background: linear-gradient(180deg, #fff 0%, #f8fafc 100%);
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.10);
        overflow: hidden;
        transition: box-shadow 160ms ease, transform 160ms ease, border-color 160ms ease;
    }

    .drawflow .drawflow-node .df-node-card:hover {
        transform: translateY(-1px);
        box-shadow: 0 14px 32px rgba(15, 23, 42, 0.14);
        border-color: #cbd5e1;
    }

    .df-role-body { border-left-color: #6366f1 !important; }
    .df-role-hidden { border-left-color: #0ea5e9 !important; }
    .df-role-processing { border-left-color: #0ea5e9 !important; }
    .df-role-appendix { border-left-color: #a855f7 !important; }
    .df-role-quality { border-left-color: #f59e0b !important; }

    .df-node-header {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        padding: 14px 14px 12px;
    }

    .df-node-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 34px;
        height: 34px;
        border-radius: 12px;
        background: #eef2ff;
        color: #4338ca;
        font-size: 18px;
        flex: 0 0 auto;
    }

    .df-node-main { min-width: 0; flex: 1; }
    .df-node-name {
        color: #111827;
        font-size: 13px;
        font-weight: 700;
        line-height: 1.2;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .df-node-type {
        margin-top: 3px;
        color: #64748b;
        font-size: 11px;
        line-height: 1.2;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .df-node-edit {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 30px;
        height: 30px;
        padding: 0;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        background: #fff;
        color: #64748b;
        font-size: 14px;
        cursor: pointer;
        flex: 0 0 auto;
    }

    .df-node-edit:hover {
        border-color: #6366f1;
        color: #4f46e5;
        background: #eef2ff;
    }

    .df-node-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        border-top: 1px solid #edf2f7;
        padding: 8px 14px 10px;
        color: #94a3b8;
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        font-weight: 700;
    }

    .df-port-label {
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }

    .df-port-label::before,
    .df-port-label::after {
        content: '';
        display: inline-block;
        width: 7px;
        height: 7px;
        border-radius: 999px;
    }

    .df-port-label-in::before { background: #10b981; }
    .df-port-label-out::after { background: #3b82f6; }

    .drawflow .drawflow-node .input,
    .drawflow .drawflow-node .output {
        width: 18px;
        height: 18px;
        border: 3px solid #fff;
        box-shadow: 0 0 0 1px rgba(15, 23, 42, 0.12), 0 2px 6px rgba(15, 23, 42, 0.18);
    }

    .drawflow .drawflow-node .input {
        left: -10px;
        background: #10b981;
    }

    .drawflow .drawflow-node .output {
        right: 4px;
        background: #3b82f6;
    }

    .drawflow .drawflow-node .input:hover,
    .drawflow .drawflow-node .output:hover {
        transform: scale(1.08);
        background: #4f46e5;
    }

    /* Pulsing amber glow for running nodes — much more prominent than static. */
    @keyframes df-running-pulse {
        0%, 100% { box-shadow: 0 0 0 2px #f59e0b, 0 14px 32px rgba(245,158,11,.20); }
        50%       { box-shadow: 0 0 0 4px #f59e0b, 0 14px 32px rgba(245,158,11,.40); }
    }
    .df-status-running .df-node-card { animation: df-running-pulse 1.2s ease-in-out infinite; }
    .df-status-completed .df-node-card { box-shadow: 0 0 0 2px #22c55e, 0 14px 32px rgba(34, 197, 94, 0.18); }
    .df-status-failed .df-node-card { box-shadow: 0 0 0 2px #ef4444, 0 14px 32px rgba(239, 68, 68, 0.18); }
    .df-status-skipped .df-node-card { opacity: 0.5; }

    /* ── Boundary node (Старт / Край) status colours ──
       Must be at least as specific as the base rule
       `.drawflow .drawflow-node .df-boundary-card` (3 selectors) to win. */
    .drawflow .drawflow-node.df-status-running .df-boundary-card {
        box-shadow: 0 0 0 2.5px #22c55e, 0 8px 18px rgba(34,197,94,.18) !important;
        border-color: #86efac !important;
    }
    .drawflow .drawflow-node.df-status-completed .df-boundary-card {
        box-shadow: 0 0 0 2.5px #22c55e, 0 8px 18px rgba(34,197,94,.18) !important;
        border-color: #86efac !important;
        background: #f0fdf4 !important;
    }
    .drawflow .drawflow-node.df-status-failed .df-boundary-card {
        box-shadow: 0 0 0 2.5px #ef4444, 0 8px 18px rgba(239,68,68,.18) !important;
        border-color: #fca5a5 !important;
    }

    /* Disable port-dot interaction in run/view so users can't accidentally
       draw new connections, while still allowing node dragging. */
    .df-run-view .drawflow-node .output { pointer-events: none !important; }
    .df-run-view .drawflow-node .input  { pointer-events: none !important; }
    .drawflow .connection .main-path { stroke: #94a3b8; stroke-width: 2.5px; }

    /* ── Per-node run UI (progress + result/log buttons) ──
       The block is ALWAYS in the DOM but collapsed in edit mode so the card has
       no dead space underneath the ports. When the run UI is revealed, the card
       grows taller, so we recompute that node's connection endpoints in JS to
       keep the port dots and wires aligned. */
    .df-run-extra {
        border-top: 1px solid #edf2f7;
        padding: 8px 12px 10px;
    }
    .df-run-hidden .df-run-extra { display: none; }
    /* Shimmer animation for pending nodes — clearly "waiting", not stuck. */
    @keyframes df-pending-shimmer {
        0%   { background-position: -200px 0; }
        100% { background-position: calc(200px + 100%) 0; }
    }
    .df-run-progress {
        height: 8px;
        border-radius: 999px;
        background: #eef2f7;
        overflow: hidden;
        margin-bottom: 6px;
    }
    .df-run-progress > span {
        display: block;
        height: 100%;
        width: 0%;
        border-radius: 999px;
        background: #cbd5e1;
        transition: width 500ms ease;
    }
    /* Pending: shimmer on the track itself, span hidden so it doesn't double up */
    .df-status-pending .df-run-progress {
        background: linear-gradient(90deg, #eef2f7 25%, #dde5f0 50%, #eef2f7 75%);
        background-size: 400px 100%;
        animation: df-pending-shimmer 1.8s ease infinite;
    }
    .df-status-pending .df-run-progress > span { width: 0% !important; background: transparent !important; }
    .df-status-running .df-run-progress > span { background: #f59e0b; }
    .df-status-completed .df-run-progress > span { background: #22c55e; width: 100% !important; }
    .df-status-failed .df-run-progress > span { background: #ef4444; width: 100% !important; }
    /* Pending nodes look dimmer so the running one stands out. */
    .df-status-pending .df-node-card { opacity: 0.70; }

    /* Small status label below the progress bar */
    .df-run-status-label {
        font-size: 10px;
        font-weight: 600;
        margin-top: 2px;
        margin-bottom: 4px;
        color: #94a3b8;
    }
    .df-status-running .df-run-status-label { color: #92400e; }
    .df-status-completed .df-run-status-label { color: #166534; }
    .df-status-failed .df-run-status-label { color: #991b1b; }

    .df-run-actions { display: flex; gap: 6px; }
    .df-run-result, .df-run-log {
        font-size: 11px;
        font-weight: 700;
        border-radius: 8px;
        padding: 6px 8px;
        cursor: pointer;
        border: 1px solid #e2e8f0;
        background: #fff;
        color: #475569;
    }
    .df-run-result { flex: 1; background: #eef2ff; border-color: #c7d2fe; color: #4338ca; }
    .df-run-result:disabled { opacity: 0.45; cursor: not-allowed; }
    .df-run-result:not(:disabled):hover { background: #e0e7ff; }
    .df-run-log:hover { background: #f8fafc; }

    .df-final-btn {
        margin-left: 8px;
        border: 1px solid #bbf7d0;
        background: #dcfce7;
        color: #15803d;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 800;
        padding: 4px 10px;
        cursor: pointer;
    }
    .df-final-btn:hover { background: #bbf7d0; }

    .drawflow .drawflow-node.selected .df-node-card {
        box-shadow: 0 0 0 2px #6366f1, 0 16px 32px rgba(15, 23, 42, 0.14) !important;
    }

    .drawflow .drawflow-node .df-boundary-card {
        min-width: 170px;
        border: 1px solid #cbd5e1;
        border-radius: 999px;
        background: #fff;
        box-shadow: 0 8px 18px rgba(15, 23, 42, 0.08);
        padding: 12px 16px;
        display: flex;
        align-items: center;
        gap: 10px;
        color: #334155;
        font-size: 13px;
        font-weight: 800;
    }

    .drawflow .drawflow-node.selected .df-boundary-card {
        box-shadow: 0 0 0 2px #94a3b8, 0 8px 18px rgba(15, 23, 42, 0.08);
    }

    .df-boundary-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        border-radius: 999px;
        background: #f1f5f9;
        flex: 0 0 auto;
    }

    /* Markdown rendered output */
    .md-output h1,.md-output h2,.md-output h3{font-weight:600;color:#111827;margin-top:.6em;margin-bottom:.2em}
    .md-output h1{font-size:1.15em}.md-output h2{font-size:1.05em}.md-output h3{font-size:1em}
    .md-output p{margin-bottom:.5em}.md-output strong{font-weight:600}.md-output em{font-style:italic}
    .md-output ul,.md-output ol{padding-left:1.4em;margin-bottom:.5em}
    .md-output ul{list-style-type:disc}.md-output ol{list-style-type:decimal}
    .md-output li{margin-bottom:.15em}
    .md-output a{color:#4f46e5;text-decoration:underline}
    .md-output blockquote{border-left:3px solid #e5e7eb;padding-left:.75em;color:#6b7280;margin:.5em 0}
    .md-output hr{border:none;border-top:1px solid #e5e7eb;margin:.75em 0}
    .md-output code{font-family:monospace;background:#f3f4f6;padding:.1em .3em;border-radius:3px;font-size:.9em}
    .md-output pre{background:#f3f4f6;padding:.6em .8em;border-radius:6px;overflow-x:auto;margin:.5em 0}
    .md-output pre code{background:none;padding:0}
    .md-output table{width:max-content;min-width:100%;border-collapse:collapse;margin:.75em 0;font-size:.92em}
    .md-output th,.md-output td{border:1px solid #e5e7eb;padding:.45rem .6rem;text-align:left;vertical-align:top}
    .md-output th{background:#f9fafb;font-weight:600;color:#374151}
</style>
@endpush

@section('content')
<div id="flow-builder-root"
     x-data="flowBuilder(@js($config))"
     @keydown.escape.window="propertiesOpen ? closeNodeModal() : (showPicker = false)"
     class="relative left-1/2 -translate-x-1/2 w-[calc(100vw-3rem)] h-[calc(100vh-6rem)] min-h-0 flex flex-col overflow-hidden">
    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-4 shrink-0">
        <div>
            <a href="{{ route('flows.show', $flow) }}" class="text-indigo-600 hover:underline text-sm">← Обратно към flow</a>
            <h1 class="text-2xl font-bold text-gray-900 mt-1">{{ $flow->name }} — граф</h1>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            {{-- Edit-mode controls --}}
            <template x-if="mode === 'edit'">
                <div class="flex flex-wrap items-center gap-2">
                    <span x-show="saving" class="text-sm text-gray-500" x-cloak>Запазване…</span>
                    <span x-show="saveError" class="text-sm text-red-600" x-cloak x-text="saveError"></span>
                    <span x-show="savedAt" class="text-sm text-green-600" x-cloak x-text="'Запазено ' + savedAt"></span>
                    <button @click="startGeneration(true)" type="button" class="px-4 py-2.5 text-sm rounded-xl bg-violet-600 text-white hover:bg-violet-700 font-bold shadow-sm">
                        ✨ Генериране на агенти
                    </button>
                    <button @click="openGenLog()" type="button" class="px-3 py-2 text-sm rounded-lg border border-gray-300 bg-white text-gray-700 hover:bg-gray-50" title="Пълен лог на генерирането на агенти">
                        📋 Лог на генерирането
                    </button>
                    <button @click="openAgentPicker()" type="button" class="px-5 py-3 text-sm rounded-xl bg-indigo-600 text-white hover:bg-indigo-700 font-bold shadow-sm">
                        ＋ Добави агент
                    </button>
                    <button @click="validate()" type="button" class="px-3 py-2 text-sm rounded-lg border border-gray-300 bg-white text-gray-700 hover:bg-gray-50">Валидирай</button>
                    <button @click="save()" type="button" class="px-3 py-2 text-sm rounded-lg bg-gray-900 text-white hover:bg-gray-800">Запис</button>
                    <form :action="runUrl" method="POST" @submit="save()">
                        @csrf
                        <button type="submit" class="px-3 py-2 text-sm rounded-lg bg-green-600 text-white hover:bg-green-700">▶ Стартирай</button>
                    </form>
                </div>
            </template>

            {{-- Live-run banners (status-aware) --}}
            <template x-if="mode === 'run' && (!runStatus || runStatus === 'running' || runStatus === 'pending')">
                <div class="flex items-center gap-2 text-sm">
                    <span class="inline-block w-4 h-4 border-2 border-amber-500 border-t-transparent rounded-full animate-spin"></span>
                    <span class="text-amber-700 font-semibold">Изпълнява се… редакторът е заключен</span>
                    <span x-show="_lastProgress && _lastProgress.agent_name"
                          class="text-xs text-amber-600 font-medium"
                          x-text="_lastProgress && _lastProgress.agent_name ? '→ ' + _lastProgress.agent_name : ''"></span>
                </div>
            </template>
            <template x-if="mode === 'run' && runStatus === 'completed'">
                <div class="flex items-center gap-3 text-sm">
                    <span class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-green-50 border border-green-200 text-green-800 font-semibold">
                        <span class="text-green-600">✓</span> Завършен
                    </span>
                    <button type="button" @click="openFinal()" class="px-3 py-2 rounded-lg bg-green-600 text-white hover:bg-green-700 font-semibold">🏁 Виж резултата</button>
                </div>
            </template>
            <template x-if="mode === 'run' && runStatus === 'failed'">
                <div class="flex items-center gap-2 text-sm">
                    <span class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-red-50 border border-red-200 text-red-800 font-semibold">
                        <span class="text-red-600">✗</span> Неуспешен
                    </span>
                </div>
            </template>

            {{-- Historical view banner --}}
            <template x-if="mode === 'view'">
                <div class="flex items-center gap-3 text-sm">
                    <span class="px-2.5 py-1 rounded-lg bg-gray-100 text-gray-600 font-semibold">🕓 Преглед на изпълнение (read-only)</span>
                    <button type="button" @click="openFinal()" class="px-3 py-2 rounded-lg border border-gray-300 bg-white text-gray-700 hover:bg-gray-50">Финален резултат</button>
                    <a href="{{ route('flows.builder', $flow) }}" class="px-3 py-2 rounded-lg bg-gray-900 text-white hover:bg-gray-800">✎ Редактирай</a>
                </div>
            </template>
        </div>
    </div>

    {{-- Stall warning: worker not running --}}
    <div x-show="stalledRun" x-cloak
         class="mb-3 shrink-0 bg-amber-50 border border-amber-200 text-amber-800 text-sm px-4 py-2.5 rounded-lg flex items-center gap-2">
        <span class="text-amber-500 text-base">⚠️</span>
        <span><strong>Изпълнението виси.</strong> Провери дали
            <code class="bg-amber-100 px-1 rounded font-mono text-xs">composer dev</code> или
            <code class="bg-amber-100 px-1 rounded font-mono text-xs">php artisan queue:work --queue=flows</code>
            е стартиран в терминала.</span>
    </div>

    <div x-show="validation" x-cloak class="mb-3 shrink-0">
        <template x-if="validation && validation.ok">
            <div class="bg-green-50 border border-green-200 text-green-800 text-sm px-4 py-2 rounded-lg">
                ✓ Графът е валиден (<span x-text="validation.wave_count"></span> вълни).
            </div>
        </template>
        <template x-if="validation && !validation.ok">
            <div class="bg-red-50 border border-red-200 text-red-800 text-sm px-4 py-2 rounded-lg">
                <div class="font-medium mb-1">Невалиден граф:</div>
                <ul class="list-disc list-inside">
                    <template x-for="err in validation.errors" :key="err"><li x-text="err"></li></template>
                </ul>
            </div>
        </template>
    </div>

    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden relative shadow-sm flex-1 min-h-0">
        <div id="drawflow" class="w-full h-full"></div>
        <div class="absolute left-4 bottom-4 rounded-xl bg-white/90 backdrop-blur border border-gray-200 px-3 py-2 text-xs text-gray-500 shadow-sm">
            Свържи син изход към зелен вход. “Контекст” означава междинен резултат, който се подава към следващи агенти, но не влиза директно във финалния output.
        </div>
    </div>

    {{-- Generation Modal (non-dismissable) --}}
    <div x-show="gen.active" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md p-8 text-center">
            <div class="mx-auto mb-5 w-14 h-14 rounded-2xl bg-violet-100 flex items-center justify-center text-3xl">✨</div>
            <h3 class="text-xl font-bold text-gray-900 mb-1">Генерирам агентите</h3>
            <p class="text-sm text-gray-500 mb-5">AI проектира pipeline-а за този flow. Това отнема около минута — не затваряй страницата.</p>

            <div class="w-full h-2.5 rounded-full bg-gray-100 overflow-hidden mb-4">
                <div class="h-full bg-violet-600 transition-all duration-700 ease-out"
                     :style="`width: ${gen.progress}%`"></div>
            </div>

            <p class="text-sm font-semibold text-violet-700 min-h-[1.25rem]" x-text="gen.message"></p>

            <div x-show="gen.error" x-cloak class="mt-5 bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-lg text-left">
                <p x-text="gen.error"></p>
                <div class="mt-3 flex gap-2 justify-end">
                    <button type="button" @click="gen.active = false" class="px-3 py-1.5 rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50 text-xs">Затвори</button>
                    <button type="button" @click="startGeneration(gen.autoSave)" class="px-3 py-1.5 rounded-lg bg-violet-600 text-white hover:bg-violet-700 text-xs">Опитай пак</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Node Result Modal --}}
    <div x-show="resultModal.open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4"
         @keydown.escape.window="resultModal.open = false">
        <div class="absolute inset-0 bg-black/40" @click="resultModal.open = false"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[80vh] flex flex-col" @click.stop>
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <h3 class="text-lg font-bold text-gray-900" x-text="'Резултат — ' + resultModal.title"></h3>
                <button @click="resultModal.open = false" type="button" class="text-gray-400 hover:text-gray-600 text-xl leading-none">✕</button>
            </div>
            <div class="p-6 overflow-y-auto">
                <div class="md-output text-sm text-gray-800 leading-relaxed" x-html="renderMd(resultModal.body)"></div>
            </div>
        </div>
    </div>

    {{-- Node Log Modal --}}
    <div x-show="logModal.open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4"
         @keydown.escape.window="logModal.open = false">
        <div class="absolute inset-0 bg-black/40" @click="logModal.open = false"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[80vh] flex flex-col" @click.stop>
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <h3 class="text-lg font-bold text-gray-900" x-text="'Лог — ' + logModal.title"></h3>
                <button @click="logModal.open = false" type="button" class="text-gray-400 hover:text-gray-600 text-xl leading-none">✕</button>
            </div>
            <div class="p-6 overflow-y-auto space-y-3">
                <div class="grid grid-cols-2 gap-3 text-xs">
                    <div class="bg-gray-50 rounded-lg px-3 py-2"><span class="text-gray-400">Статус:</span> <span class="font-semibold" x-text="logModal.meta.status"></span></div>
                    <div class="bg-gray-50 rounded-lg px-3 py-2"><span class="text-gray-400">Модел:</span> <span class="font-semibold" x-text="logModal.meta.model || '—'"></span></div>
                    <div class="bg-gray-50 rounded-lg px-3 py-2"><span class="text-gray-400">Времетраене:</span> <span class="font-semibold" x-text="logModal.meta.duration"></span></div>
                    <div class="bg-gray-50 rounded-lg px-3 py-2"><span class="text-gray-400">Токени:</span> <span class="font-semibold" x-text="logModal.meta.tokens || '—'"></span></div>
                </div>
                <div x-show="logModal.error" x-cloak>
                    <p class="text-xs font-semibold text-red-600 mb-1">Грешка</p>
                    <pre class="whitespace-pre-wrap break-words text-xs text-red-700 bg-red-50 rounded-lg p-3" x-text="logModal.error"></pre>
                </div>
                <div x-show="logModal.params" x-cloak>
                    <p class="text-xs font-semibold text-gray-500 mb-1">Параметри на модела</p>
                    <div class="grid grid-cols-3 gap-2 text-xs">
                        <div class="bg-gray-50 rounded-lg px-3 py-2"><span class="text-gray-400">Temperature:</span> <span class="font-semibold" x-text="logModal.params?.temperature"></span></div>
                        <div class="bg-gray-50 rounded-lg px-3 py-2"><span class="text-gray-400">Top-p:</span> <span class="font-semibold" x-text="logModal.params?.top_p"></span></div>
                        <div class="bg-gray-50 rounded-lg px-3 py-2"><span class="text-gray-400">Top-k:</span> <span class="font-semibold" x-text="logModal.params?.top_k"></span></div>
                        <div class="bg-gray-50 rounded-lg px-3 py-2"><span class="text-gray-400">Repeat penalty:</span> <span class="font-semibold" x-text="logModal.params?.repeat_penalty"></span></div>
                        <div class="bg-gray-50 rounded-lg px-3 py-2"><span class="text-gray-400">Num predict:</span> <span class="font-semibold" x-text="logModal.params?.num_predict"></span></div>
                        <div class="bg-gray-50 rounded-lg px-3 py-2"><span class="text-gray-400">Seed:</span> <span class="font-semibold" x-text="logModal.params?.seed"></span></div>
                        <div class="bg-gray-50 rounded-lg px-3 py-2"><span class="text-gray-400">Език:</span> <span class="font-semibold" x-text="logModal.params?.language"></span></div>
                        <div class="bg-gray-50 rounded-lg px-3 py-2"><span class="text-gray-400">Тон:</span> <span class="font-semibold" x-text="logModal.params?.tone"></span></div>
                        <div class="bg-gray-50 rounded-lg px-3 py-2"><span class="text-gray-400">Стил:</span> <span class="font-semibold" x-text="logModal.params?.style"></span></div>
                        <div class="bg-gray-50 rounded-lg px-3 py-2"><span class="text-gray-400">Format:</span> <span class="font-semibold" x-text="logModal.params?.format"></span></div>
                    </div>
                </div>
                <div x-show="logModal.steps" x-cloak>
                    <p class="text-xs font-semibold text-gray-500 mb-1">Активност</p>
                    <pre class="whitespace-pre-wrap break-words text-xs text-gray-700 bg-gray-900/5 rounded-lg p-3 max-h-64 overflow-y-auto" x-text="logModal.steps"></pre>
                </div>
                <div x-show="logModal.systemPrompt" x-cloak>
                    <p class="text-xs font-semibold text-gray-500 mb-1">Системен промпт</p>
                    <pre class="whitespace-pre-wrap break-words text-xs text-gray-600 bg-gray-50 rounded-lg p-3 max-h-48 overflow-y-auto" x-text="logModal.systemPrompt"></pre>
                </div>
                <div>
                    <p class="text-xs font-semibold text-gray-500 mb-1">Потребителски промпт (вход)</p>
                    <pre class="whitespace-pre-wrap break-words text-xs text-gray-600 bg-gray-50 rounded-lg p-3 max-h-48 overflow-y-auto" x-text="logModal.input || '—'"></pre>
                </div>
            </div>
        </div>
    </div>

    {{-- Final Output Modal --}}
    <div x-show="finalModal.open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4"
         @keydown.escape.window="finalModal.open = false">
        <div class="absolute inset-0 bg-black/50" @click="finalModal.open = false"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-3xl max-h-[85vh] flex flex-col" @click.stop>
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <h3 class="text-lg font-bold text-gray-900">🏁 Финален резултат</h3>
                <button @click="finalModal.open = false" type="button" class="text-gray-400 hover:text-gray-600 text-xl leading-none">✕</button>
            </div>
            <div class="p-6 overflow-y-auto">
                <p x-show="!finalModal.body" x-cloak class="text-sm text-gray-400">Все още няма финален резултат.</p>
                <div x-show="finalModal.body" class="md-output text-sm text-gray-800 leading-relaxed" x-html="renderMd(finalModal.body)"></div>
            </div>
        </div>
    </div>

    {{-- Agent Generation Log Modal --}}
    <div x-show="genLogModal.open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4"
         @keydown.escape.window="genLogModal.open = false">
        <div class="absolute inset-0 bg-black/40" @click="genLogModal.open = false"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-4xl max-h-[85vh] flex flex-col" @click.stop>
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <h3 class="text-lg font-bold text-gray-900">📋 Лог на генерирането на агенти</h3>
                <button @click="genLogModal.open = false" type="button" class="text-gray-400 hover:text-gray-600 text-xl leading-none">✕</button>
            </div>
            <div class="p-6 overflow-y-auto space-y-4">
                <p x-show="genLogModal.loading" x-cloak class="text-sm text-gray-400">Зареждане…</p>
                <p x-show="genLogModal.error" x-cloak class="text-sm text-red-600" x-text="genLogModal.error"></p>
                <p x-show="!genLogModal.loading && !genLogModal.error && genLogModal.logs.length === 0" x-cloak class="text-sm text-gray-400">Все още няма записи за генериране.</p>

                <template x-for="log in genLogModal.logs" :key="log.id">
                    <div class="border border-gray-200 rounded-xl overflow-hidden">
                        <div class="px-4 py-3 bg-gray-50 flex items-center justify-between cursor-pointer" @click="log._expanded = !log._expanded">
                            <div class="flex items-center gap-3 text-sm">
                                <span class="font-semibold text-gray-900" x-text="log.created_at"></span>
                                <span class="text-xs px-2 py-0.5 rounded-full"
                                      :class="log.status === 'completed' ? 'bg-green-100 text-green-700' : (log.status === 'failed' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700')"
                                      x-text="log.status"></span>
                                <span class="text-xs text-gray-500" x-text="(log.provider || '—') + ' · ' + (log.model || '—')"></span>
                                <span class="text-xs text-gray-400" x-text="(log.parsed_count ?? '—') + ' агента'"></span>
                            </div>
                            <span class="text-gray-400 text-xs" x-text="log._expanded ? '▲' : '▼'"></span>
                        </div>
                        <div x-show="log._expanded" x-cloak class="p-4 space-y-3">
                            <div class="grid grid-cols-3 gap-2 text-xs">
                                <div class="bg-gray-50 rounded-lg px-3 py-2"><span class="text-gray-400">Провайдър:</span> <span class="font-semibold" x-text="log.provider || '—'"></span></div>
                                <div class="bg-gray-50 rounded-lg px-3 py-2"><span class="text-gray-400">Модел:</span> <span class="font-semibold" x-text="log.model || '—'"></span></div>
                                <div class="bg-gray-50 rounded-lg px-3 py-2"><span class="text-gray-400">Времетраене:</span> <span class="font-semibold" x-text="log.duration_ms ? (Math.round(log.duration_ms/100)/10 + ' сек') : '—'"></span></div>
                            </div>
                            <div x-show="log.error" x-cloak>
                                <p class="text-xs font-semibold text-red-600 mb-1">Грешка</p>
                                <pre class="whitespace-pre-wrap break-words text-xs text-red-700 bg-red-50 rounded-lg p-3" x-text="log.error"></pre>
                            </div>
                            <div>
                                <p class="text-xs font-semibold text-gray-500 mb-1">Опции (параметри към модела)</p>
                                <pre class="whitespace-pre-wrap break-words text-xs text-gray-600 bg-gray-50 rounded-lg p-3" x-text="JSON.stringify(log.options, null, 2)"></pre>
                            </div>
                            <div>
                                <p class="text-xs font-semibold text-gray-500 mb-1">Системен промпт</p>
                                <pre class="whitespace-pre-wrap break-words text-xs text-gray-600 bg-gray-50 rounded-lg p-3 max-h-64 overflow-y-auto" x-text="log.system_prompt || '—'"></pre>
                            </div>
                            <div>
                                <p class="text-xs font-semibold text-gray-500 mb-1">Потребителски промпт</p>
                                <pre class="whitespace-pre-wrap break-words text-xs text-gray-600 bg-gray-50 rounded-lg p-3 max-h-64 overflow-y-auto" x-text="log.user_message || '—'"></pre>
                            </div>
                            <div>
                                <p class="text-xs font-semibold text-gray-500 mb-1">Пълен суров отговор</p>
                                <pre class="whitespace-pre-wrap break-words text-xs text-gray-600 bg-gray-900/5 rounded-lg p-3 max-h-80 overflow-y-auto" x-text="log.raw_response || '—'"></pre>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    {{-- Agent Picker Modal --}}
    <div x-show="showPicker" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4"
         @keydown.escape.window="showPicker = false">
        <div class="absolute inset-0 bg-black/40" @click="showPicker = false"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-[900px] overflow-hidden" @click.stop>
            <div class="px-6 pt-5 pb-0 flex items-center justify-between">
                <h3 class="text-lg font-bold text-gray-900">Добави агент</h3>
                <button @click="showPicker = false" type="button" class="text-gray-400 hover:text-gray-600 text-xl leading-none">✕</button>
            </div>

            <div class="flex px-6 pt-3 pb-0 border-b border-gray-200 gap-1">
                <template x-for="tab in pickerTabs" :key="tab.id">
                    <button type="button"
                            @click="activePickerTab = tab.id"
                            :class="activePickerTab === tab.id
                                ? 'border-indigo-600 text-indigo-700 font-semibold bg-indigo-50'
                                : 'border-transparent text-gray-500 hover:text-gray-700'"
                            class="px-4 py-2 text-sm border-b-2 -mb-px rounded-t-lg transition whitespace-nowrap"
                            x-text="tab.label">
                    </button>
                </template>
            </div>

            <div class="p-6 max-h-[560px] overflow-y-auto">
                <div class="mb-4">
                    <input type="text" x-model="pickerSearch"
                           placeholder="Търси по име или тип..."
                           class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>

                <div x-show="pickerLoading" class="text-center py-8 text-gray-400 text-sm">
                    <span class="inline-block w-5 h-5 border-2 border-indigo-400 border-t-transparent rounded-full animate-spin mr-2"></span>
                    Зарежда шаблони...
                </div>

                <div x-show="!pickerLoading && activePickerTab === 'all'">
                    <div class="mb-4">
                        <div @click="selectTemplate(null)"
                             class="flex items-center gap-4 p-4 border-2 border-dashed border-gray-300 rounded-xl cursor-pointer hover:border-indigo-500 hover:bg-indigo-50 transition">
                            <span class="text-3xl">＋</span>
                            <div class="flex-1">
                                <div class="font-semibold text-sm text-gray-900">Нов празен агент</div>
                                <div class="text-xs text-gray-500">Започни от нулата — всички полета са готови за попълване</div>
                            </div>
                            <span class="text-indigo-600 text-sm font-semibold">Избери →</span>
                        </div>
                    </div>

                    <template x-if="filteredCompanyTemplates.length > 0">
                        <div class="mb-5">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-2">🏢 Моите агенти</p>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                                <template x-for="tpl in filteredCompanyTemplates" :key="tpl.id">
                                    <div @click="selectTemplate(tpl)"
                                         class="border border-gray-200 rounded-xl p-3 cursor-pointer hover:border-indigo-500 hover:bg-indigo-50 transition">
                                        <span class="block text-2xl mb-1" x-text="tpl.icon || '🤖'"></span>
                                        <div class="text-xs font-semibold text-gray-900 mb-1 leading-tight" x-text="tpl.name"></div>
                                        <div class="text-[12px] text-gray-500 leading-tight mb-1.5 line-clamp-2" x-text="tpl.description || tpl.role || ''"></div>
                                        <span class="inline-block text-[10px] font-mono px-1.5 py-0.5 rounded bg-green-100 text-green-700" x-text="tpl.type"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>

                    <template x-if="filteredSystemTemplates.length > 0">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-2">⚙ Системни агенти</p>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                                <template x-for="tpl in filteredSystemTemplates" :key="tpl.id">
                                    <div @click="selectTemplate(tpl)"
                                         class="border border-gray-200 rounded-xl p-3 cursor-pointer hover:border-indigo-500 hover:bg-indigo-50 transition">
                                        <span class="block text-2xl mb-1" x-text="tpl.icon || '🤖'"></span>
                                        <div class="text-xs font-semibold text-gray-900 mb-1 leading-tight" x-text="tpl.name"></div>
                                        <div class="text-[12px] text-gray-500 leading-tight mb-1.5 line-clamp-2" x-text="tpl.description || tpl.role || ''"></div>
                                        <span class="inline-block text-[10px] font-mono px-1.5 py-0.5 rounded bg-violet-100 text-violet-700" x-text="tpl.type"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>

                    <div x-show="filteredCompanyTemplates.length === 0 && filteredSystemTemplates.length === 0 && pickerSearch"
                         class="text-center py-8 text-gray-400 text-sm">
                        Няма резултати за "<span x-text="pickerSearch"></span>"
                    </div>
                </div>

                <div x-show="!pickerLoading && activePickerTab === 'mine'">
                    <div x-show="filteredCompanyTemplates.length === 0" class="text-center py-8 text-gray-400 text-sm">
                        <p class="text-3xl mb-2">🏢</p>
                        Нямате запазени агент шаблони.
                    </div>
                    <div x-show="filteredCompanyTemplates.length > 0" class="grid grid-cols-2 md:grid-cols-4 gap-2">
                        <template x-for="tpl in filteredCompanyTemplates" :key="tpl.id">
                            <div @click="selectTemplate(tpl)"
                                 class="border border-gray-200 rounded-xl p-3 cursor-pointer hover:border-indigo-500 hover:bg-indigo-50 transition">
                                <span class="block text-2xl mb-1" x-text="tpl.icon || '🤖'"></span>
                                <div class="text-xs font-semibold text-gray-900 mb-1 leading-tight" x-text="tpl.name"></div>
                                <div class="text-[12px] text-gray-500 leading-tight mb-1.5 line-clamp-2" x-text="tpl.description || tpl.role || ''"></div>
                                <span class="inline-block text-[10px] font-mono px-1.5 py-0.5 rounded bg-green-100 text-green-700" x-text="tpl.type"></span>
                            </div>
                        </template>
                    </div>
                </div>

                <div x-show="!pickerLoading && activePickerTab === 'system'">
                    <div x-show="filteredSystemTemplates.length === 0" class="text-center py-8 text-gray-400 text-sm">
                        Няма системни агент шаблони.
                    </div>
                    <div x-show="filteredSystemTemplates.length > 0" class="grid grid-cols-2 md:grid-cols-4 gap-2">
                        <template x-for="tpl in filteredSystemTemplates" :key="tpl.id">
                            <div @click="selectTemplate(tpl)"
                                 class="border border-gray-200 rounded-xl p-3 cursor-pointer hover:border-indigo-500 hover:bg-indigo-50 transition">
                                <span class="block text-2xl mb-1" x-text="tpl.icon || '🤖'"></span>
                                <div class="text-xs font-semibold text-gray-900 mb-1 leading-tight" x-text="tpl.name"></div>
                                <div class="text-[12px] text-gray-500 leading-tight mb-1.5 line-clamp-2" x-text="tpl.description || tpl.role || ''"></div>
                                <span class="inline-block text-[10px] font-mono px-1.5 py-0.5 rounded bg-violet-100 text-violet-700" x-text="tpl.type"></span>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Node Properties Modal --}}
    <div x-show="propertiesOpen" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4"
         @keydown.escape.window="closeNodeModal()">
        <div class="absolute inset-0 bg-black/40" @click="closeNodeModal()"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-5xl max-h-[92vh] overflow-hidden" @click.stop>
            <div class="px-6 py-5 border-b border-gray-100 flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 mb-0.5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600">Свойства на агент-бокс</p>
                        <span x-show="modalReadOnly" class="text-xs px-2 py-0.5 rounded-full bg-amber-50 border border-amber-200 text-amber-700 font-semibold">👁 Само за четене</span>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 truncate" x-text="selected?.name || 'Агент'"></h3>
                    <p class="text-xs text-gray-400 mt-1" x-text="selected ? typeLabel(selected.type) : ''"></p>
                </div>
                <button @click="closeNodeModal()" type="button" class="text-gray-400 hover:text-gray-600 text-xl leading-none">✕</button>
            </div>

            <template x-if="selected">
                <div>
                    <div class="flex px-6 border-b border-gray-200 gap-1">
                        <template x-for="tab in propsTabs" :key="tab.id">
                            <button type="button"
                                    @click="propsTab = tab.id"
                                    :class="propsTab === tab.id
                                        ? 'border-indigo-600 text-indigo-700 font-semibold bg-indigo-50'
                                        : 'border-transparent text-gray-500 hover:text-gray-700'"
                                    class="px-4 py-2 text-sm border-b-2 -mb-px rounded-t-lg transition whitespace-nowrap"
                                    x-text="tab.label">
                            </button>
                        </template>
                    </div>

                    <div class="p-6 overflow-y-auto max-h-[calc(92vh-185px)]">
                        <div x-show="propsTab === 'basic'" class="space-y-5">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Име</label>
                                    <input type="text" x-model="selected.name" :disabled="modalReadOnly"
                                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:bg-gray-50 disabled:text-gray-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Тип</label>
                                    <select x-model="selected.type" @change="!modalReadOnly && onSelectedTypeChanged()" :disabled="modalReadOnly"
                                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:bg-gray-50 disabled:text-gray-500">
                                        <template x-for="type in agentTypes" :key="type.type">
                                            <option :value="type.type" x-text="type.label"></option>
                                        </template>
                                    </select>
                                </div>
                            </div>

                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label class="block text-sm font-medium text-gray-700">Роля / Описание</label>
                                    <button x-show="!modalReadOnly" type="button" @click="generateField('role')"
                                            :disabled="generating.role || !selected.name"
                                            class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 disabled:bg-indigo-300 disabled:cursor-not-allowed text-white text-xs font-semibold px-3 py-1 rounded-lg transition">
                                        <span x-text="generating.role ? '⏳' : '✨'"></span>
                                        <span x-text="generating.role ? 'Генерира...' : 'Генерирай с AI'"></span>
                                    </button>
                                </div>
                                <textarea x-model="selected.role" rows="3" :disabled="modalReadOnly"
                                          class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:bg-gray-50 disabled:text-gray-500"></textarea>
                            </div>

                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label class="block text-sm font-medium text-gray-700">System промпт</label>
                                    <button x-show="!modalReadOnly" type="button" @click="generateField('system_prompt')"
                                            :disabled="generating.system_prompt || !selected.name"
                                            class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 disabled:bg-indigo-300 disabled:cursor-not-allowed text-white text-xs font-semibold px-3 py-1 rounded-lg transition">
                                        <span x-text="generating.system_prompt ? '⏳' : '✨'"></span>
                                        <span x-text="generating.system_prompt ? 'Генерира...' : 'Генерирай с AI'"></span>
                                    </button>
                                </div>
                                <p class="text-xs text-gray-400 mb-1">Описва ролята и поведението на агента. Инжектира се автоматично при всяко изпълнение.</p>
                                <textarea x-model="selected.system_prompt" rows="4" :disabled="modalReadOnly"
                                          class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:bg-gray-50 disabled:text-gray-500"
                                          placeholder="Ти си специализиран агент за..."></textarea>
                            </div>

                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label class="block text-sm font-medium text-gray-700">Промпт шаблон</label>
                                    <button x-show="!modalReadOnly" type="button" @click="generateField('prompt_template')"
                                            :disabled="generating.prompt_template || !selected.name"
                                            class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 disabled:bg-indigo-300 disabled:cursor-not-allowed text-white text-xs font-semibold px-3 py-1 rounded-lg transition">
                                        <span x-text="generating.prompt_template ? '⏳' : '✨'"></span>
                                        <span x-text="generating.prompt_template ? 'Генерира...' : 'Генерирай с AI'"></span>
                                    </button>
                                </div>
                                <textarea x-model="selected.prompt_template" rows="7" :disabled="modalReadOnly"
                                          class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:bg-gray-50 disabled:text-gray-500"
                                          placeholder="Инструкции за агента с @{{placeholder}}-и..."></textarea>
                                <p class="text-xs text-gray-400 mt-1">Плейсхолдъри: @{{url}}, @{{topic}}, @{{node:Име на възел}}</p>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Модел</label>
                                    <select x-model="selected.model" :disabled="modalReadOnly"
                                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:bg-gray-50 disabled:text-gray-500">
                                        <option value="">(по подразбиране)</option>
                                        <template x-for="m in recommendedModels(selected.type)" :key="'rec-' + m.ollama_tag">
                                            <option :value="m.ollama_tag" x-text="'★ ' + (m.display_name || m.ollama_tag)"></option>
                                        </template>
                                        <template x-for="m in otherModels(selected.type)" :key="'other-' + m.ollama_tag">
                                            <option :value="m.ollama_tag" x-text="m.display_name || m.ollama_tag"></option>
                                        </template>
                                    </select>
                                    <p class="text-xs text-gray-400 mt-1">Препоръчаните модели са маркирани със ★.</p>
                                </div>

                                <div x-show="selected.type === 'qa_verifier'">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">QA праг (%)</label>
                                    <select x-model.number="selected.config.qa.threshold"
                                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                        <template x-for="threshold in qaThresholdOptions" :key="threshold">
                                            <option :value="threshold" x-text="threshold + '%'"></option>
                                        </template>
                                    </select>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div x-show="selected.type !== 'qa_verifier'">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Роля в изхода</label>
                                    <select x-model="selected.output_role" :disabled="modalReadOnly"
                                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                        <option value="">(авто от тип)</option>
                                        <option value="body">Основно съдържание</option>
                                        <option value="appendix">Добавка (хаштагове, SEO)</option>
                                        <option value="hidden">Контекст (междинен, не във финалния output)</option>
                                    </select>
                                    <p class="text-xs text-gray-400 mt-1">Определя къде ще се появи резултатът във финалния output.</p>
                                </div>

                                <div class="flex items-center gap-3 pt-7">
                                    <input type="checkbox" x-model="selected.is_active" :disabled="modalReadOnly" id="node-is-active"
                                           class="w-4 h-4 text-indigo-600 border-gray-300 rounded">
                                    <label for="node-is-active" class="text-sm font-medium text-gray-700">Активен</label>
                                </div>
                            </div>
                        </div>

                        <div x-show="propsTab === 'output'" class="space-y-5">
                            <p class="text-xs text-gray-500 bg-indigo-50 rounded-lg px-3 py-2">
                                Тези настройки се <strong>инжектират автоматично</strong> в system prompt-а на агента при изпълнение.
                            </p>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Език на изхода</label>
                                    <select x-model="selected.output_language" :disabled="modalReadOnly"
                                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                        <template x-for="(label, code) in outputPrefs.langs" :key="code">
                                            <option :value="code" x-text="label"></option>
                                        </template>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Тон</label>
                                    <select x-model="selected.output_tone" :disabled="modalReadOnly"
                                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                        <option value="">— без предпочитание —</option>
                                        <template x-for="(label, tone) in outputPrefs.tones" :key="tone">
                                            <option :value="tone" x-text="label"></option>
                                        </template>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Стил</label>
                                    <select x-model="selected.output_style" :disabled="modalReadOnly"
                                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                        <option value="">— без предпочитание —</option>
                                        <template x-for="(label, style) in outputPrefs.styles" :key="style">
                                            <option :value="style" x-text="label"></option>
                                        </template>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Формат</label>
                                    <select x-model="selected.output_format" :disabled="modalReadOnly"
                                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                        <option value="">— без предпочитание —</option>
                                        <template x-for="(label, format) in outputPrefs.formats" :key="format">
                                            <option :value="format" x-text="label"></option>
                                        </template>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div x-show="propsTab === 'params'" class="space-y-5">
                            <div class="rounded-xl border border-indigo-100 bg-indigo-50 px-4 py-3 text-sm text-indigo-950 space-y-2">
                                <h2 class="font-semibold text-indigo-900">Как да мислим за тези параметри</h2>
                                <p>
                                    Оставените празни полета използват стойностите по подразбиране на модела. Променяй по една настройка наведнъж.
                                </p>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 space-y-3">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                        Temperature
                                        <span class="text-xs font-normal text-gray-400">(0 – 2, default: 0.7)</span>
                                    </label>
                                    <input type="number" x-model.number="selected.config.temperature" :disabled="modalReadOnly" step="0.05" min="0" max="2"
                                           placeholder="0.7"
                                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                    <p class="text-xs text-gray-500">Контролира колко смело моделът избира следващата дума.</p>
                                </div>

                                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 space-y-3">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                        Top P
                                        <span class="text-xs font-normal text-gray-400">(0 – 1, default: 0.9)</span>
                                    </label>
                                    <input type="number" x-model.number="selected.config.top_p" :disabled="modalReadOnly" step="0.05" min="0" max="1"
                                           placeholder="0.9"
                                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                    <p class="text-xs text-gray-500">Ограничава избора до най-вероятните токени.</p>
                                </div>

                                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 space-y-3">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                        Top K
                                        <span class="text-xs font-normal text-gray-400">(1 – 200, default: 40)</span>
                                    </label>
                                    <input type="number" x-model.number="selected.config.top_k" step="1" min="1" max="200"
                                           placeholder="40"
                                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                    <p class="text-xs text-gray-500">Поставя твърд лимит колко възможни токена се разглеждат.</p>
                                </div>

                                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 space-y-3">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                        Repeat Penalty
                                        <span class="text-xs font-normal text-gray-400">(0 – 2, default: 1.1)</span>
                                    </label>
                                    <input type="number" x-model.number="selected.config.repeat_penalty" step="0.05" min="0" max="2"
                                           placeholder="1.1"
                                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                    <p class="text-xs text-gray-500">Намалява повтарянето на вече използвани думи и фрази.</p>
                                </div>

                                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 space-y-3 md:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                        Max Tokens (num_predict)
                                        <span class="text-xs font-normal text-gray-400">(-1 = без лимит, default: -1)</span>
                                    </label>
                                    <input type="number" x-model.number="selected.config.num_predict" :disabled="modalReadOnly" step="1" min="-1"
                                           placeholder="-1"
                                           class="w-full md:w-64 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                    <p class="text-xs text-gray-500">Задава горна граница за дължината на отговора в токени.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 flex flex-wrap items-center justify-between gap-3">
                        {{-- Read-only: only Close; Edit: Delete + Cancel + Save --}}
                        <template x-if="!modalReadOnly">
                            <button type="button" @click="removeSelectedFromModal()"
                                    class="text-sm text-red-600 hover:text-red-700 font-medium">
                                Изтрий възела
                            </button>
                        </template>
                        <template x-if="modalReadOnly">
                            <span class="text-xs text-gray-400 italic">Настройките не могат да се редактират в режим на преглед.</span>
                        </template>
                        <div class="flex items-center gap-2">
                            <button type="button" @click="closeNodeModal()"
                                    class="bg-white border border-gray-300 text-gray-600 text-sm px-4 py-2 rounded-lg hover:bg-gray-50 transition"
                                    x-text="modalReadOnly ? 'Затвори' : 'Отказ'">
                            </button>
                            <button x-show="!modalReadOnly" type="button" @click="saveNodeModal()"
                                    class="bg-green-600 hover:bg-green-700 text-white text-sm font-semibold px-5 py-2 rounded-lg transition">
                                Запази свойствата
                            </button>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/drawflow@0.0.59/dist/drawflow.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script>
function flowBuilder(config) {
    return {
        editor: null,
        selectedId: null,
        selected: null,
        propertiesOpen: false,
        propsTab: 'basic',
        propsTabs: [
            { id: 'basic', label: 'Основни' },
            { id: 'output', label: 'Output' },
            { id: 'params', label: 'Параметри' },
        ],
        models: config.models || [],
        agentTypes: config.agentTypes || [],
        templateIcons: config.templateIcons || {},
        typeIconFallbacks: {
            researcher: '🔎',
            deep_researcher: '🧭',
            trend_researcher: '📈',
            competitor_profiler: '🏢',
            review_analyzer: '💬',
            keyword_extractor: '🔑',
            image_describer: '🖼️',
            scraper: '🕷️',
            analyzer: '📊',
            swot_builder: '🧩',
            data_extractor: '📋',
            classifier: '🏷️',
            sentiment_analyzer: '🙂',
            summarizer: '📝',
            decision: '🔀',
            content_bg: '✍️',
            content_en: '✍️',
            writer: '✍️',
            caption_writer: '💬',
            hook_writer: '🪝',
            ad_copywriter: '📣',
            report_writer: '📄',
            newsletter_writer: '✉️',
            email_composer: '📧',
            seo_writer: '🔍',
            offer_builder: '💼',
            translator: '🌐',
            publisher: '🚀',
            report_composer: '🧾',
            bg_text_corrector: '✅',
            formatter: '🧱',
            hashtag: '#',
            hashtags: '#',
            hashtag_generator: '#',
            qa_verifier: '🛡️',
            verifier: '🛡️',
            orchestrator: '🎛️',
            code: '💻',
            vision: '👁️',
        },
        outputPrefs: config.outputPrefs || { langs: {}, tones: {}, styles: {}, formats: {} },
        runUrl: config.runUrl,
        mode: config.mode || 'edit',
        saving: false,
        savedAt: null,
        saveError: null,
        validation: null,
        generating: {},

        // ── Agent generation (DAG) ──
        gen: { active: false, progress: 0, message: '', error: null, token: null, autoSave: false, _timer: null, _rot: null },

        // ── Run/view per-node data + modals ──
        runData: {},          // node_key → { status, output, raw_output, error, model, duration_ms, tokens_used, steps }
        runStatus: null,      // mirrors poll's data.status — null | 'pending' | 'running' | 'completed' | 'failed'
        stalledRun: false,    // true when run is 'running' but no NodeRun activity after 40s (worker not running)
        _pageLoadedAt: Date.now(),
        finalOutput: null,
        resultModal: { open: false, title: '', body: '' },
        logModal: { open: false, title: '', meta: {}, error: '', steps: '', input: '', params: null, systemPrompt: '' },
        finalModal: { open: false, body: '' },
        genLogModal: { open: false, loading: false, logs: [], error: '' },
        modalReadOnly: false, // true in run/view modes — makes the properties modal display-only
        qaThresholdOptions: Array.from({ length: 21 }, (_, i) => i * 5),

        showPicker: false,
        activePickerTab: 'all',
        pickerSearch: '',
        pickerLoading: false,
        pickerLoaded: false,
        pickerTemplates: { system: [], company: [] },
        pickerTabs: [
            { id: 'all', label: 'Всички' },
            { id: 'mine', label: '🏢 Моите агенти' },
            { id: 'system', label: '⚙ Системни агенти' },
        ],
        boundaryDefinitions: {
            start: { type: 'flow_start', name: 'Старт', icon: '▶', inputs: 0, outputs: 1, x: 60, y: 120 },
            end: { type: 'flow_end', name: 'Край', icon: '■', inputs: 1, outputs: 0, x: 1120, y: 120 },
        },

        init() {
            if (this.editor) return;

            const el = document.getElementById('drawflow');
            this.editor = new Drawflow(el);
            this.editor.reroute = true;
            this.editor.start();

            this.bindNodeEditClicks(el);

            if (config.graphLayout) {
                try {
                    this.editor.import(config.graphLayout);
                    this.refreshAllNodes();
                } catch (e) {
                    console.error('import failed', e);
                }
            }

            this.ensureBoundaryNodes();

            this.editor.on('nodeSelected', (id) => { this.selectedId = id; });
            this.editor.on('nodeUnselected', () => {
                if (!this.propertiesOpen) this.selectedId = null;
            });
            this.editor.on('nodeRemoved', () => {
                this.$nextTick(() => this.ensureBoundaryNodes());
            });

            window.addEventListener('keydown', (event) => this.preventBoundaryDelete(event));

            // Delegate clicks for injected run-mode buttons (result / log / final).
            this.bindRunClicks(el);

            // In run/view mode, allow node dragging (for visual rearranging)
            // but block accidental connection drawing via CSS on port dots.
            // We do NOT use 'fixed' because that prevents all dragging.
            if (this.mode !== 'edit') {
                document.getElementById('drawflow')?.classList.add('df-run-view');
                // Keep editor_mode = 'edit' so nodes can be moved; the save()
                // guard below prevents any changes from actually persisting.
            }

            if (this.mode === 'run') {
                this.startPolling();
            } else if (this.mode === 'view') {
                // One-shot hydrate from the completed run, then auto-open final.
                this.pollOnce().then(() => {
                    if (config.autoOpenFinal) this.openFinal();
                });
            } else if (config.generate) {
                // Fresh flow created via "Запази и генерирай агенти".
                this.$nextTick(() => this.startGeneration(true));
            }
        },

        bindRunClicks(el) {
            el.addEventListener('click', (event) => {
                const result = event.target.closest('.df-run-result');
                const log = event.target.closest('.df-run-log');
                const final = event.target.closest('.df-final-btn');
                if (!result && !log && !final) return;
                event.preventDefault();
                event.stopPropagation();
                if (final) { this.openFinal(); return; }
                const nodeEl = (result || log).closest('.drawflow-node');
                if (!nodeEl) return;
                const key = nodeEl.id.replace('node-', '');
                if (result) this.openResult(key); else this.openLog(key);
            }, true);
        },

        bindNodeEditClicks(el) {
            const stopIfEditButton = (event) => {
                if (!event.target.closest('.df-node-edit')) return;
                event.preventDefault();
                event.stopPropagation();
            };

            el.addEventListener('mousedown', stopIfEditButton, true);
            el.addEventListener('click', (event) => {
                const button = event.target.closest('.df-node-edit');
                if (!button) return;

                event.preventDefault();
                event.stopPropagation();

                const nodeEl = button.closest('.drawflow-node');
                if (!nodeEl || !nodeEl.id) return;

                this.openNodeModal(nodeEl.id.replace('node-', ''));
            }, true);
        },

        typeLabel(type) {
            return (this.agentTypes.find(t => t.type === type) || {}).label || type || 'Агент';
        },

        typeMeta(type) {
            return this.agentTypes.find(t => t.type === type) || { type, label: type || 'Агент', output_role: 'body' };
        },

        roleLabel(role) {
            return {
                body: 'Основен',
                hidden: 'Контекст',
                processing: 'Обработка',
                appendix: 'Добавка',
                quality: 'Качество',
            }[role] || role || 'Авто';
        },

        roleDescription(role) {
            return {
                body: 'Финален видим резултат',
                hidden: 'Междинен резултат за следващи агенти, без директно включване във финалния output',
                processing: 'Междинна обработка',
                appendix: 'Добавка към финалния резултат',
                quality: 'QA/проверка на качество',
            }[role] || '';
        },

        effectiveOutputRole(data) {
            return data.output_role || this.typeMeta(data.type).output_role || 'body';
        },

        roleClass(role) {
            const safe = String(role || 'body').toLowerCase().replace(/[^a-z0-9_-]/g, '');
            return 'df-role-' + (safe || 'body');
        },

        escapeHtml(s) {
            return String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
        },

        isBoundaryData(data) {
            return Boolean(data?.is_boundary) || ['flow_start', 'flow_end'].includes(data?.type);
        },

        isBoundaryNodeId(id) {
            const node = this.editor?.getNodeFromId(id);
            return this.isBoundaryData(node?.data);
        },

        preventBoundaryDelete(event) {
            if (!['Delete', 'Backspace'].includes(event.key)) return;
            if (this.selectedId == null || !this.isBoundaryNodeId(this.selectedId)) return;

            event.preventDefault();
            event.stopPropagation();
        },

        resolveIcon(data) {
            if (data.icon && data.icon !== '🤖') return data.icon;

            return this.templateIcons[data.type] || this.typeIconFallbacks[data.type] || data.icon || '🤖';
        },

        nodeHtml(data) {
            if (this.isBoundaryData(data)) {
                return this.boundaryNodeHtml(data);
            }

            const role = this.effectiveOutputRole(data);
            const icon = this.resolveIcon(data);
            const roleDescription = this.roleDescription(role);

            // The run-extra block is ALWAYS rendered but collapsed in edit mode
            // via the df-run-hidden class so the card has no dead space below the
            // ports. When we flip into run mode the block expands and the card
            // grows; applyRunStatuses() calls editor.updateConnectionNodes() on
            // the reveal so the cached connection endpoints follow the port dots.
            return `<div class="df-node-card df-run-hidden ${this.roleClass(role)}">
                        <div class="df-node-header">
                            <div class="df-node-icon">${this.escapeHtml(icon)}</div>
                            <div class="df-node-main">
                                <div class="df-node-name">${this.escapeHtml(data.name || 'Агент')}</div>
                                <div class="df-node-type">${this.escapeHtml(this.typeLabel(data.type))}</div>
                            </div>
                            <button type="button" class="df-node-edit nodrag" title="Свойства" aria-label="Свойства">⚙</button>
                        </div>
                        <div class="df-node-footer">
                            <span class="df-port-label df-port-label-in">вход</span>
                            <span title="${this.escapeHtml(roleDescription)}">${this.escapeHtml(this.roleLabel(role))}</span>
                            <span class="df-port-label df-port-label-out">изход</span>
                        </div>
                        <div class="df-run-extra">
                            <div class="df-run-progress"><span></span></div>
                            <div class="df-run-status-label">Изчаква своя ред</div>
                            <div class="df-run-actions">
                                <button type="button" class="df-run-result nodrag" disabled>Резултат</button>
                                <button type="button" class="df-run-log nodrag" title="Лог">📜</button>
                            </div>
                        </div>
                    </div>`;
        },

        boundaryNodeHtml(data) {
            return `<div class="df-boundary-card" title="Системен визуален възел. Не се изпълнява като агент и не може да се редактира.">
                        <span class="df-boundary-icon">${this.escapeHtml(data.icon)}</span>
                        <span>${this.escapeHtml(data.name)}</span>
                    </div>`;
        },

        defaultConfig(configValue = {}) {
            const configData = configValue && typeof configValue === 'object' && !Array.isArray(configValue)
                ? JSON.parse(JSON.stringify(configValue))
                : {};

            configData.qa = configData.qa && typeof configData.qa === 'object' && !Array.isArray(configData.qa)
                ? configData.qa
                : {};
            configData.qa.enabled = Boolean(configData.qa.enabled);
            configData.qa.threshold = Number(configData.qa.threshold ?? 60);

            return configData;
        },

        normalizeNodeData(data) {
            if (this.isBoundaryData(data)) {
                return data;
            }

            const meta = this.typeMeta(data?.type || 'content_bg');
            const normalized = {
                type: data?.type || 'content_bg',
                name: data?.name || meta.label || 'Нов агент',
                icon: data?.icon && data.icon !== '🤖'
                    ? data.icon
                    : (this.templateIcons[data?.type] || this.typeIconFallbacks[data?.type] || data?.icon || '🤖'),
                role: data?.role || '',
                model: data?.model || '',
                prompt_template: data?.prompt_template || '',
                system_prompt: data?.system_prompt || '',
                output_language: data?.output_language || 'bg',
                output_tone: data?.output_tone || '',
                output_style: data?.output_style || '',
                output_format: data?.output_format || '',
                output_role: data?.output_role || meta.output_role || '',
                is_active: data?.is_active !== false,
                config: this.defaultConfig(data?.config || {}),
            };

            if (normalized.type === 'qa_verifier') {
                normalized.config.qa.threshold = Number(normalized.config.qa.threshold ?? 60);
            }

            return normalized;
        },

        refreshAllNodes() {
            const data = this.editor.export().drawflow?.Home?.data || {};
            for (const id in data) {
                const rawData = data[id].data || {};
                const normalized = this.isBoundaryData(rawData)
                    ? rawData
                    : this.normalizeNodeData(rawData);
                this.editor.updateNodeDataFromId(id, normalized);
                this.updateNodeLabel(id, normalized);
            }
        },

        updateNodeLabel(id, data) {
            const normalized = this.isBoundaryData(data) ? data : this.normalizeNodeData(data);
            const content = document.querySelector('#node-' + id + ' .drawflow_content_node');
            if (content) content.innerHTML = this.isBoundaryData(normalized)
                ? this.boundaryNodeHtml(normalized)
                : this.nodeHtml(normalized);

            const store = this.editor.drawflow.drawflow?.Home?.data?.[id];
            if (store) {
                store.data = normalized;
                store.html = this.isBoundaryData(normalized)
                    ? this.boundaryNodeHtml(normalized)
                    : this.nodeHtml(normalized);
            }
        },

        nextNodePosition() {
            const nodes = this.editor.export().drawflow?.Home?.data || {};
            const count = Object.values(nodes).filter(node => !this.isBoundaryData(node.data)).length;
            return {
                x: 160 + (count % 4) * 330,
                y: 180 + Math.floor(count / 4) * 180,
            };
        },

        findBoundaryNodeId(boundary) {
            const nodes = this.editor.export().drawflow?.Home?.data || {};
            for (const id in nodes) {
                if (nodes[id].data?.boundary === boundary || nodes[id].data?.type === this.boundaryDefinitions[boundary]?.type) {
                    return id;
                }
            }

            return null;
        },

        ensureBoundaryNodes() {
            for (const boundary of ['start', 'end']) {
                const existingId = this.findBoundaryNodeId(boundary);
                const definition = this.boundaryDefinitions[boundary];
                const data = {
                    type: definition.type,
                    name: definition.name,
                    icon: definition.icon,
                    boundary,
                    is_boundary: true,
                    is_active: false,
                    locked: true,
                    config: {},
                };

                if (existingId) {
                    this.editor.updateNodeDataFromId(existingId, data);
                    this.updateNodeLabel(existingId, data);
                    continue;
                }

                this.editor.addNode(
                    definition.type,
                    definition.inputs,
                    definition.outputs,
                    definition.x,
                    definition.y,
                    'flow-boundary',
                    data,
                    this.boundaryNodeHtml(data)
                );
            }
        },

        addNodeData(data) {
            const normalized = this.normalizeNodeData(data);
            const pos = this.nextNodePosition();
            const before = Object.keys(this.editor.export().drawflow?.Home?.data || {});
            const returnedId = this.editor.addNode(normalized.type, 1, 1, pos.x, pos.y, 'flow-node', normalized, this.nodeHtml(normalized));
            const after = Object.keys(this.editor.export().drawflow?.Home?.data || {});

            return returnedId || after.find(id => !before.includes(id)) || after[after.length - 1];
        },

        openNodeModal(id) {
            const node = this.editor.getNodeFromId(id);
            if (!node) return;
            if (this.isBoundaryData(node.data)) return;

            this.selectedId = id;
            this.selected = this.normalizeNodeData(JSON.parse(JSON.stringify(node.data || {})));
            this.propsTab = 'basic';
            this.propertiesOpen = true;
            this.generating = {};
            // In run or view mode open as read-only — settings are informational only.
            this.modalReadOnly = this.mode !== 'edit';
        },

        closeNodeModal() {
            this.propertiesOpen = false;
            this.selected = null;
            this.generating = {};
            this.modalReadOnly = false;
        },

        saveNodeModal() {
            if (this.selectedId == null || !this.selected) return;

            const normalized = this.normalizeNodeData(this.selected);
            this.editor.updateNodeDataFromId(this.selectedId, normalized);
            this.updateNodeLabel(this.selectedId, normalized);
            this.closeNodeModal();
        },

        removeSelectedFromModal() {
            if (this.selectedId != null && this.isBoundaryNodeId(this.selectedId)) return;
            if (this.selectedId == null || !confirm('Изтрий възела "' + (this.selected?.name || 'Агент') + '"?')) return;

            this.editor.removeNodeId('node-' + this.selectedId);
            this.selectedId = null;
            this.closeNodeModal();
        },

        onSelectedTypeChanged() {
            if (!this.selected) return;

            const meta = this.typeMeta(this.selected.type);
            if (!this.selected.output_role) this.selected.output_role = meta.output_role || '';
            this.selected.config = this.defaultConfig(this.selected.config || {});

            if (this.selected.type === 'qa_verifier') {
                this.selected.config.qa.threshold = Number(this.selected.config.qa.threshold ?? 60);
                this.selected.output_role = 'quality';
            }
        },

        recommendedModels(type) {
            return this.models.filter(m => (m.is_default_for || []).includes(type));
        },

        otherModels(type) {
            return this.models.filter(m => !(m.is_default_for || []).includes(type));
        },

        templateToNodeData(tpl) {
            const firstModel = this.models[0];
            const type = tpl ? (tpl.type || 'content_bg') : 'content_bg';
            const meta = this.typeMeta(type);
            const resolvedModel = tpl && tpl.model && this.models.find(m => m.ollama_tag === tpl.model)
                ? tpl.model
                : (firstModel ? firstModel.ollama_tag : '');

            return {
                name: tpl ? (tpl.name || meta.label || 'Нов агент') : 'Нов агент',
                icon: tpl ? (tpl.icon || this.templateIcons[type] || this.typeIconFallbacks[type] || '🤖') : (this.templateIcons[type] || this.typeIconFallbacks[type] || '🤖'),
                type,
                role: tpl ? (tpl.role || tpl.description || '') : '',
                model: resolvedModel,
                system_prompt: tpl ? (tpl.system_prompt || '') : '',
                prompt_template: tpl ? (tpl.prompt_template || '') : '',
                output_language: 'bg',
                output_tone: '',
                output_style: '',
                output_format: '',
                output_role: meta.output_role || '',
                is_active: true,
                config: tpl && tpl.config ? tpl.config : { temperature: 0.7, num_predict: 1000, qa: { enabled: false, threshold: 60 } },
            };
        },

        get filteredSystemTemplates() {
            const q = this.pickerSearch.toLowerCase();
            return (this.pickerTemplates.system || []).filter(t =>
                !q || (t.name || '').toLowerCase().includes(q) || (t.type || '').toLowerCase().includes(q)
            );
        },

        get filteredCompanyTemplates() {
            const q = this.pickerSearch.toLowerCase();
            return (this.pickerTemplates.company || []).filter(t =>
                !q || (t.name || '').toLowerCase().includes(q) || (t.type || '').toLowerCase().includes(q)
            );
        },

        async openAgentPicker() {
            this.showPicker = true;
            this.activePickerTab = 'all';
            this.pickerSearch = '';

            if (this.pickerLoaded) return;

            this.pickerLoading = true;
            try {
                const resp = await fetch(`${config.pickerUrl}?company_id=${config.companyId}`, {
                    headers: { 'Accept': 'application/json' },
                });
                if (resp.ok) {
                    this.pickerTemplates = await resp.json();
                    this.pickerLoaded = true;
                } else {
                    console.error('Failed to load templates, status:', resp.status);
                }
            } catch (e) {
                console.error('Failed to load templates', e);
            } finally {
                this.pickerLoading = false;
            }
        },

        selectTemplate(tpl) {
            this.showPicker = false;
            const id = this.addNodeData(this.templateToNodeData(tpl));
            this.$nextTick(() => this.openNodeModal(id));
        },

        async generateField(field) {
            if (!this.selected?.name?.trim()) {
                alert('Въведи първо името на агента.');
                return;
            }

            this.generating[field] = true;
            try {
                const resp = await fetch(config.generateFieldUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': config.csrf,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        field,
                        agent_name: this.selected.name || '',
                        agent_type: this.selected.type || '',
                        flow_description: config.flowDescription || '',
                        role: this.selected.role || '',
                        system_prompt: this.selected.system_prompt || '',
                        prompt_template: this.selected.prompt_template || '',
                    }),
                });

                const data = await resp.json();
                if (!resp.ok) {
                    alert(data.error || 'Грешка при AI генерация. Провери дали Ollama работи.');
                } else if (data.generated) {
                    this.selected[field] = data.generated;
                }
            } catch (e) {
                console.error('generateField error', e);
                alert('Мрежова грешка при AI генерация.');
            } finally {
                this.generating[field] = false;
            }
        },

        export() {
            this.ensureBoundaryNodes();

            return this.editor.export();
        },

        async save() {
            // Never persist changes when in run or view mode — dragging nodes
            // around for visual clarity should not overwrite the saved graph.
            if (this.mode !== 'edit') return false;
            this.saving = true;
            this.saveError = null;
            try {
                const res = await fetch(config.saveUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': config.csrf,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ graph: this.export() }),
                });

                const data = await res.json().catch(() => ({}));
                if (!res.ok || data.ok === false) {
                    this.saveError = data.error || 'Грешка при запис на графа.';
                    return false;
                }

                this.savedAt = new Date().toLocaleTimeString();
                return true;
            } catch (e) {
                console.error('save graph error', e);
                this.saveError = 'Мрежова грешка при запис на графа.';
                return false;
            } finally {
                this.saving = false;
            }
        },

        async validate() {
            const res = await fetch(config.validateUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': config.csrf },
                body: JSON.stringify({ graph: this.export() }),
            });
            this.validation = await res.json();
        },

        startPolling() {
            const tick = async () => {
                const done = await this.pollOnce();
                if (done) return;
                setTimeout(tick, 2500);
            };
            tick();
        },

        // Fetch run state once; returns true when the run is finished.
        async pollOnce() {
            if (!config.pollUrl) return true;
            try {
                const res = await fetch(config.pollUrl);
                const data = await res.json();
                this.ingestPoll(data);

                // Stall detection: run is marked 'running' but no NodeRun records
                // after 40 seconds → likely the queue worker isn't running.
                if (data.status === 'running') {
                    const elapsed = Date.now() - this._pageLoadedAt;
                    if ((data.node_runs || []).length === 0 && elapsed > 40000) {
                        this.stalledRun = true;
                    } else if ((data.node_runs || []).length > 0) {
                        this.stalledRun = false;
                    }
                } else {
                    this.stalledRun = false;
                }

                return ['completed', 'failed'].includes(data.status);
            } catch (e) {
                return false;
            }
        },

        // Merge a poll payload into runData and refresh node visuals.
        ingestPoll(data) {
            const prevStatus = this.runStatus;
            this.runStatus = data.status ?? this.runStatus;

            this.finalOutput = data.final_output ?? this.finalOutput;
            if (this.finalModal.open) this.finalModal.body = this.finalOutput || '';

            // Auto-open final popup when a live run completes (view mode already
            // handles this on first hydrate via config.autoOpenFinal).
            if (this.mode === 'run' && prevStatus !== 'completed' && data.status === 'completed') {
                this.$nextTick(() => this.openFinal());
            }

            // node_runs gives node_key+status; agent_runs (same order, graph mode) carries
            // output/raw/model/tokens/input. Merge both by index into runData keyed by node_key.
            (data.node_runs || []).forEach((nr, i) => {
                const ar = (data.agent_runs || [])[i] || {};
                const key = String(nr.node_key);
                this.runData[key] = Object.assign({}, this.runData[key], {
                    status: nr.status,
                    duration_ms: nr.duration_ms,
                    started_at_iso: nr.started_at_iso,
                    completed_at_iso: nr.completed_at_iso,
                    error: nr.error,
                    output: ar.output,
                    raw_output: ar.raw_output,
                    input: ar.input,
                    model: ar.model_used,
                    params: ar.params,
                    tokens_used: ar.tokens_used,
                });
            });

            this._lastProgress = data.progress || {};
            this.applyStatuses(data.node_runs || [], this._lastProgress, data.status);
        },

        applyStatuses(nodeRuns, progress, runStatus) {
            const byKey = {};
            const startByKey = {};
            for (const r of nodeRuns) {
                byKey[r.node_key] = r.status;
                if (r.started_at_iso) startByKey[r.node_key] = Date.parse(r.started_at_iso);
            }

            // Determine the boundary status from the overall run status.
            // Start = green when run is running or completed.
            // End   = green when run is completed, red when failed.
            const startNodeStatus = (runStatus === 'running' || runStatus === 'completed')
                ? 'running' : null;
            const endNodeStatus = runStatus === 'completed' ? 'completed'
                : runStatus === 'failed' ? 'failed' : null;

            const startId = this.findBoundaryNodeId('start');
            const endId   = this.findBoundaryNodeId('end');

            const exp = this.editor.export().drawflow.Home.data;
            for (const id in exp) {
                const node = exp[id];
                const el = document.getElementById('node-' + id);
                if (!el) continue;
                const isBoundary = this.isBoundaryData(node.data);

                if (isBoundary) {
                    // Apply colour to boundary cards based on run status.
                    el.classList.remove('df-status-running', 'df-status-completed', 'df-status-failed');
                    if (String(id) === String(startId) && startNodeStatus) {
                        el.classList.add('df-status-' + startNodeStatus);
                    } else if (String(id) === String(endId) && endNodeStatus) {
                        el.classList.add('df-status-' + endNodeStatus);
                    }
                    if (node.data?.type === 'flow_end') this.decorateEndNode(el, runStatus);
                    continue;
                }

                // Default for nodes that haven't started yet in an active/finished
                // run: 'pending' (grey static bar) — NOT undefined, which would
                // leave a stale animation from initial render.
                const status = byKey[String(id)] || (this.mode === 'edit' ? null : 'pending');

                el.classList.remove('df-status-running', 'df-status-completed', 'df-status-failed', 'df-status-skipped', 'df-status-pending');
                if (status) el.classList.add('df-status-' + status);

                // Reveal the run UI once any status is applied. The card grows
                // taller when the run-extra block expands, so recompute this
                // node's connections on the transition to keep wires aligned.
                const card = el.querySelector('.df-node-card');
                if (card && status && card.classList.contains('df-run-hidden')) {
                    card.classList.remove('df-run-hidden');
                    try { this.editor.updateConnectionNodes('node-' + id); } catch (e) {}
                }

                this.decorateRunNode(el, String(id), status, progress, startByKey[String(id)]);
            }

            // Keep the time-based creep tick alive while at least one node is
            // running, even if poll responses are between intervals.
            this.scheduleProgressTick(byKey);
        },

        // Update the progress bar + button state per status. The run-extra DOM
        // is pre-rendered by nodeHtml so the card height never changes here.
        decorateRunNode(el, key, status, progress, startedAtMs) {
            const card = el.querySelector('.df-node-card');
            if (!card) return;
            const bar = card.querySelector('.df-run-progress');
            const fill = bar?.querySelector('span');
            const resultBtn = card.querySelector('.df-run-result');
            const label = card.querySelector('.df-run-status-label');
            if (!bar || !fill || !resultBtn) return;

            if (status === 'running' && progress && progress.pages_total > 0) {
                // Real page counter from researcher/deep_researcher logs.
                fill.style.width = Math.min(100, Math.round((progress.pages_done / progress.pages_total) * 100)) + '%';
            } else if (status === 'running' && startedAtMs) {
                // Time-based creep: 0 → 90% over ~60s elapsed. Gives a live
                // feel without lying about the % when no real counter exists.
                const elapsed = Date.now() - startedAtMs;
                const pct = Math.min(90, Math.max(2, (elapsed / 60000) * 90));
                fill.style.width = pct.toFixed(1) + '%';
            } else if (status === 'running') {
                // Fallback if start time not yet available.
                fill.style.width = '5%';
            } else if (status === 'pending') {
                fill.style.width = '0%';
            }
            // completed/failed widths are forced to 100% in CSS.

            resultBtn.disabled = status !== 'completed';

            // Update the small text label under the bar.
            if (label) {
                const labelText = {
                    pending:   'Изчаква своя ред',
                    running:   'В момента работи…',
                    completed: 'Завършен',
                    failed:    'Неуспешен',
                    skipped:   'Пропуснат',
                };
                label.textContent = labelText[status] ?? '';
            }
        },

        // Lightweight 1s tick so the running-node bar visibly creeps between polls.
        scheduleProgressTick(byKey) {
            const anyRunning = Object.values(byKey).some(s => s === 'running');
            if (!anyRunning) {
                if (this._progressTick) { clearInterval(this._progressTick); this._progressTick = null; }
                return;
            }
            if (this._progressTick) return;
            this._progressTick = setInterval(() => {
                // Re-apply progress with current run state cached in this.runData.
                const fakeNodeRuns = Object.entries(this.runData).map(([k, v]) => ({
                    node_key: k, status: v.status,
                    started_at_iso: v.started_at_iso ?? null,
                }));
                this.applyStatuses(fakeNodeRuns, this._lastProgress || {}, null);
            }, 1000);
        },

        decorateEndNode(el, runStatus) {
            const card = el.querySelector('.df-boundary-card');
            if (!card) return;
            const has = card.querySelector('.df-final-btn');
            if (runStatus === 'completed' && !has) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'df-final-btn nodrag';
                btn.textContent = '🏁 Резултат';
                card.appendChild(btn);
            }
        },

        // Render Markdown as HTML (falls back to escaped plain text if marked is unavailable).
        renderMd(text) {
            if (!text) return '';
            if (typeof marked === 'undefined') {
                return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/\n/g, '<br>');
            }
            return marked.parse(String(text), { breaks: false, gfm: true });
        },

        openResult(key) {
            const d = this.runData[key] || {};
            const node = this.editor.getNodeFromId(key);
            const title = node?.data?.name || ('Възел ' + key);
            let body;
            if (!d.status || d.status === 'pending') {
                body = 'Този агент още не е стартирал.';
            } else if (d.status === 'running') {
                body = 'Агентът работи в момента — изходът ще се появи, когато приключи.';
            } else {
                body = d.output || '(няма изход)';
            }
            this.resultModal = { open: true, title, body };
        },

        openLog(key) {
            const d = this.runData[key] || {};
            const node = this.editor.getNodeFromId(key);
            const title = node?.data?.name || ('Възел ' + key);
            const status = d.status || (this.mode === 'edit' ? '—' : 'pending');

            // Friendly status label.
            const statusLabel = {
                pending: 'Изчаква своя ред',
                running: 'В момента работи',
                completed: 'Завършен',
                failed: 'Неуспешен',
                skipped: 'Пропуснат',
            }[status] || status;

            if (status === 'pending') {
                this.logModal = {
                    open: true, title,
                    meta: { status: statusLabel, model: '—', duration: '—', tokens: '—' },
                    error: '', steps: '', params: null, systemPrompt: '',
                    input: 'Този агент още не е стартирал. Лог ще се появи, след като предходните агенти приключат.',
                };
                return;
            }

            const dur = d.duration_ms ? (Math.round(d.duration_ms / 100) / 10) + ' сек'
                      : (status === 'running' && d.started_at_iso)
                          ? Math.round((Date.now() - Date.parse(d.started_at_iso)) / 1000) + ' сек (в момента)'
                          : '—';

            // For the currently-running node, surface the recent log tail.
            let steps = d.raw_output && d.raw_output !== d.output ? d.raw_output : '';
            if (status === 'running' && this._lastProgress) {
                const p = this._lastProgress;
                const tail = Array.isArray(p.tail) ? p.tail.join('\n') : '';
                if (tail) steps = tail;
            }

            // Execution parameters actually sent to the model (snapshot per node run).
            const p = d.params || {};
            const opts = p.options || {};
            const fmt = (v) => (v === null || v === undefined || v === '') ? '—' : v;
            const params = {
                temperature: fmt(opts.temperature),
                top_p: fmt(opts.top_p),
                top_k: fmt(opts.top_k),
                repeat_penalty: fmt(opts.repeat_penalty),
                num_predict: fmt(opts.num_predict),
                seed: fmt(opts.seed),
                language: fmt(p.output_language),
                tone: fmt(p.output_tone),
                style: fmt(p.output_style),
                format: fmt(p.output_format),
            };

            this.logModal = {
                open: true, title,
                meta: { status: statusLabel, model: (p.model || d.model) || '—', duration: dur, tokens: d.tokens_used || '—' },
                error: d.error || '',
                steps,
                params,
                systemPrompt: p.system_prompt || '',
                input: (p.user_message || d.input) || (status === 'running' ? '(агентът все още работи)' : '—'),
            };
        },

        openFinal() {
            this.finalModal = { open: true, body: this.finalOutput || '' };
        },

        async openGenLog() {
            this.genLogModal = { open: true, loading: true, logs: [], error: '' };
            try {
                const res = await fetch(config.generationLogsUrl, { headers: { 'Accept': 'application/json' } });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const data = await res.json();
                this.genLogModal.logs = (data.logs || []).map(l => Object.assign({ _expanded: false }, l));
            } catch (e) {
                this.genLogModal.error = 'Неуспешно зареждане на логовете: ' + e.message;
            } finally {
                this.genLogModal.loading = false;
            }
        },

        // ───────────────────────── Agent generation (DAG) ─────────────────────────

        startGeneration(autoSave) {
            if (this.gen.active) return;
            const hasNodes = Object.values(this.editor.export().drawflow.Home.data || {})
                .some(n => !this.isBoundaryData(n.data));
            if (hasNodes && !config.generate) {
                if (!confirm('Това ще ИЗТРИЕ всички текущи агенти в графа и ще създаде нови. Продължаваме?')) return;
            }

            this.gen = { active: true, progress: 4, message: 'Стартиране…', error: null, token: null, autoSave: !!autoSave, _timer: null, _rot: null };
            this.startRotatingMessages();

            fetch(config.generateUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': config.csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ company_id: config.companyId, name: config.flowName, description: config.flowDescription }),
            })
            .then(r => r.json().then(d => ({ ok: r.ok, d })))
            .then(({ ok, d }) => {
                if (!ok || !d.token) { this.failGeneration(d.error || 'Неуспешно стартиране на генерацията.'); return; }
                this.gen.token = d.token;
                this.pollGeneration();
            })
            .catch(e => this.failGeneration('Мрежова грешка: ' + e.message));
        },

        pollGeneration() {
            const url = config.generationStatusUrlBase + '/' + this.gen.token;
            const tick = async () => {
                if (!this.gen.active) return;
                try {
                    const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                    const data = await res.json();
                    if (data.stage) this.gen.message = data.stage;
                    if (data.status === 'completed') {
                        this.gen.progress = 100;
                        await this.finishGeneration(data.agents || []);
                        return;
                    }
                    if (data.status === 'failed' || data.status === 'expired') {
                        this.failGeneration(data.error || 'Генерацията се провали.');
                        return;
                    }
                    // creep the bar forward up to 90% while pending
                    this.gen.progress = Math.min(90, this.gen.progress + 3);
                } catch (e) { /* keep polling */ }
                setTimeout(tick, 2000);
            };
            tick();
        },

        startRotatingMessages() {
            const phrases = [
                'Анализирам описанието…', 'Проектирам агентите…', 'Подбирам модели…',
                'Изграждам зависимостите…', 'Подреждам графа…', 'Обмислям следваща стъпка…',
            ];
            let i = 0;
            this.gen._rot = setInterval(() => {
                if (!this.gen.active) return;
                // Only rotate if the server hasn't given a fresh concrete stage recently.
                this.gen.message = phrases[i % phrases.length];
                i++;
            }, 2600);
        },

        stopGenerationTimers() {
            if (this.gen._rot) { clearInterval(this.gen._rot); this.gen._rot = null; }
        },

        failGeneration(msg) {
            this.stopGenerationTimers();
            this.gen.error = msg;
            this.gen.message = '';
        },

        async finishGeneration(agents) {
            this.stopGenerationTimers();
            this.gen.message = 'Готово — изграждам графа…';
            try {
                this.applyGeneratedGraph(agents);
            } catch (e) {
                console.error('applyGeneratedGraph failed', e);
                this.failGeneration('Грешка при изграждане на графа: ' + e.message);
                return;
            }
            if (this.gen.autoSave) {
                await this.save();
            }
            this.gen.active = false;
        },

        // Build Drawflow nodes + edges from generated agents (with depends_on → DAG).
        applyGeneratedGraph(agents) {
            // Remove all current non-boundary nodes.
            const data = this.editor.export().drawflow.Home.data || {};
            for (const id in data) {
                if (!this.isBoundaryData(data[id].data)) this.editor.removeNodeId('node-' + id);
            }
            this.ensureBoundaryNodes();

            const startId = this.findBoundaryNodeId('start');
            const endId = this.findBoundaryNodeId('end');

            const verifiers = agents.filter(a => a.is_verifier || a.type === 'qa_verifier');
            const chain = agents.filter(a => !(a.is_verifier || a.type === 'qa_verifier'));
            if (chain.length === 0) { this.ensureBoundaryNodes(); return; }

            // Resolve depends_on (by uid) → predecessor index list; fall back to sequential.
            const uidToIdx = {};
            chain.forEach((a, i) => { if (a.uid) uidToIdx[a.uid] = i; });
            const anyDeps = chain.some(a => Array.isArray(a.depends_on) && a.depends_on.length);
            const preds = chain.map((a, i) => {
                if (!anyDeps) return i === 0 ? [] : [i - 1];
                return (a.depends_on || []).map(u => uidToIdx[u]).filter(j => j !== undefined && j !== i);
            });

            // Layered layout: depth = longest path from a root.
            const depth = chain.map(() => 0);
            for (let pass = 0; pass < chain.length; pass++) {
                preds.forEach((ps, i) => ps.forEach(p => { depth[i] = Math.max(depth[i], depth[p] + 1); }));
            }
            // Column/row spacing tuned so cards (incl. the run-extra block, ~250px tall) never overlap.
            const COL_X0 = 260;
            const COL_W = 340;
            const ROW_Y0 = 80;
            const ROW_H = 260;

            const rowInCol = {};
            const ids = [];
            const yCenters = [];
            chain.forEach((a, i) => {
                const col = depth[i];
                const row = (rowInCol[col] = (rowInCol[col] || 0) + 1) - 1;
                const x = COL_X0 + col * COL_W;
                const y = ROW_Y0 + row * ROW_H;
                yCenters.push(y);
                const nodeData = this.normalizeNodeData(this.generatedToNodeData(a));
                const id = this.editor.addNode(nodeData.type, 1, 1, x, y, 'flow-node', nodeData, this.nodeHtml(nodeData));
                ids.push(id);
            });

            // Reposition the boundary nodes so they never overlap the DAG.
            const maxCol = Math.max(...depth);
            const yMid = yCenters.length
                ? Math.round((Math.min(...yCenters) + Math.max(...yCenters)) / 2)
                : 120;
            this.moveBoundaryNode(startId, 60, yMid);
            this.moveBoundaryNode(endId, COL_X0 + (maxCol + 1) * COL_W, yMid);

            // Edges: predecessors → node; roots ← Start; sinks → End.
            const hasSucc = chain.map(() => false);
            preds.forEach((ps) => ps.forEach(p => { hasSucc[p] = true; }));
            chain.forEach((a, i) => {
                if (preds[i].length === 0) {
                    this.editor.addConnection(startId, ids[i], 'output_1', 'input_1');
                } else {
                    preds[i].forEach(p => this.editor.addConnection(ids[p], ids[i], 'output_1', 'input_1'));
                }
                if (!hasSucc[i]) {
                    this.editor.addConnection(ids[i], endId, 'output_1', 'input_1');
                }
            });

            this.refreshAllNodes();
        },

        // Programmatic position update for a Drawflow node + recompute its
        // connection endpoints so lines follow the new coordinates.
        moveBoundaryNode(id, x, y) {
            const store = this.editor.drawflow.drawflow?.Home?.data?.[id];
            if (!store) return;
            store.pos_x = x;
            store.pos_y = y;
            const el = document.getElementById('node-' + id);
            if (el) {
                el.style.left = x + 'px';
                el.style.top = y + 'px';
            }
            try { this.editor.updateConnectionNodes('node-' + id); } catch (e) {}
        },

        generatedToNodeData(a) {
            const cfg = (a.config && typeof a.config === 'object') ? JSON.parse(JSON.stringify(a.config)) : {};
            const role = a.role || a.output_description || a.name || '';
            // Defensive fallbacks so a partial LLM response never produces a
            // FlowNode with empty prompts (the executor would otherwise fail).
            const promptTemplate = (a.prompt_template && a.prompt_template.trim())
                ? a.prompt_template
                : role || ('Извърши задачата на агент "' + (a.name || 'Агент') + '" и върни резултата.');
            const systemPrompt = (a.system_prompt && a.system_prompt.trim())
                ? a.system_prompt
                : ('Ти си агент "' + (a.name || 'Агент') + '". ' + (role ? role + ' ' : '') + 'Отговаряй на български език.');

            return {
                name: a.name || 'Агент',
                type: a.type || 'content_bg',
                icon: a.icon || this.templateIcons[a.type] || this.typeIconFallbacks[a.type] || '🤖',
                role,
                model: a.model || (this.models[0] ? this.models[0].ollama_tag : ''),
                system_prompt: systemPrompt,
                prompt_template: promptTemplate,
                output_language: a.output_language || 'bg',
                output_tone: a.output_tone || '',
                output_style: a.output_style || '',
                output_format: a.output_format || '',
                output_role: a.output_role || '',
                is_active: true,
                config: cfg,
            };
        },
    };
}
</script>
@endpush
