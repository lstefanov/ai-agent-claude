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
        cursor: default;
        user-select: none;
        -webkit-user-select: none;
    }

    #drawflow.df-space { cursor: grab; }
    #drawflow.df-panning { cursor: grabbing; }

    /* Builder Copilot: възли с приложени (но незапазени) предложения */
    .drawflow .drawflow-node.assistant-proposed {
        outline: 2px dashed #8b5cf6;
        outline-offset: 5px;
        border-radius: 16px;
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
    /* Human-in-the-loop: a node paused awaiting approval glows violet. */
    @keyframes df-paused-pulse {
        0%, 100% { box-shadow: 0 0 0 2px #8b5cf6, 0 14px 32px rgba(139, 92, 246, .20); }
        50%       { box-shadow: 0 0 0 4px #8b5cf6, 0 14px 32px rgba(139, 92, 246, .40); }
    }
    .df-status-paused .df-node-card { animation: df-paused-pulse 1.6s ease-in-out infinite; }

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
    .df-status-paused .df-run-progress > span { background: #8b5cf6; width: 100% !important; }
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
    .df-status-paused .df-run-status-label { color: #5b21b6; }

    .df-run-actions { display: flex; gap: 6px; }
    .df-run-result, .df-run-log, .df-run-test {
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
    .df-run-test:disabled { opacity: 0.45; cursor: not-allowed; }
    .df-run-test:not(:disabled):hover { background: #f8fafc; }

    /* ── Provider/model badge — ALWAYS rendered so card height is identical in
       edit and run modes; only its text/classes change at runtime. ── */
    .df-node-model {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 5px 12px;
        border-top: 1px solid #edf2f7;
        min-height: 26px;
    }
    .df-model-provider {
        font-size: 9px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .06em;
        padding: 2px 6px;
        border-radius: 999px;
        flex: 0 0 auto;
    }
    .df-model-name {
        font-size: 11px;
        color: #475569;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .df-prov-auto      { background: #f1f5f9; color: #64748b; }
    .df-prov-ollama    { background: #d1fae5; color: #065f46; }
    .df-prov-openai    { background: #e0f2fe; color: #075985; }
    .df-prov-anthropic { background: #ffedd5; color: #9a3412; }
    .df-prov-deepseek  { background: #dbeafe; color: #1e40af; }
    .df-prov-gemini    { background: #ccfbf1; color: #0f766e; }
    .df-prov-xai       { background: #e2e8f0; color: #0f172a; }
    .df-prov-qwen      { background: #ede9fe; color: #5b21b6; }

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
    {{-- Хедър, ред 1: навигация + име вляво; Run действието / статус банерите вдясно --}}
    <div class="flex items-center justify-between gap-4 mb-2.5 shrink-0 min-w-0">
        <div class="min-w-0">
            <a href="{{ route('flows.show', $flow) }}" class="text-indigo-600 hover:underline text-sm">← Обратно към flow</a>
            <h1 class="text-xl font-bold text-gray-900 truncate" title="{{ $flow->name }}">
                {{ $flow->name }} <span class="text-gray-300 font-normal">· граф</span>
            </h1>
        </div>

        <div class="shrink-0 flex items-center gap-2">
            {{-- Edit: Run групата е основното действие горе вдясно --}}
            <template x-if="mode === 'edit'">
                <div class="relative" @click.outside="showRunInputs = false">
                    <form :action="runUrl" method="POST" @submit.prevent="await save(); $el.submit()">
                        @csrf
                        <div class="flex items-center gap-1.5">
                            <button type="button" @click="showRunInputs = !showRunInputs"
                                    class="px-2.5 py-2 text-sm rounded-lg border border-gray-300 bg-white text-gray-600 hover:bg-gray-50"
                                    title="Вход за този run (шаблон, тема, параметри)">⚙</button>
                            <button type="submit" class="px-4 py-2 text-sm rounded-lg bg-green-600 text-white hover:bg-green-700 font-semibold shadow-sm">▶ Стартирай</button>
                        </div>
                        <div x-show="showRunInputs" x-cloak
                             class="absolute right-0 mt-2 w-72 bg-white border border-gray-200 rounded-xl shadow-lg p-3 z-30 space-y-2 text-left">
                            <div x-show="versions.length > 1">
                                <label class="block text-xs font-medium text-gray-600 mb-1">Шаблон за този run</label>
                                <select name="version_id" x-model="runVersionId"
                                        class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-green-500">
                                    <template x-for="v in versions" :key="'run-v' + v.id">
                                        <option :value="String(v.id)" :selected="String(runVersionId) === String(v.id)"
                                                x-text="(v.is_active ? '● ' : '') + v.name"></option>
                                    </template>
                                </select>
                                <p x-show="String(runVersionId) !== String(activeVersionId)" class="text-[11px] text-gray-400 mt-1">
                                    Run-ът изпълнява този шаблон, без да сменя активния.
                                </p>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Тема на този run</label>
                                <input type="text" name="inputs[topic]" x-model="runTopic"
                                       placeholder="напр. лазерна епилация"
                                       class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                            </div>
                            {{-- Site flows only: per-run target site, overrides the seed {{url}} --}}
                            <div x-show="flowTargetUrl" x-cloak>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Сайт (URL)</label>
                                <input type="text" name="inputs[url]" x-model="runSiteUrl"
                                       :placeholder="flowTargetUrl"
                                       class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                            </div>
                            <template x-for="f in runInputs" :key="f.key">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1" x-text="f.label || f.key"></label>
                                    <input type="text" :name="'inputs[' + f.key + ']'" :value="f.default || ''"
                                           :placeholder="f.placeholder || ''"
                                           class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                                </div>
                            </template>
                            <p class="text-[11px] text-gray-400">Стойностите заместват placeholder-ите в промптите.</p>
                        </div>
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
            {{-- Human-in-the-loop: a paused human_approval node awaits a decision --}}
            <template x-if="mode === 'run' && runStatus === 'waiting_approval'">
                <div class="flex items-center gap-2 text-sm flex-wrap">
                    <span class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-violet-50 border border-violet-200 text-violet-800 font-semibold">
                        ✋ Чака одобрение<span x-show="pausedApprovalName()" x-text="': ' + pausedApprovalName()"></span>
                    </span>
                    <button type="button" @click="openApprovalInput()"
                            class="px-2.5 py-1.5 rounded-lg border border-violet-200 bg-white text-violet-700 hover:bg-violet-50 text-xs font-semibold">
                        📄 Прегледай материала
                    </button>
                    <input type="text" x-model="approval.comment" placeholder="Коментар (по избор)…"
                           class="w-56 border border-gray-300 rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-violet-500">
                    <button type="button" @click="sendApproval('approve')" :disabled="approval.sending"
                            class="px-3 py-1.5 rounded-lg bg-green-600 hover:bg-green-700 disabled:bg-green-300 text-white text-xs font-bold">
                        ✅ Одобри
                    </button>
                    <button type="button" @click="sendApproval('reject')" :disabled="approval.sending"
                            class="px-3 py-1.5 rounded-lg bg-red-600 hover:bg-red-700 disabled:bg-red-300 text-white text-xs font-bold">
                        ⛔ Отхвърли
                    </button>
                </div>
            </template>
            <template x-if="mode === 'run' && runStatus === 'completed'">
                <div class="flex items-center gap-3 text-sm">
                    <span class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-green-50 border border-green-200 text-green-800 font-semibold">
                        <span class="text-green-600">✓</span> Завършен
                    </span>
                    <template x-if="runCompletedAt">
                        <span class="text-xs text-gray-500"
                              x-text="new Date(runCompletedAt).toLocaleString('bg-BG', { dateStyle: 'short', timeStyle: 'short' })"></span>
                    </template>
                    <template x-if="runCostUsd">
                        <span class="text-xs bg-amber-50 text-amber-700 border border-amber-200 px-2 py-0.5 rounded-full font-medium tabular-nums"
                              title="Общ разход за платени API заявки в този run"
                              x-text="'$' + Number(runCostUsd).toFixed(4)"></span>
                    </template>
                    <button type="button" @click="openFinal()" class="px-3 py-2 rounded-lg bg-green-600 text-white hover:bg-green-700 font-semibold">🏁 Виж резултата</button>
                </div>
            </template>
            <template x-if="mode === 'run' && runStatus === 'failed'">
                <div class="flex items-center gap-2 text-sm flex-wrap">
                    <span class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-red-50 border border-red-200 text-red-800 font-semibold">
                        <span class="text-red-600">✗</span> Неуспешен
                    </span>
                    <template x-if="resumeUrl">
                        <button type="button" @click="resumeRun()"
                                :disabled="resuming"
                                class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-orange-600 hover:bg-orange-700 disabled:bg-orange-300 text-white font-semibold transition">
                            <span x-show="resuming" class="inline-block w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
                            <span x-text="resuming ? 'Подновява...' : '▶ Продължи от грешката'"></span>
                        </button>
                    </template>
                </div>
            </template>

            {{-- Historical view banner --}}
            <template x-if="mode === 'view'">
                <div class="flex items-center gap-3 text-sm flex-wrap">
                    <span class="px-2.5 py-1 rounded-lg bg-gray-100 text-gray-600 font-semibold">🕓 Преглед на изпълнение (read-only)</span>
                    <template x-if="runCompletedAt">
                        <span class="text-xs text-gray-500"
                              x-text="new Date(runCompletedAt).toLocaleString('bg-BG', { dateStyle: 'short', timeStyle: 'short' })"></span>
                    </template>
                    <template x-if="runCostUsd">
                        <span class="text-xs bg-amber-50 text-amber-700 border border-amber-200 px-2 py-0.5 rounded-full font-medium tabular-nums"
                              title="Общ разход за платени API заявки в този run"
                              x-text="'$' + Number(runCostUsd).toFixed(4)"></span>
                    </template>
                    <button type="button" @click="openFinal()" class="px-3 py-2 rounded-lg border border-gray-300 bg-white text-gray-700 hover:bg-gray-50">Финален резултат</button>
                    <template x-if="resumeUrl">
                        <button type="button" @click="resumeRun()"
                                :disabled="resuming"
                                class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-orange-600 hover:bg-orange-700 disabled:bg-orange-300 text-white font-semibold transition">
                            <span x-show="resuming" class="inline-block w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
                            <span x-text="resuming ? 'Подновява...' : '▶ Продължи от грешката'"></span>
                        </button>
                    </template>
                    <a href="{{ route('flows.builder', $flow) }}" class="px-3 py-2 rounded-lg bg-gray-900 text-white hover:bg-gray-800">✎ Редактирай</a>
                </div>
            </template>
        </div>
    </div>

    {{-- Хедър, ред 2 (само edit): toolbar — Шаблон | Изграждане | Лог ‖ Статус + Запис --}}
    <template x-if="mode === 'edit'">
        <div class="flex flex-wrap items-center gap-2 mb-3 shrink-0 bg-white border border-gray-200 rounded-xl px-2.5 py-2 shadow-sm">
            {{-- Кой шаблон се редактира --}}
            <div x-show="versions.length" class="flex items-center gap-1.5" title="Шаблон, който се редактира. „Стартирай“ изпълнява него; активният (●) е по подразбиране за webhook и планирани изпълнения.">
                <span class="text-xs text-gray-400 font-medium pl-1">Шаблон:</span>
                <select x-model="selectedVersionId" @change="switchVersion()"
                        class="border border-gray-300 rounded-lg px-2 py-1.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 max-w-[220px]">
                    <template x-for="v in versions" :key="v.id">
                        <option :value="String(v.id)" :selected="String(selectedVersionId) === String(v.id)"
                                x-text="(v.is_active ? '● ' : '') + v.name"></option>
                    </template>
                </select>
            </div>
            {{-- Ниво на runtime моделите на агентите в този шаблон --}}
            <div class="flex items-center gap-1.5">
                <span class="relative flex items-center gap-1 group">
                    <span class="text-xs text-gray-500 font-medium select-none">Ниво</span>
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 text-gray-400 cursor-help" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-3a1 1 0 00-.867.5 1 1 0 11-1.731-1A3 3 0 0113 8a3.001 3.001 0 01-2 2.83V11a1 1 0 11-2 0v-1a1 1 0 011-1 1 1 0 100-2zm0 8a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/>
                    </svg>
                    <span class="pointer-events-none absolute top-full left-0 mt-2 w-64 rounded-lg bg-gray-800 text-white text-xs px-3 py-2 opacity-0 group-hover:opacity-100 transition-opacity z-50 shadow-lg leading-relaxed">
                        Ниво на runtime моделите на агентите в шаблона.<br>
                        Смяната преизчислява кой провайдър/модел се ползва за всеки агент и показва приблизителен разход преди запис.
                    </span>
                </span>
                <select x-model="levelSelect" @change="onLevelSelect()"
                        class="border border-gray-300 rounded-lg px-2 py-1.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <option value="" disabled x-show="!isStandardLevel(modelLevel)">— смени ниво —</option>
                    <option value="low">🪙 Ниско</option>
                    <option value="medium">⚖️ Средно</option>
                    <option value="high">🚀 Високо</option>
                    <option value="ultra">💎 Ултра</option>
                    <option value="god">👑 GOD</option>
                </select>
            </div>
            <div x-show="versions.length" class="h-6 w-px bg-gray-200"></div>

            {{-- Изграждане на графа --}}
            <button @click="openGenConfig()" type="button" class="px-3 py-1.5 text-sm rounded-lg bg-violet-600 text-white hover:bg-violet-700 font-semibold">
                ✨ Генериране на агенти
            </button>
            <button @click="openAgentPicker()" type="button" class="px-3 py-1.5 text-sm rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 font-semibold">
                ＋ Добави агент
            </button>
            <div class="h-6 w-px bg-gray-200"></div>

            <button @click="openMemoryPanel()" type="button" class="px-2.5 py-1.5 text-sm rounded-lg text-gray-500 hover:text-gray-700 hover:bg-gray-50" title="Памет на flow-а — какво е създадено в предишни изпълнения + поуки per агент">
                🧠 Памет
            </button>

            <button @click="openGenLog()" type="button" class="px-2.5 py-1.5 text-sm rounded-lg text-gray-500 hover:text-gray-700 hover:bg-gray-50" title="Пълен лог на генерирането на агенти">
                📋 Лог
            </button>

            <div class="flex-1"></div>

            {{-- Статус на записа + проверка/запис --}}
            <span x-show="saving" class="text-xs text-gray-500" x-cloak>Запазване…</span>
            <span x-show="saveError" class="text-xs text-red-600 max-w-[260px] truncate" x-cloak x-text="saveError" :title="saveError"></span>
            <span x-show="savedAt && !saving && !saveError" class="text-xs text-green-600" x-cloak x-text="'✓ Запазено ' + savedAt"></span>
            <button @click="validate()" type="button" class="px-3 py-1.5 text-sm rounded-lg border border-gray-300 bg-white text-gray-700 hover:bg-gray-50">Валидирай</button>
            <button @click="save()" type="button" class="px-4 py-1.5 text-sm rounded-lg bg-gray-900 text-white hover:bg-gray-800 font-semibold">💾 Запис</button>
        </div>
    </template>

    {{-- Stall warning: worker not running --}}
    <div x-show="stalledRun" x-cloak
         class="mb-3 shrink-0 bg-amber-50 border border-amber-200 text-amber-800 text-sm px-4 py-2.5 rounded-lg flex items-center gap-2">
        <span class="text-amber-500 text-base">⚠️</span>
        <span><strong>Изпълнението виси.</strong> Провери дали
            <code class="bg-amber-100 px-1 rounded font-mono text-xs">composer dev</code> или
            <code class="bg-amber-100 px-1 rounded font-mono text-xs">php artisan horizon</code>
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
        @include('flows.partials.assistant-panel')
        <div class="hidden absolute left-4 bottom-4 rounded-xl bg-white/90 backdrop-blur border border-gray-200 px-3 py-2 text-xs text-gray-500 shadow-sm">
            Свържи син изход към зелен вход. “Контекст” означава междинен резултат, който се подава към следващи агенти, но не влиза директно във финалния output.
        </div>
    </div>

    {{-- Generation Config Modal: с кой провайдър/модел да се планира --}}
    <div x-show="genCfg.open" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center p-4"
         @keydown.escape.window="genCfg.open = false">
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" @click="genCfg.open = false"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[88vh] flex flex-col" @click.stop>
            <div class="px-6 py-4 border-b border-gray-100 shrink-0">
                <h3 class="text-lg font-bold text-gray-900">✨ Генериране на агенти</h3>
                <p class="text-xs text-gray-400 mt-0.5">Избери кой LLM да проектира pipeline-а — един провайдър за всичко или хибрид по фази.</p>
            </div>
            <div class="p-6 overflow-y-auto space-y-4">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Провайдър</label>
                        {{-- :selected — опциите от x-for се щамповат след x-model
                             bind-а; без него селектът визуално пада на първата опция. --}}
                        <select x-model="genCfg.provider" @change="genCfgProviderChanged()"
                                class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-violet-500">
                            <template x-for="p in plannerProviders" :key="p">
                                <option :value="p" :selected="genCfg.provider === p" :disabled="!plannerAvailability[p]"
                                        x-text="picker.providerLabel(p) + (plannerAvailability[p] ? '' : ' — недостъпен')"></option>
                            </template>
                            <option value="hybrid" :selected="genCfg.provider === 'hybrid'">🧪 Хибрид (различен модел за всяка фаза)</option>
                        </select>
                    </div>
                    <div x-show="genCfg.provider !== 'hybrid'">
                        <label class="block text-xs font-medium text-gray-600 mb-1">Модел</label>
                        <select x-model="genCfg.model" @change="syncPickerFromSingle()"
                                class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-violet-500">
                            <template x-for="m in genCfgModels()" :key="m.value">
                                <option :value="m.value" :selected="genCfg.model === m.value"
                                        :title="m.title || ''" x-text="m.label"></option>
                            </template>
                        </select>
                        <p class="text-[11px] text-gray-400 mt-1"
                           x-show="picker.singleModelHint(genCfg.provider, genCfg.model)"
                           x-text="picker.singleModelHint(genCfg.provider, genCfg.model)"></p>
                    </div>
                </div>

                {{-- Ниво на разходите за runtime моделите на САМИТЕ агенти
                     (отделно от планер фазите по-долу). --}}
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Ниво на моделите за агентите</label>
                    <div class="grid grid-cols-4 gap-2">
                        <template x-for="lv in modelLevels" :key="lv.value">
                            <button type="button" @click="genCfg.level = lv.value"
                                    class="rounded-lg border px-2 py-1.5 text-xs font-semibold transition"
                                    :class="genCfg.level === lv.value ? 'border-violet-400 bg-violet-50 text-violet-800' : 'border-gray-200 text-gray-600 hover:bg-gray-50'">
                                <span x-text="lv.icon + ' ' + lv.label"></span>
                            </button>
                        </template>
                    </div>
                    <p class="text-[11px] text-gray-400 mt-1" x-text="modelLevelHint()"></p>
                </div>

                {{-- Един провайдър: компактна цена. Хибрид: пълният per-phase picker. --}}
                <template x-if="genCfg.provider !== 'hybrid'">
                    <div class="flex items-center justify-between rounded-xl bg-violet-50 border border-violet-200 px-4 py-3">
                        <div class="text-sm font-semibold text-violet-900">Приблизителна цена на генерацията</div>
                        <div class="text-sm font-bold tabular-nums"
                             :class="picker.totalCost() > 0 ? 'text-amber-700' : 'text-green-700'"
                             x-text="picker.totalCostLabel()"></div>
                    </div>
                </template>
                <div x-show="genCfg.provider === 'hybrid'">
                    @include('flows.partials.phase-picker')
                </div>
            </div>
            <div class="px-6 py-4 border-t border-gray-100 flex justify-end gap-2 shrink-0">
                <button type="button" @click="genCfg.open = false" class="px-3 py-2 rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50 text-sm">Отказ</button>
                <button type="button" @click="confirmGenConfig()" class="px-4 py-2 rounded-lg bg-violet-600 text-white hover:bg-violet-700 text-sm font-bold">
                    ✨ Генерирай
                </button>
            </div>
        </div>
    </div>

    {{-- Смяна на нивото на моделите: preview на новите модели + приблизителен разход --}}
    <div x-show="relevel.open" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center p-4"
         @keydown.escape.window="closeRelevel()">
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" @click="closeRelevel()"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[88vh] flex flex-col" @click.stop>
            <div class="px-6 py-4 border-b border-gray-100 shrink-0">
                <h3 class="text-lg font-bold text-gray-900">
                    Смяна на нивото на моделите →
                    <span class="text-sm font-bold px-2 py-1 rounded-full border align-middle"
                          :class="levelMeta(relevel.level).cls"
                          x-text="levelMeta(relevel.level).icon + ' ' + levelMeta(relevel.level).label"></span>
                </h3>
                <p class="text-xs text-gray-400 mt-0.5">Всеки агент получава нов модел според типа и задачите си. Прегледай разхода преди запис.</p>
            </div>
            <div class="p-6 overflow-y-auto">
                <template x-if="relevel.loading">
                    <div class="flex items-center gap-2 text-sm text-gray-500 py-6 justify-center">
                        <span class="inline-block w-4 h-4 border-2 border-violet-500 border-t-transparent rounded-full animate-spin"></span>
                        Изчислявам новите модели и разхода…
                    </div>
                </template>
                <template x-if="relevel.error">
                    <div class="bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-2.5 rounded-lg mb-3" x-text="relevel.error"></div>
                </template>
                <template x-if="!relevel.loading && !relevel.error">
                    <div>
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-[11px] uppercase tracking-wider text-gray-400 border-b border-gray-100">
                                    <th class="text-left py-1.5 pr-2 font-semibold">Агент</th>
                                    <th class="text-left py-1.5 pr-2 font-semibold">Нов модел</th>
                                    <th class="text-right py-1.5 font-semibold">~Разход / run</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="n in relevel.nodes" :key="n.key">
                                    <tr class="border-b border-gray-50">
                                        <td class="py-1.5 pr-2 text-gray-800 truncate max-w-[220px]" x-text="n.name"></td>
                                        <td class="py-1.5 pr-2">
                                            <div class="font-mono text-xs"
                                                 :class="n.new_model ? 'text-violet-700' : 'text-gray-500'"
                                                 x-text="n.display_model + (n.new_model ? '' : ' (локален)')"></div>
                                            <div class="text-[10px] text-gray-400 leading-snug" x-show="n.reason" x-text="n.reason"></div>
                                        </td>
                                        <td class="py-1.5 text-right tabular-nums align-top"
                                            :class="n.est_cost > 0 ? 'text-amber-700' : 'text-green-700'"
                                            x-text="n.est_cost > 0 ? '$' + n.est_cost.toFixed(4) : 'безплатно'"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                        <div class="flex items-center justify-between rounded-xl bg-violet-50 border border-violet-200 px-4 py-3 mt-4">
                            <div class="text-sm font-semibold text-violet-900">
                                Приблизителен разход на едно изпълнение
                                <span class="block text-[11px] font-normal text-violet-700/70"
                                      x-text="relevel.basis === 'last_run' ? 'на база реалните токени от последния успешен run' : 'на база допускания (~6K input токена на агент) — груба оценка'"></span>
                            </div>
                            <div class="text-base font-bold tabular-nums"
                                 :class="relevel.total > 0 ? 'text-amber-700' : 'text-green-700'"
                                 x-text="relevel.total > 0 ? '$' + relevel.total.toFixed(4) : 'безплатно'"></div>
                        </div>
                    </div>
                </template>
            </div>
            <div class="px-6 py-4 border-t border-gray-100 flex justify-end gap-2 shrink-0">
                <button type="button" @click="closeRelevel()" class="px-3 py-2 rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50 text-sm">Отказ</button>
                <button type="button" @click="applyRelevel()" :disabled="relevel.loading || relevel.applying || !!relevel.error"
                        class="px-4 py-2 rounded-lg bg-violet-600 text-white hover:bg-violet-700 disabled:bg-violet-300 text-sm font-bold">
                    <span x-text="relevel.applying ? 'Запазва…' : '✓ Приложи и запиши'"></span>
                </button>
            </div>
        </div>
    </div>

    {{-- Save-as-template dialog (след успешна генерация) --}}
    <div x-show="saveDlg.open" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md" @click.stop>
            <div class="px-6 py-4 border-b border-gray-100">
                <h3 class="text-lg font-bold text-gray-900">💾 Запазване на генерирания план</h3>
                <p class="text-xs text-gray-400 mt-0.5">Агентите са изградени в графа. Как да ги запазим?</p>
            </div>
            <div class="p-6 space-y-3 text-sm">
                <label class="flex items-start gap-2.5 border rounded-xl p-3 cursor-pointer"
                       :class="saveDlg.mode === 'new' ? 'border-violet-400 bg-violet-50/60' : 'border-gray-200'">
                    <input type="radio" value="new" x-model="saveDlg.mode" class="mt-0.5">
                    <span>
                        <span class="font-semibold text-gray-900 block">Запази като нов шаблон</span>
                        <span class="text-xs text-gray-500">Текущият шаблон остава непокътнат.</span>
                    </span>
                </label>
                <label class="flex items-start gap-2.5 border rounded-xl p-3 cursor-pointer"
                       :class="saveDlg.mode === 'overwrite' ? 'border-violet-400 bg-violet-50/60' : 'border-gray-200'"
                       x-show="selectedVersionId">
                    <input type="radio" value="overwrite" x-model="saveDlg.mode" class="mt-0.5">
                    <span>
                        <span class="font-semibold text-gray-900 block">Презапиши текущия шаблон</span>
                        <span class="text-xs text-gray-500" x-text="'„' + (selectedVersionName() || '—') + '“ ще получи новия план.'"></span>
                    </span>
                </label>

                <div x-show="saveDlg.mode === 'new'" class="space-y-2.5 pt-1">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Име на шаблона</label>
                        <input type="text" x-model="saveDlg.name"
                               class="w-full border border-gray-300 rounded-lg px-2.5 py-2 focus:outline-none focus:ring-2 focus:ring-violet-500">
                    </div>
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" x-model="saveDlg.isActive" class="rounded">
                        Направи го активен (по подразбиране за webhook и планирани изпълнения)
                    </label>
                </div>

                <p x-show="saveDlg.error" class="text-xs text-red-600" x-text="saveDlg.error"></p>
            </div>
            <div class="px-6 py-4 border-t border-gray-100 flex justify-end gap-2">
                <button type="button" @click="saveDlg.open = false" class="px-3 py-2 rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50 text-sm">По-късно</button>
                <button type="button" @click="confirmSaveDialog()" :disabled="saveDlg.saving"
                        class="px-4 py-2 rounded-lg bg-violet-600 text-white hover:bg-violet-700 disabled:opacity-50 text-sm font-bold">
                    <span x-show="!saveDlg.saving">💾 Запази</span>
                    <span x-show="saveDlg.saving" class="animate-pulse">Запазване…</span>
                </button>
            </div>
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
                    <button type="button" @click="startGeneration(gen.autoSave, gen.phases, gen.level)" class="px-3 py-1.5 rounded-lg bg-violet-600 text-white hover:bg-violet-700 text-xs">Опитай пак</button>
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
                    <template x-if="logModal.meta.cost">
                        <div class="bg-gray-50 rounded-lg px-3 py-2">
                            <span class="text-gray-400">Цена:</span>
                            <span class="font-semibold text-emerald-700"
                                  x-text="'$' + Number(logModal.meta.cost).toFixed(4)"></span>
                        </div>
                    </template>
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
                <div x-show="logModal.output" x-cloak>
                    <p class="text-xs font-semibold text-gray-500 mb-1">Изход (резултат)</p>
                    <pre class="whitespace-pre-wrap break-words text-xs text-gray-800 bg-gray-900/5 rounded-lg p-3 max-h-80 overflow-y-auto" x-text="logModal.output"></pre>
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

    {{-- Тест на агент Modal: ad-hoc experiments on a finished node (nothing persisted) --}}
    <div x-show="testModal.open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4"
         @keydown.escape.window="testModal.open = false">
        <div class="absolute inset-0 bg-black/50" @click="testModal.open = false"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-7xl h-[92vh] flex flex-col" @click.stop>
            {{-- Header: agent + original run meta --}}
            <div class="px-6 py-4 border-b border-gray-100 flex items-start justify-between gap-4 shrink-0">
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600">🧪 Тест на агент — експерименти без запис</p>
                    <h3 class="text-lg font-bold text-gray-900 truncate" x-text="testModal.nodeName"></h3>
                    <div class="flex flex-wrap items-center gap-2 mt-1 text-xs text-gray-500">
                        <span x-text="typeLabel(testModal.nodeType)"></span>
                        <template x-if="testModal.original">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="px-2 py-0.5 rounded-full bg-gray-100 font-mono" x-text="testModal.original.model || 'авто'"></span>
                                <span x-show="testModal.original.duration_ms" x-text="(Math.round(testModal.original.duration_ms / 100) / 10) + ' сек'"></span>
                                <span x-show="testModal.original.tokens_used" x-text="testModal.original.tokens_used + ' токена'"></span>
                                <span x-show="testModal.original.cost_usd" x-text="'$' + Number(testModal.original.cost_usd).toFixed(4)"></span>
                            </span>
                        </template>
                    </div>
                </div>
                <button @click="testModal.open = false" type="button" class="text-gray-400 hover:text-gray-600 text-xl leading-none">✕</button>
            </div>

            {{-- Original input (collapsible) --}}
            <div class="px-6 py-2 border-b border-gray-100 bg-gray-50/60 shrink-0">
                <button type="button" @click="testModal.inputOpen = !testModal.inputOpen"
                        class="text-xs font-semibold text-gray-600 hover:text-gray-900 flex items-center gap-1">
                    <span x-text="testModal.inputOpen ? '▾' : '▸'"></span>
                    <span>Оригинален вход (какво получи агентът)</span>
                    <span class="text-gray-400 font-normal"
                          x-text="testModal.original ? '· ' + (testModal.original.user_message || '').length + ' знака' : ''"></span>
                </button>
                <pre x-show="testModal.inputOpen" x-cloak
                     class="mt-2 mb-1 text-xs text-gray-700 whitespace-pre-wrap bg-white border border-gray-200 rounded-lg p-3 max-h-48 overflow-y-auto"
                     x-text="testModal.original?.user_message || '—'"></pre>
            </div>

            {{-- Body: left = experiment, right = original output --}}
            <div class="flex-1 min-h-0 grid grid-cols-1 lg:grid-cols-2 divide-y lg:divide-y-0 lg:divide-x divide-gray-200">
                {{-- LEFT: experiment panel --}}
                <div class="min-h-0 overflow-y-auto p-5 space-y-4">
                    <p x-show="testModal.loading" class="text-sm text-gray-400">Зареждане на данните от run-а…</p>

                    <div class="space-y-3" x-show="!testModal.loading">
                        <div class="flex items-center justify-between">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Промптове (само за теста)</p>
                            <button type="button" @click="resetTestForm()"
                                    class="text-xs text-indigo-600 hover:text-indigo-800 font-medium">↺ Възстанови оригинала</button>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">System промпт</label>
                            <textarea x-model="testModal.form.system_prompt" rows="5"
                                      class="w-full border border-gray-300 rounded-lg px-3 py-2 text-xs font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Потребителско съобщение (вход)</label>
                            <textarea x-model="testModal.form.user_message" rows="7"
                                      class="w-full border border-gray-300 rounded-lg px-3 py-2 text-xs font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
                            <p class="text-[11px] text-gray-400 mt-1">Промените тук важат само за теста — шаблонът на промпта в агента не се променя при „Приложи“.</p>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Провайдър</label>
                                <select x-model="testModal.form.provider" @change="testProviderChanged()"
                                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                    <template x-for="p in testProviders()" :key="p.key">
                                        <option :value="p.key" :disabled="!p.available"
                                                x-text="p.label + (p.available ? '' : ' — недостъпен')"></option>
                                    </template>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Модел</label>
                                <select x-model="testModal.form.model"
                                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                    <template x-for="m in testModelOptions()" :key="m.value">
                                        <option :value="m.value" :title="m.title" x-text="m.label"></option>
                                    </template>
                                </select>
                            </div>
                        </div>
                        <p class="text-xs text-gray-500 -mt-1 min-h-[16px]" x-text="testModelHint()"></p>

                        <details class="text-sm">
                            <summary class="text-xs font-semibold text-gray-500 cursor-pointer select-none">Разширени настройки</summary>
                            <div class="grid grid-cols-2 gap-3 mt-2">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Temperature</label>
                                    <input type="number" step="0.1" min="0" max="2" x-model="testModal.form.temperature" placeholder="—"
                                           class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1">Max токени (num_predict)</label>
                                    <input type="number" step="1" min="-1" x-model="testModal.form.num_predict" placeholder="—"
                                           class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                </div>
                            </div>
                        </details>

                        <div class="flex items-center gap-3">
                            <button type="button" @click="runTest()" :disabled="testModal.running || !testModal.form.model"
                                    class="bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-semibold px-5 py-2 rounded-lg transition">
                                ⚡ Генерирай
                            </button>
                            <span class="text-xs text-gray-500" x-text="'Очаквана цена: ' + (testCostEstimate() || '—')"></span>
                        </div>

                        <div x-show="testModal.running" x-cloak
                             class="flex items-center gap-3 text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                            <span class="inline-block w-3 h-3 rounded-full bg-amber-500 animate-pulse shrink-0"></span>
                            <span>Генериране… <span class="font-mono" x-text="testModal.elapsed + ' сек'"></span></span>
                            <span class="text-xs text-amber-600/80">Локалните модели може да отнемат минути.</span>
                        </div>

                        <div x-show="testModal.error" x-cloak
                             class="text-xs text-red-700 bg-red-50 border border-red-200 rounded-lg p-3 whitespace-pre-wrap"
                             x-text="testModal.error"></div>

                        {{-- Attempt history (session-only) --}}
                        <div x-show="(testAttempts[testModal.nodeKey] || []).length" class="space-y-1.5">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Опити в тази сесия</p>
                            <template x-for="(a, i) in (testAttempts[testModal.nodeKey] || [])" :key="i">
                                <button type="button" @click="testModal.activeAttempt = i"
                                        class="w-full flex items-center gap-2 text-left text-xs px-2.5 py-1.5 rounded-lg border transition"
                                        :class="testModal.activeAttempt === i ? 'border-indigo-300 bg-indigo-50' : 'border-gray-200 bg-white hover:bg-gray-50'">
                                    <span class="font-bold" :class="a.status === 'completed' ? 'text-green-600' : 'text-red-500'"
                                          x-text="a.status === 'completed' ? '✓' : '✗'"></span>
                                    <span class="df-model-provider" :class="'df-prov-' + (a.provider || 'ollama')" x-text="a.provider"></span>
                                    <span class="font-mono truncate" x-text="a.model.includes('/') ? a.model.split('/').slice(1).join('/') : a.model"></span>
                                    <span class="ml-auto text-gray-400 shrink-0"
                                          x-text="[a.at, a.duration_ms ? (Math.round(a.duration_ms / 100) / 10) + 'с' : null, a.tokens_used ? a.tokens_used + ' ток.' : null, a.cost_usd ? '$' + Number(a.cost_usd).toFixed(4) : null].filter(Boolean).join(' · ')"></span>
                                </button>
                            </template>
                        </div>

                        {{-- Active attempt output --}}
                        <template x-if="activeTestAttempt()">
                            <div class="border border-indigo-200 rounded-xl overflow-hidden">
                                <div class="px-4 py-2 bg-indigo-50/60 flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-xs font-bold text-indigo-700">Нов резултат — <span class="font-mono" x-text="activeTestAttempt().model"></span></p>
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs text-emerald-700 font-semibold" x-show="testModal.appliedNotice" x-text="testModal.appliedNotice"></span>
                                        <button type="button" @click="applyAttempt(testModal.activeAttempt)"
                                                x-show="activeTestAttempt().status === 'completed'" :disabled="testModal.applying"
                                                title="Записва модела (и системния промпт, ако е редактиран) в агента на текущия flow"
                                                class="bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white text-xs font-semibold px-3 py-1.5 rounded-lg transition">
                                            ✅ Приложи в агента
                                        </button>
                                    </div>
                                </div>
                                <div class="p-4">
                                    <div x-show="activeTestAttempt().error"
                                         class="text-xs text-red-700 bg-red-50 border border-red-200 rounded-lg p-3 whitespace-pre-wrap"
                                         x-text="activeTestAttempt().error"></div>
                                    <div x-show="activeTestAttempt().output"
                                         class="md-output text-sm text-gray-800 leading-relaxed"
                                         x-html="renderMd(activeTestAttempt().output)"></div>
                                    {{-- Thinking model burned the whole budget inside <think> --}}
                                    <div x-show="!activeTestAttempt().output && !activeTestAttempt().error && activeTestAttempt().raw_output" x-cloak>
                                        <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg p-2 mb-2">
                                            Моделът върна само вътрешен reasoning (&lt;think&gt;) — увеличи „Max токени“ и опитай пак. Суров отговор:
                                        </p>
                                        <pre class="text-xs text-gray-500 whitespace-pre-wrap bg-gray-50 border border-gray-200 rounded-lg p-3 max-h-60 overflow-y-auto"
                                             x-text="activeTestAttempt().raw_output"></pre>
                                    </div>
                                    <p x-show="!activeTestAttempt().output && !activeTestAttempt().error && !activeTestAttempt().raw_output"
                                       class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg p-2">
                                        Празен отговор — при thinking модели (qwen3, deepseek-r1) целият бюджет може да отиде за вътрешен reasoning. Увеличи „Max токени“ в Разширени настройки и опитай пак.
                                    </p>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- RIGHT: original output (the comparison reference) --}}
                <div class="min-h-0 overflow-y-auto p-5 bg-gray-50/50">
                    <div class="flex items-center justify-between mb-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Оригинален резултат от run-а</p>
                        <span class="text-xs text-gray-400 font-mono" x-text="testModal.original?.model || ''"></span>
                    </div>
                    <template x-if="testModal.original && testModal.original.error && !testModal.original.output">
                        <div class="text-xs text-red-700 bg-red-50 border border-red-200 rounded-lg p-3 whitespace-pre-wrap"
                             x-text="testModal.original.error"></div>
                    </template>
                    <div x-show="testModal.original?.output"
                         class="md-output text-sm text-gray-800 leading-relaxed"
                         x-html="renderMd(testModal.original?.output)"></div>
                    <p x-show="testModal.original && !testModal.original.output && !testModal.original.error"
                       class="text-sm text-gray-400">Няма запазен изход.</p>
                </div>
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

                <template x-for="group in genLogModal.logs" :key="group.id">
                    <div class="border border-gray-200 rounded-xl overflow-hidden">
                        <div class="px-4 py-3 bg-gray-50 flex items-center justify-between cursor-pointer" @click="group._expanded = !group._expanded">
                            <div class="flex items-center gap-3 text-sm">
                                <span class="font-semibold text-gray-900" x-text="group.created_at"></span>
                                <span class="text-xs px-2 py-0.5 rounded-full"
                                      :class="group.status === 'completed' ? 'bg-green-100 text-green-700' : (group.status === 'failed' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700')"
                                      x-text="group.status"></span>
                                <span class="text-xs text-gray-500" x-text="(group.provider || '—') + ' · ' + (group.model || '—')"></span>
                                <span class="text-xs text-gray-400" x-text="(group.parsed_count ?? '—') + ' агента'"></span>
                                <span class="text-xs font-semibold text-emerald-700" x-text="group.cost_usd != null ? '$' + Number(group.cost_usd).toFixed(4) : '—'"></span>
                                <span class="text-xs text-gray-400" x-text="group.duration_ms ? (Math.round(group.duration_ms/100)/10 + ' сек') : ''"></span>
                            </div>
                            <span class="text-gray-400 text-xs" x-text="group._expanded ? '▲' : '▼'"></span>
                        </div>
                        <div x-show="group._expanded" x-cloak class="p-3 space-y-2">
                            <template x-for="phase in group.phases" :key="phase.id">
                                <div class="border border-gray-100 rounded-lg overflow-hidden">
                                    <div class="px-3 py-2 flex items-center justify-between cursor-pointer hover:bg-gray-50" @click="phase._expanded = !phase._expanded">
                                        <div class="flex items-center gap-3 text-xs">
                                            <span class="px-2 py-0.5 rounded-full"
                                                  :class="phase.status === 'completed' ? 'bg-green-100 text-green-700' : (phase.status === 'failed' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700')"
                                                  x-text="phase.status"></span>
                                            <span class="text-gray-600 font-medium" x-text="(phase.provider || '—') + ' · ' + (phase.model || '—')"></span>
                                            <span class="text-gray-400" x-text="(phase.parsed_count ?? '—') + ' агента'"></span>
                                            <span class="font-semibold text-emerald-700" x-text="phase.cost_usd != null ? '$' + Number(phase.cost_usd).toFixed(4) : '—'"></span>
                                            <span class="text-gray-400" x-text="phase.duration_ms ? (Math.round(phase.duration_ms/100)/10 + ' сек') : ''"></span>
                                        </div>
                                        <span class="text-gray-400 text-xs" x-text="phase._expanded ? '▲' : '▼'"></span>
                                    </div>
                                    <div x-show="phase._expanded" x-cloak class="p-4 space-y-3 border-t border-gray-100">
                                        <div class="grid grid-cols-3 gap-2 text-xs">
                                            <div class="bg-gray-50 rounded-lg px-3 py-2"><span class="text-gray-400">Провайдър:</span> <span class="font-semibold" x-text="phase.provider || '—'"></span></div>
                                            <div class="bg-gray-50 rounded-lg px-3 py-2"><span class="text-gray-400">Модел:</span> <span class="font-semibold" x-text="phase.model || '—'"></span></div>
                                            <div class="bg-gray-50 rounded-lg px-3 py-2"><span class="text-gray-400">Времетраене:</span> <span class="font-semibold" x-text="phase.duration_ms ? (Math.round(phase.duration_ms/100)/10 + ' сек') : '—'"></span></div>
                                            <div class="bg-gray-50 rounded-lg px-3 py-2"><span class="text-gray-400">Цена:</span> <span class="font-semibold" x-text="phase.cost_usd != null ? '$' + Number(phase.cost_usd).toFixed(4) : '—'"></span></div>
                                            <div class="bg-gray-50 rounded-lg px-3 py-2"><span class="text-gray-400">Токени (вход / изход):</span> <span class="font-semibold" x-text="(phase.prompt_tokens ?? '—') + ' / ' + (phase.completion_tokens ?? '—')"></span></div>
                                            <div class="bg-gray-50 rounded-lg px-3 py-2"><span class="text-gray-400">Час:</span> <span class="font-semibold" x-text="phase.created_at || '—'"></span></div>
                                        </div>
                                        <div x-show="phase.error" x-cloak>
                                            <p class="text-xs font-semibold text-red-600 mb-1">Грешка</p>
                                            <pre class="whitespace-pre-wrap break-words text-xs text-red-700 bg-red-50 rounded-lg p-3" x-text="phase.error"></pre>
                                        </div>
                                        <div>
                                            <p class="text-xs font-semibold text-gray-500 mb-1">Опции (параметри към модела)</p>
                                            <pre class="whitespace-pre-wrap break-words text-xs text-gray-600 bg-gray-50 rounded-lg p-3" x-text="JSON.stringify(phase.options, null, 2)"></pre>
                                        </div>
                                        <div>
                                            <p class="text-xs font-semibold text-gray-500 mb-1">Системен промпт</p>
                                            <pre class="whitespace-pre-wrap break-words text-xs text-gray-600 bg-gray-50 rounded-lg p-3 max-h-64 overflow-y-auto" x-text="phase.system_prompt || '—'"></pre>
                                        </div>
                                        <div>
                                            <p class="text-xs font-semibold text-gray-500 mb-1">Потребителски промпт</p>
                                            <pre class="whitespace-pre-wrap break-words text-xs text-gray-600 bg-gray-50 rounded-lg p-3 max-h-64 overflow-y-auto" x-text="phase.user_message || '—'"></pre>
                                        </div>
                                        <div>
                                            <p class="text-xs font-semibold text-gray-500 mb-1">Пълен суров отговор</p>
                                            <pre class="whitespace-pre-wrap break-words text-xs text-gray-600 bg-gray-900/5 rounded-lg p-3 max-h-80 overflow-y-auto" x-text="phase.raw_response || '—'"></pre>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    {{-- Памет на flow-а Modal --}}
    @include('flows.partials.memory-panel')

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
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-5xl max-h-[92vh] overflow-hidden flex flex-col" @click.stop>
            <div class="px-6 py-5 border-b border-gray-100 flex items-start justify-between gap-4 shrink-0">
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
                <div class="flex-1 min-h-0 flex flex-col">
                    <div class="flex px-6 border-b border-gray-200 gap-1 shrink-0">
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

                    <div class="p-6 overflow-y-auto flex-1 min-h-0">
                        <div x-show="propsTab === 'basic'" class="space-y-5">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Име</label>
                                    <input type="text" x-model="selected.name" :disabled="modalReadOnly || resumeEditing"
                                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:bg-gray-50 disabled:text-gray-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Тип</label>
                                    <select x-model="selected.type" @change="!modalReadOnly && !resumeEditing && onSelectedTypeChanged()" :disabled="modalReadOnly || resumeEditing"
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
                                    <button x-show="!modalReadOnly && !resumeEditing" type="button" @click="generateField('role')"
                                            :disabled="generating.role || !selected.name"
                                            class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 disabled:bg-indigo-300 disabled:cursor-not-allowed text-white text-xs font-semibold px-3 py-1 rounded-lg transition">
                                        <span x-text="generating.role ? '⏳' : '✨'"></span>
                                        <span x-text="generating.role ? 'Генерира...' : 'Генерирай с AI'"></span>
                                    </button>
                                </div>
                                <textarea x-model="selected.role" rows="3" :disabled="modalReadOnly || resumeEditing"
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
                                    <div class="relative" @click.outside="modelPickerOpen = false" @keydown.escape.window="modelPickerOpen = false">
                                        <button type="button" @click="modelPickerOpen = !modelPickerOpen" :disabled="modalReadOnly"
                                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-left flex items-center justify-between gap-2 bg-white hover:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:bg-gray-50 disabled:cursor-not-allowed transition">
                                            <span class="min-w-0">
                                                <span class="block text-sm font-medium text-gray-900 truncate" x-text="currentModelMeta().name"></span>
                                                <span class="block text-xs text-gray-400 truncate" x-text="currentModelMeta().desc"></span>
                                            </span>
                                            <svg class="w-4 h-4 text-gray-400 shrink-0 transition-transform" :class="modelPickerOpen ? 'rotate-180' : ''"
                                                 fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                        </button>

                                        <div x-show="modelPickerOpen" x-cloak x-transition.opacity.duration.100ms
                                             class="absolute z-30 mt-1 w-full bg-white border border-gray-200 rounded-xl shadow-xl max-h-80 overflow-y-auto">

                                            {{-- По подразбиране --}}
                                            <button type="button" @click="pickModel('')"
                                                    class="w-full text-left px-3 py-2.5 hover:bg-indigo-50 transition border-b border-gray-100"
                                                    :class="selected.model === '' ? 'bg-indigo-50' : ''">
                                                <div class="flex items-center justify-between gap-2">
                                                    <span class="text-sm font-medium text-gray-900">⚙ По подразбиране</span>
                                                    <span x-show="selected.model === ''" class="text-indigo-600 text-sm">✓</span>
                                                </div>
                                                <div class="text-xs text-gray-500 mt-0.5">Кодът избира най-подходящия инсталиран локален модел според типа на агента.</div>
                                            </button>

                                            {{-- Препоръчани за типа --}}
                                            <template x-if="recommendedModels(selected.type).length">
                                                <div>
                                                    <div class="px-3 pt-2.5 pb-1 text-[10px] font-semibold uppercase tracking-wider text-amber-600 bg-amber-50/60">★ Препоръчани за този тип</div>
                                                    <template x-for="m in recommendedModels(selected.type)" :key="'rec-' + m.ollama_tag">
                                                        <button type="button" @click="pickModel(m.ollama_tag)"
                                                                class="w-full text-left px-3 py-2.5 hover:bg-indigo-50 transition"
                                                                :class="selected.model === m.ollama_tag ? 'bg-indigo-50' : ''">
                                                            <div class="flex items-center justify-between gap-2">
                                                                <span class="text-sm font-medium text-gray-900 truncate" x-text="'★ ' + (m.display_name || m.ollama_tag)"></span>
                                                                <span x-show="selected.model === m.ollama_tag" class="text-indigo-600 text-sm shrink-0">✓</span>
                                                            </div>
                                                            <div class="text-xs text-gray-500 mt-0.5 line-clamp-2" x-text="m.description || m.category"></div>
                                                        </button>
                                                    </template>
                                                </div>
                                            </template>

                                            {{-- Локални Ollama модели --}}
                                            <template x-if="otherModels(selected.type).length">
                                                <div>
                                                    <div class="px-3 pt-2.5 pb-1 text-[10px] font-semibold uppercase tracking-wider text-gray-400 bg-gray-50/80">Локални (Ollama)</div>
                                                    <template x-for="m in otherModels(selected.type)" :key="'other-' + m.ollama_tag">
                                                        <button type="button" @click="pickModel(m.ollama_tag)"
                                                                class="w-full text-left px-3 py-2.5 hover:bg-indigo-50 transition"
                                                                :class="selected.model === m.ollama_tag ? 'bg-indigo-50' : ''">
                                                            <div class="flex items-center justify-between gap-2">
                                                                <span class="text-sm font-medium text-gray-900 truncate" x-text="m.display_name || m.ollama_tag"></span>
                                                                <span x-show="selected.model === m.ollama_tag" class="text-indigo-600 text-sm shrink-0">✓</span>
                                                            </div>
                                                            <div class="text-xs text-gray-500 mt-0.5 line-clamp-2" x-text="m.description || m.category"></div>
                                                        </button>
                                                    </template>
                                                </div>
                                            </template>

                                            {{-- Платени cloud модели --}}
                                            <template x-if="paidModels.length">
                                                <div>
                                                    <div class="px-3 pt-2.5 pb-1 text-[10px] font-semibold uppercase tracking-wider text-violet-500 bg-violet-50/60">☁ Cloud (платени)</div>
                                                    <template x-for="m in paidModels" :key="'paid-' + m.value">
                                                        <button type="button" @click="pickModel(m.value)"
                                                                class="w-full text-left px-3 py-2.5 hover:bg-violet-50 transition"
                                                                :class="selected.model === m.value ? 'bg-violet-50' : ''">
                                                            <div class="flex items-center justify-between gap-2">
                                                                <span class="text-sm font-medium text-gray-900 truncate" x-text="'☁ ' + m.label"></span>
                                                                <span x-show="selected.model === m.value" class="text-violet-600 text-sm shrink-0">✓</span>
                                                            </div>
                                                            <div class="text-xs text-gray-500 mt-0.5 line-clamp-2" x-text="m.description"></div>
                                                        </button>
                                                    </template>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                    <p class="text-xs text-gray-400 mt-1">Изпълнението на ☁ модели се таксува per token и се вижда като цена в run-а.</p>
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
                                    <select x-model="selected.output_role" :disabled="modalReadOnly || resumeEditing"
                                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                        <option value="">(авто от тип)</option>
                                        <option value="body">Основно съдържание</option>
                                        <option value="appendix">Добавка (хаштагове, SEO)</option>
                                        <option value="hidden">Контекст (междинен, не във финалния output)</option>
                                    </select>
                                    <p class="text-xs text-gray-400 mt-1">Определя къде ще се появи резултатът във финалния output.</p>
                                </div>

                                <div class="flex items-center gap-3 pt-7">
                                    <input type="checkbox" x-model="selected.is_active" :disabled="modalReadOnly || resumeEditing" id="node-is-active"
                                           class="w-4 h-4 text-indigo-600 border-gray-300 rounded">
                                    <label for="node-is-active" class="text-sm font-medium text-gray-700">Активен</label>
                                </div>
                            </div>

                            {{-- Step-QA gate (non-verifier nodes): re-runs the node on low score; --}}
                            {{-- from the 2nd retry the planner revises the agent (Фаза 3). --}}
                            <div x-show="selected.type !== 'qa_verifier'" class="border-t border-gray-100 pt-4">
                                <div class="flex items-center gap-3">
                                    <input type="checkbox" x-model="selected.config.qa.enabled" :disabled="modalReadOnly" id="node-qa-enabled"
                                           class="w-4 h-4 text-indigo-600 border-gray-300 rounded">
                                    <label for="node-qa-enabled" class="text-sm font-medium text-gray-700">QA проверка на този възел (gate с retry)</label>
                                </div>
                                <p class="text-xs text-gray-400 mt-1">При резултат под прага възелът се преизпълнява; от 2-рия опит planner-ът поправя агента (Фаза 3). Верификаторът се създава автоматично от критериите по-долу.</p>
                                <div x-show="selected.config.qa.enabled" class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-3">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">QA праг (%)</label>
                                        <select x-model.number="selected.config.qa.threshold" :disabled="modalReadOnly"
                                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                            <template x-for="threshold in qaThresholdOptions" :key="threshold">
                                                <option :value="threshold" x-text="threshold + '%'"></option>
                                            </template>
                                        </select>
                                    </div>
                                    <div class="md:col-span-2">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">QA критерии</label>
                                        <textarea x-model="selected.config.qa.custom_prompt" :disabled="modalReadOnly" rows="2"
                                                  placeholder="Какво трябва да провери верификаторът за изхода на този възел?"
                                                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
                                    </div>
                                </div>
                            </div>

                            {{-- Decision routing branches (each branch = one output port) --}}
                            <div x-show="selected.type === 'decision'" class="border-t border-gray-100 pt-4">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Клонове на решението</label>
                                <p class="text-xs text-gray-400 mb-3">Всеки клон е отделен изходен порт. Свържи всеки порт със следващия възел за този клон. Агентът избира ЕДИН клон според входа; останалите клонове се пропускат.</p>
                                <template x-for="(br, i) in (selected.config.branches || [])" :key="i">
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="text-[11px] font-mono text-gray-400 w-16 shrink-0" x-text="'output_' + (i+1)"></span>
                                        <input type="text" x-model="br.label" :disabled="modalReadOnly || resumeEditing" placeholder="Етикет"
                                               class="w-32 border border-gray-300 rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                        <input type="text" x-model="br.when" :disabled="modalReadOnly || resumeEditing" placeholder="Кога се избира този клон"
                                               class="flex-1 border border-gray-300 rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                        <button type="button" x-show="!modalReadOnly && !resumeEditing" @click="removeBranch(i)"
                                                class="text-red-500 hover:text-red-700 text-sm px-1 shrink-0" title="Премахни клон">✕</button>
                                    </div>
                                </template>
                                <button type="button" x-show="!modalReadOnly && !resumeEditing" @click="addBranch()"
                                        class="text-xs text-indigo-600 hover:text-indigo-800 font-medium mt-1">+ Добави клон</button>
                                <p class="text-[11px] text-gray-400 mt-2">Изходните портове се синхронизират с клоновете при запазване на възела.</p>
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

                    <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 flex flex-wrap items-center justify-between gap-3 shrink-0">
                        {{-- Read-only: only Close; Edit: Delete + Cancel + Save --}}
                        <template x-if="!modalReadOnly && !resumeEditing">
                            <button type="button" @click="removeSelectedFromModal()"
                                    class="text-sm text-red-600 hover:text-red-700 font-medium">
                                Изтрий възела
                            </button>
                        </template>
                        <template x-if="modalReadOnly && !resumeEditing">
                            <span class="text-xs text-gray-400 italic">Настройките не могат да се редактират в режим на преглед.</span>
                        </template>
                        <template x-if="resumeEditing">
                            <span class="text-xs text-orange-600 font-medium">Редактирай настройките на неуспешния агент, после натисни "Запази и продължи".</span>
                        </template>
                        <div class="flex items-center gap-2">
                            <button type="button" @click="closeNodeModal()"
                                    class="bg-white border border-gray-300 text-gray-600 text-sm px-4 py-2 rounded-lg hover:bg-gray-50 transition"
                                    x-text="modalReadOnly ? 'Затвори' : 'Отказ'">
                            </button>
                            <button x-show="!modalReadOnly && !resumeEditing" type="button" @click="saveNodeModal()"
                                    class="bg-green-600 hover:bg-green-700 text-white text-sm font-semibold px-5 py-2 rounded-lg transition">
                                Запази свойствата
                            </button>
                            <button x-show="resumeEditing" type="button" @click="saveAndResume()"
                                    :disabled="resuming"
                                    class="flex items-center gap-1.5 bg-orange-600 hover:bg-orange-700 disabled:bg-orange-300 text-white text-sm font-semibold px-5 py-2 rounded-lg transition">
                                <span x-show="resuming" class="inline-block w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
                                <span x-text="resuming ? 'Запазва...' : '💾 Запази и продължи'"></span>
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
        paidModels: config.paidModels || [],
        modelPickerOpen: false,
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
        // Per-run inputs: @{{topic}} default + declared custom placeholders.
        runTopic: config.flowTopic || '',
        // Site flows: target URL from the flow description, editable per run
        // (overrides the seed @{{url}}; empty falls back to the description URL).
        flowTargetUrl: config.flowTargetUrl || '',
        runSiteUrl: config.flowTargetUrl || '',
        runInputs: Array.isArray(config.runInputs) ? config.runInputs : [],
        showRunInputs: false,
        mode: config.mode || 'edit',
        saving: false,
        savedAt: null,
        saveError: null,
        validation: null,
        generating: {},

        // ── Шаблони (граф версии) ──
        versions: (config.versions || []).map(v => ({ ...v })),
        selectedVersionId: config.selectedVersionId ? String(config.selectedVersionId) : '',
        activeVersionId: config.activeVersionId ? String(config.activeVersionId) : '',
        // „Стартирай“ изпълнява разглеждания шаблон (без да пипа активния).
        runVersionId: config.selectedVersionId ? String(config.selectedVersionId) : '',
        _editingVersionId: config.selectedVersionId ? String(config.selectedVersionId) : '',
        _baseline: null,

        // ── Generation config (провайдър/модел/хибрид per фаза) ──
        plannerProviders: config.plannerProviders || [],
        plannerAvailability: config.plannerAvailability || {},
        picker: window.plannerPhasePicker(config.plannerDefaults || {}, {
            providers: config.plannerProviders || [],
            availability: config.plannerAvailability || {},
            cloudModels: config.cloudModels || {},
            ollamaModels: config.models || [],
            pricing: config.pricing || {},
        }),
        genCfg: { open: false, provider: 'openai', model: '', level: 'medium' },
        // Ниво на runtime моделите на разглеждания шаблон ('custom' след ръчна
        // смяна на модел; null при стари версии). levelSelect е dropdown mirror.
        modelLevel: config.modelLevel || null,
        levelSelect: ['low', 'medium', 'high', 'ultra', 'god'].includes(config.modelLevel) ? config.modelLevel : '',
        relevel: { open: false, loading: false, applying: false, level: null, nodes: [], total: 0, basis: 'assumptions', error: null },
        // Нива на разходите за runtime моделите на агентите (enforce-ва се в
        // FlowPlannerService::resolveProviderPins; default: medium).
        modelLevels: [
            { value: 'low', icon: '🪙', label: 'Ниско', hint: 'Основно локални Ollama модели; до 3 евтини cloud за критичните стъпки. Безплатно, но бавно (локален GPU).' },
            { value: 'medium', icon: '⚖️', label: 'Средно', hint: 'Повечето агенти на евтини cloud модели (Gemini/DeepSeek/Qwen/Grok), поне 3 остават на Ollama. Балансът по подразбиране.' },
            { value: 'high', icon: '🚀', label: 'Високо', hint: 'Всички агенти на евтини cloud модели; до 3 критични стъпки на OpenAI. Българският текст остава на BgGPT.' },
            { value: 'ultra', icon: '💎', label: 'Ултра', hint: 'Всички агенти на OpenAI (вкл. българското писане); до 2 критични стъпки на Claude. Най-високо качество, най-скъпо.' },
            { value: 'god', icon: '👑', label: 'GOD', hint: 'Всеки агент на най-скъпите флагмани — GPT-4o или Claude Sonnet, task-aware, без лимит. Максимално качество, максимална цена.' },
        ],
        saveDlg: { open: false, mode: 'new', name: '', isActive: true, agents: null, meta: null, saving: false, error: '' },

        // ── Agent generation (DAG) ──
        gen: { active: false, progress: 0, message: '', stage: '', error: null, token: null, autoSave: false, phases: null, level: 'medium', _timer: null, _rot: null, _stageChangedAt: 0, _narratorStage: '', _narratorIndex: 0, _steadyLineShown: false, _lastNarratorDelay: 0 },

        // ── Run/view per-node data + modals ──
        runData: {},          // node_key → { status, output, raw_output, error, model, duration_ms, tokens_used, steps }
        runStatus: null,      // mirrors poll's data.status — null | 'pending' | 'running' | 'waiting_approval' | 'completed' | 'failed'
        approvals: {},        // context['approvals'] from the poll (human-in-the-loop)
        approval: { comment: '', sending: false },
        runCostUsd: null,     // total cost for the viewed run (from poll)
        runCompletedAt: null, // ISO string of run completion (from poll)
        _lastProgress: null,  // latest poll progress payload — read by the run banner before the first poll lands
        stalledRun: false,    // true when poll reports no live flows queue worker
        _pageLoadedAt: Date.now(),
        finalOutput: null,
        resultModal: { open: false, title: '', body: '' },
        logModal: { open: false, title: '', meta: {}, error: '', steps: '', input: '', params: null, systemPrompt: '', output: '' },
        // ── Тест на агент (ad-hoc experiments on a finished node) ──
        testModal: {
            open: false, nodeKey: null, nodeName: '', nodeType: '',
            loading: false,    // fetching nodeDetail on open
            inputOpen: false,  // collapsible original input
            original: null,    // { model, system_prompt, user_message, options, output, error, status, duration_ms, tokens_used, cost_usd }
            form: { provider: 'ollama', model: '', system_prompt: '', user_message: '', temperature: '', num_predict: '' },
            running: false, token: null, startedAt: 0, elapsed: 0, _timer: null,
            error: '',
            activeAttempt: -1, // index into testAttempts[nodeKey]; -1 = none yet
            applying: false, appliedNotice: '',
        },
        // node_key → [{ model, provider, at, duration_ms, tokens_used, cost_usd, output, error, status, system_prompt }]
        // Survives popup close; dies on page reload. Never persisted.
        testAttempts: {},
        finalModal: { open: false, body: '' },
        genLogModal: { open: false, loading: false, logs: [], error: '' },
        memoryPanel: { open: false, loading: false, error: '', enabled: config.memoryEnabled ?? true, tab: 'outputs', outputs: [], lessons: [], clearing: false, toggling: false, search: '', sortCol: 'created_at', sortDir: 'desc', page: 1, pageSize: 15, preview: { open: false, nodeName: '', title: '', body: '' } },

        // ── Асистент (Builder Copilot) ──
        chat: { open: false, loaded: false, messages: [], input: '', sending: false, stage: '', partial: '', session: null },
        chatSuggestions: [
            'Обясни ми какво прави този flow',
            'Оцени настройките на flow-а и кажи какво да подобря',
            'Защо се провали последният run?',
        ],
        _assistantIdMap: {},
        modalReadOnly: false, // true in run/view modes — makes the properties modal display-only
        resumeEditing: false, // true when a failed node's modal is open for editing before resume
        resumeUrl: config.resumeUrl || null,
        failedNodeKeys: config.failedNodeKeys || [],
        viewRunId: config.viewRunId || null,
        resuming: false,
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

            this.setupCanvasNavigation(el);
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

            // Dirty-снимка СЛЕД import + boundary възлите — switchVersion
            // сравнява срещу нея, за да предупреди за незапазени промени.
            this._baseline = JSON.stringify(this.editor.export());

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
                // Fresh flow created via "Запази и генерирай агенти" — plan on
                // the .env defaults; the save bootstraps the "Default" template.
                this.$nextTick(() => this.startGeneration(true));
            } else if (config.newTemplate) {
                // Dashboard "+ Нов шаблон": first pick provider/model/hybrid.
                this.$nextTick(() => this.openGenConfig());
            }
        },

        bindRunClicks(el) {
            el.addEventListener('click', (event) => {
                const result = event.target.closest('.df-run-result');
                const test = event.target.closest('.df-run-test');
                const log = event.target.closest('.df-run-log');
                const final = event.target.closest('.df-final-btn');
                if (!result && !test && !log && !final) return;
                event.preventDefault();
                event.stopPropagation();
                if (final) { this.openFinal(); return; }
                const nodeEl = (result || test || log).closest('.drawflow-node');
                if (!nodeEl) return;
                const key = nodeEl.id.replace('node-', '');
                if (result) this.openResult(key);
                else if (test) { if (!test.disabled) this.openTest(key); }
                else this.openLog(key);
            }, true);
        },

        // Reliable canvas navigation layered on top of Drawflow's flaky,
        // container-bound panning: wheel/trackpad pans (Ctrl+wheel still zooms),
        // and drag-to-pan from anywhere (empty canvas / middle button / Space+drag)
        // with window-level move/up listeners so a gesture can never freeze or
        // get stuck when the pointer leaves the canvas.
        setupCanvasNavigation(el) {
            const editor = this.editor;
            let panning = false;
            let spaceHeld = false;
            let panStartX = 0, panStartY = 0, baseX = 0, baseY = 0;

            const applyPan = (x, y) => {
                editor.canvas_x = x;
                editor.canvas_y = y;
                editor.precanvas.style.transform =
                    `translate(${x}px, ${y}px) scale(${editor.zoom})`;
            };

            // Wheel / two-finger trackpad → pan. Ctrl+wheel falls through to
            // Drawflow's zoom_enter (which only acts on ctrlKey).
            el.addEventListener('wheel', (event) => {
                if (event.ctrlKey) return;
                event.preventDefault();
                let dx = event.deltaX;
                let dy = event.deltaY;
                if (event.shiftKey && dx === 0) { dx = dy; dy = 0; }
                applyPan(editor.canvas_x - dx, editor.canvas_y - dy);
            }, { passive: false });

            // Decide whether a mousedown starts a pan. Capture phase so it
            // pre-empts Drawflow's own (container-bound) panning and node logic.
            el.addEventListener('mousedown', (event) => {
                const t = event.target;
                const onEmptyCanvas = t === editor.precanvas
                    || t.classList.contains('parent-drawflow')
                    || t.classList.contains('drawflow');
                const middle = event.button === 1;
                const spacePan = spaceHeld && event.button === 0;
                const emptyPan = event.button === 0 && onEmptyCanvas;
                if (!middle && !spacePan && !emptyPan) return;

                event.preventDefault();
                event.stopPropagation();
                panning = true;
                panStartX = event.clientX;
                panStartY = event.clientY;
                baseX = editor.canvas_x;
                baseY = editor.canvas_y;
                el.classList.add('df-panning');
            }, true);

            // Move/up live on window: a drag keeps working when the pointer
            // leaves the canvas and always ends when the button is released.
            window.addEventListener('mousemove', (event) => {
                if (!panning) return;
                applyPan(baseX + (event.clientX - panStartX), baseY + (event.clientY - panStartY));
            });

            window.addEventListener('mouseup', (event) => {
                if (!panning) return;
                panning = false;
                el.classList.remove('df-panning');

                // A near-stationary press on empty canvas behaves like a click:
                // mirror Drawflow's deselect so the background clears selection.
                const moved = Math.abs(event.clientX - panStartX) + Math.abs(event.clientY - panStartY);
                if (moved < 3 && editor.node_selected) {
                    editor.node_selected.classList.remove('selected');
                    editor.node_selected = null;
                    editor.dispatch('nodeUnselected', true);
                }
            });

            // Hold Space to pan-drag from anywhere (and show a grab cursor).
            window.addEventListener('keydown', (event) => {
                if (event.code !== 'Space' || spaceHeld) return;
                const a = document.activeElement;
                if (a && (a.tagName === 'INPUT' || a.tagName === 'TEXTAREA' || a.isContentEditable)) return;
                spaceHeld = true;
                el.classList.add('df-space');
                event.preventDefault();
            });
            window.addEventListener('keyup', (event) => {
                if (event.code !== 'Space') return;
                spaceHeld = false;
                el.classList.remove('df-space');
            });

            // Safety: losing focus must never leave a gesture stuck.
            window.addEventListener('blur', () => {
                panning = false;
                spaceHeld = false;
                editor.editor_selected = false;
                el.classList.remove('df-panning', 'df-space');
            });
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

        // ── Provider/model badge ──
        // '' → null (авто-избор при изпълнение); 'openai/gpt-4o' → 'openai';
        // anything unprefixed → local Ollama.
        modelProviderOf(model) {
            if (!model) return null;
            const paid = ['openai', 'anthropic', 'deepseek', 'gemini', 'xai', 'qwen'];
            const slash = String(model).indexOf('/');
            if (slash > 0 && paid.includes(model.slice(0, slash))) return model.slice(0, slash);
            return 'ollama';
        },

        modelBadge(model) {
            const provider = this.modelProviderOf(model);
            if (!provider) {
                return { cls: 'df-prov-auto', providerLabel: 'авто', name: 'авто-избор', full: 'Моделът се избира автоматично при изпълнение' };
            }
            if (provider === 'ollama') {
                const known = (this.models || []).find(m => m.ollama_tag === model);
                return { cls: 'df-prov-ollama', providerLabel: 'Ollama', name: known?.display_name || model, full: model };
            }
            const labels = { openai: 'OpenAI', anthropic: 'Anthropic', deepseek: 'DeepSeek', gemini: 'Gemini', xai: 'xAI', qwen: 'Qwen' };
            return { cls: `df-prov-${provider}`, providerLabel: labels[provider], name: String(model).slice(provider.length + 1), full: model };
        },

        nodeHtml(data) {
            if (this.isBoundaryData(data)) {
                return this.boundaryNodeHtml(data);
            }

            const role = this.effectiveOutputRole(data);
            const icon = this.resolveIcon(data);
            const roleDescription = this.roleDescription(role);
            const badge = this.modelBadge(data.model || '');

            // The run-extra block is ALWAYS rendered but collapsed in edit mode
            // via the df-run-hidden class so the card has no dead space below the
            // ports. When we flip into run mode the block expands and the card
            // grows; applyRunStatuses() calls editor.updateConnectionNodes() on
            // the reveal so the cached connection endpoints follow the port dots.
            // The model badge row is always visible; in run/view mode
            // paintModelBadge() swaps its text to the actually used model.
            return `<div class="df-node-card df-run-hidden ${this.roleClass(role)}">
                        <div class="df-node-header">
                            <div class="df-node-icon">${this.escapeHtml(icon)}</div>
                            <div class="df-node-main">
                                <div class="df-node-name">${this.escapeHtml(data.name || 'Агент')}</div>
                                <div class="df-node-type">${this.escapeHtml(this.typeLabel(data.type))}</div>
                            </div>
                            <button type="button" class="df-node-edit nodrag" title="Свойства" aria-label="Свойства">⚙</button>
                        </div>
                        <div class="df-node-model" data-model="${this.escapeHtml(data.model || '')}">
                            <span class="df-model-provider ${badge.cls}">${this.escapeHtml(badge.providerLabel)}</span>
                            <span class="df-model-name" title="${this.escapeHtml(badge.full)}">${this.escapeHtml(badge.name)}</span>
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
                                <button type="button" class="df-run-test nodrag" title="Тест на агента — експерименти с друг модел/промпт" disabled>🧪</button>
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
            // Keys for the per-node QA gate. The verifier is synthesized from the
            // criteria at run time; verifier_node_key only points at a dedicated
            // verifier node when the user wires one manually.
            configData.qa.verifier_node_key = configData.qa.verifier_node_key || '';
            configData.qa.custom_prompt = configData.qa.custom_prompt || '';

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
            // A new decision node starts with two routing branches (= two output ports).
            if (normalized.type === 'decision' && !(normalized.config.branches || []).length) {
                normalized.config.branches = [
                    { port: 'output_1', label: 'Клон A', when: '' },
                    { port: 'output_2', label: 'Клон B', when: '' },
                ];
            }
            const outputs = normalized.type === 'decision'
                ? Math.max(1, (normalized.config.branches || []).length)
                : 1;
            const pos = this.nextNodePosition();
            const before = Object.keys(this.editor.export().drawflow?.Home?.data || {});
            const returnedId = this.editor.addNode(normalized.type, 1, outputs, pos.x, pos.y, 'flow-node', normalized, this.nodeHtml(normalized));
            const after = Object.keys(this.editor.export().drawflow?.Home?.data || {});

            return returnedId || after.find(id => !before.includes(id)) || after[after.length - 1];
        },

        openNodeModal(id) {
            const node = this.editor.getNodeFromId(id);
            if (!node) return;
            if (this.isBoundaryData(node.data)) return;

            this.selectedId = id;
            this.selected = this.normalizeNodeData(JSON.parse(JSON.stringify(node.data || {})));
            // Запомня модела при отваряне — ръчна смяна при запис → ниво "custom".
            this._modelBeforeEdit = String(node.data?.model || '');
            this.propsTab = 'basic';
            this.propertiesOpen = true;
            this.generating = {};

            // Failed nodes in a failed run can be edited before resume.
            const nodeKey = node.data?.node_key || String(id);
            const isFailed = this.resumeUrl && this.failedNodeKeys.includes(nodeKey);
            this.resumeEditing = isFailed;
            // In run or view mode open as read-only — UNLESS this is a failed
            // node that can be edited and resumed.
            this.modalReadOnly = (this.mode !== 'edit') && !isFailed;
        },

        closeNodeModal() {
            this.propertiesOpen = false;
            this.selected = null;
            this.generating = {};
            this.modalReadOnly = false;
            this.resumeEditing = false;
        },

        saveNodeModal() {
            if (this.selectedId == null || !this.selected) return;

            const normalized = this.normalizeNodeData(this.selected);
            // Ръчно сменен модел → шаблонът вече не отговаря на ниво.
            if (String(normalized.model || '') !== this._modelBeforeEdit) {
                this.modelLevel = 'custom';
                this.levelSelect = '';
            }
            // Decision: re-key branch ports to output_1..N and sync the node's
            // output ports so each branch has its own port to wire an edge from.
            if (normalized.type === 'decision' && Array.isArray(normalized.config.branches)) {
                normalized.config.branches.forEach((b, i) => { b.port = 'output_' + (i + 1); });
            }
            this.editor.updateNodeDataFromId(this.selectedId, normalized);
            this.updateNodeLabel(this.selectedId, normalized);
            if (normalized.type === 'decision') {
                this.syncDecisionPorts(this.selectedId, (normalized.config.branches || []).length);
            }
            this.closeNodeModal();
        },

        // Branch editor helpers (decision nodes) ───────────────────────────
        addBranch() {
            if (!this.selected.config.branches) this.selected.config.branches = [];
            const n = this.selected.config.branches.length + 1;
            this.selected.config.branches.push({ port: 'output_' + n, label: 'Клон ' + n, when: '' });
        },

        removeBranch(i) {
            if (!Array.isArray(this.selected.config.branches)) return;
            this.selected.config.branches.splice(i, 1);
            this.selected.config.branches.forEach((b, idx) => { b.port = 'output_' + (idx + 1); });
        },

        // Make the Drawflow node's output-port count match the branch count, so
        // every branch has a dedicated port to connect an edge from.
        syncDecisionPorts(id, target) {
            target = Math.max(1, target);
            const node = this.editor.getNodeFromId(id);
            if (!node) return;
            let current = Object.keys(node.outputs || {}).length;
            while (current < target) { this.editor.addNodeOutput(id); current++; }
            while (current > target) { this.editor.removeNodeOutput(id, 'output_' + current); current--; }
            try { this.editor.updateConnectionNodes('node-' + id); } catch (e) {}
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

        pickModel(value) {
            if (this.modalReadOnly) return;
            this.selected.model = value;
            this.modelPickerOpen = false;
        },

        // Name + description for the currently selected model (picker button face).
        currentModelMeta() {
            const value = (this.selected && this.selected.model) || '';

            if (value === '') {
                return { name: '⚙ По подразбиране', desc: 'Кодът избира локален модел според типа на агента.' };
            }

            const paid = this.paidModels.find(m => m.value === value);
            if (paid) {
                return { name: '☁ ' + paid.label, desc: paid.description || 'Платен cloud модел.' };
            }

            const local = this.models.find(m => m.ollama_tag === value);
            if (local) {
                const star = (local.is_default_for || []).includes(this.selected.type) ? '★ ' : '';
                return { name: star + (local.display_name || local.ollama_tag), desc: local.description || local.category || '' };
            }

            return { name: value, desc: 'Моделът не е в каталога (премахнат или преименуван).' };
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

        // Записва редактора в ИЗБРАНИЯ шаблон (version_id). extra може да носи
        // agents/generator/intent при презапис след прясна генерация.
        async save(extra = {}) {
            // Never persist changes when in run or view mode — dragging nodes
            // around for visual clarity should not overwrite the saved graph.
            if (this.mode !== 'edit') return false;
            this.saving = true;
            this.saveError = null;
            try {
                const body = { graph: this.export(), ...extra };
                if (this.selectedVersionId) body.version_id = Number(this.selectedVersionId);
                if (this.modelLevel && body.model_level === undefined) body.model_level = this.modelLevel;

                const res = await fetch(config.saveUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': config.csrf,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(body),
                });

                const data = await res.json().catch(() => ({}));
                if (!res.ok || data.ok === false) {
                    this.saveError = data.error || 'Грешка при запис на графа.';
                    return false;
                }

                if (data.version) this.absorbVersion(data.version);
                this._baseline = JSON.stringify(this.editor.export());
                this.savedAt = new Date().toLocaleTimeString();
                this.clearAssistantMarks();
                return true;
            } catch (e) {
                console.error('save graph error', e);
                this.saveError = 'Мрежова грешка при запис на графа.';
                return false;
            } finally {
                this.saving = false;
            }
        },

        // ── Шаблони (граф версии) ─────────────────────────────────────────
        selectedVersionName() {
            const v = this.versions.find(v => String(v.id) === String(this.selectedVersionId));
            return v ? v.name : '';
        },

        // Слива създадена/обновена версия в dropdown състоянието.
        absorbVersion(v) {
            if (v.model_level !== undefined && v.model_level !== null) {
                this.modelLevel = v.model_level;
                this.levelSelect = this.isStandardLevel(v.model_level) ? v.model_level : '';
            }
            if (v.is_active) {
                this.versions.forEach(x => { x.is_active = String(x.id) === String(v.id); });
                this.activeVersionId = String(v.id);
            }
            const idx = this.versions.findIndex(x => String(x.id) === String(v.id));
            if (idx >= 0) {
                this.versions[idx] = { ...this.versions[idx], ...v };
            } else {
                this.versions.unshift({ ...v });
            }
            if (!this.selectedVersionId) {
                this.selectedVersionId = String(v.id);
                this._editingVersionId = String(v.id);
                this.runVersionId = String(v.id);
            }
        },

        switchVersion() {
            if (String(this.selectedVersionId) === String(this._editingVersionId)) return;
            const dirty = JSON.stringify(this.editor.export()) !== this._baseline;
            if (dirty && !confirm('Имаш незапазени промени по текущия шаблон. Превключване без запис?')) {
                this.selectedVersionId = this._editingVersionId;
                return;
            }
            window.location = config.builderUrl + '?version=' + this.selectedVersionId;
        },

        // ── Generation config popup ───────────────────────────────────────
        openGenConfig() {
            const defaults = config.plannerDefaults || {};
            const specs = Object.values(defaults).map(s => (s.provider || '') + ':' + (s.model || ''));
            const uniform = specs.length && specs.every(s => s === specs[0]);
            if (uniform) {
                this.genCfg.provider = defaults.pipeline_design?.provider || 'openai';
                this.genCfg.model = defaults.pipeline_design?.model || '';
                this.syncPickerFromSingle();
            } else {
                // .env вече описва хибрид → отваряме направо per-phase изгледа.
                this.genCfg.provider = 'hybrid';
            }
            this.genCfg.open = true;
        },

        genCfgModels() {
            return this.picker.singleModelOptions(this.genCfg.provider, this.genCfg.model);
        },

        modelLevelHint() {
            const lv = this.modelLevels.find(l => l.value === this.genCfg.level);
            return lv ? lv.hint : '';
        },

        // ── Ниво на моделите на шаблона (toolbar badge + смяна) ──────────
        levelMeta(lv) {
            return {
                low:    { label: 'Ниско',  icon: '🪙', cls: 'bg-emerald-100 text-emerald-700 border-emerald-300' },
                medium: { label: 'Средно', icon: '⚖️', cls: 'bg-blue-100 text-blue-700 border-blue-300' },
                high:   { label: 'Високо', icon: '🚀', cls: 'bg-orange-100 text-orange-700 border-orange-300' },
                ultra:  { label: 'Ултра',  icon: '💎', cls: 'bg-violet-100 text-violet-700 border-violet-300' },
                god:    { label: 'GOD',    icon: '👑', cls: 'bg-amber-100 text-amber-800 border-amber-400' },
                custom: { label: 'Custom', icon: '✎',  cls: 'bg-gray-100 text-gray-600 border-gray-300' },
            }[lv] || { label: '—', icon: '·', cls: 'bg-gray-100 text-gray-400 border-gray-200' };
        },

        isStandardLevel(lv) {
            return ['low', 'medium', 'high', 'ultra', 'god'].includes(lv);
        },

        onLevelSelect() {
            const level = this.levelSelect;
            if (!level || level === this.modelLevel) return;
            this.openRelevel(level);
        },

        async openRelevel(level) {
            this.relevel = { open: true, loading: true, applying: false, level, nodes: [], total: 0, basis: 'assumptions', error: null };
            try {
                const res = await fetch(config.relevelUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': config.csrf, 'Accept': 'application/json' },
                    body: JSON.stringify({
                        graph: this.export(),
                        level,
                        version_id: this.selectedVersionId ? Number(this.selectedVersionId) : undefined,
                    }),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok || data.ok === false) {
                    this.relevel.error = data.error || data.message || 'Грешка при изчисляване на новите модели.';
                } else {
                    this.relevel.nodes = data.nodes || [];
                    this.relevel.total = data.total_usd || 0;
                    this.relevel.basis = data.basis || 'assumptions';
                }
            } catch (e) {
                this.relevel.error = 'Мрежова грешка: ' + e.message;
            } finally {
                this.relevel.loading = false;
            }
        },

        closeRelevel() {
            this.relevel.open = false;
            // Селектът се връща на текущото ниво (или placeholder при custom/—).
            this.levelSelect = this.isStandardLevel(this.modelLevel) ? this.modelLevel : '';
        },

        async applyRelevel() {
            if (this.relevel.applying || this.relevel.loading || this.relevel.error) return;
            this.relevel.applying = true;

            // Новите модели се записват директно в нодовете (node_key = drawflow id).
            for (const n of this.relevel.nodes) {
                const node = this.editor.getNodeFromId(n.key);
                if (!node || this.isBoundaryData(node.data)) continue;
                const data = JSON.parse(JSON.stringify(node.data || {}));
                data.model = n.new_model;
                this.editor.updateNodeDataFromId(n.key, data);
                this.updateNodeLabel(n.key, data);
            }

            this.modelLevel = this.relevel.level;
            this.levelSelect = this.relevel.level;

            const ok = await this.save();
            this.relevel.applying = false;
            if (ok) this.relevel.open = false;
            else this.relevel.error = this.saveError || 'Грешка при запис.';
        },

        genCfgProviderChanged() {
            if (this.genCfg.provider === 'hybrid') return;
            // Първо нулирай модела: singleModelOptions() unshift-ва текущия
            // (вече стар) модел като custom опция и той би останал избран.
            this.genCfg.model = '';
            const first = this.genCfgModels()[0];
            this.genCfg.model = first ? first.value : '';
            this.syncPickerFromSingle();
        },

        // Единичен провайдър = всичките 4 фази на него (picker-ът смята цената).
        syncPickerFromSingle() {
            if (this.genCfg.provider === 'hybrid') return;
            for (const phase of this.picker.phaseOrder) {
                this.picker.phases[phase] = { provider: this.genCfg.provider, model: this.genCfg.model || '' };
            }
        },

        confirmGenConfig() {
            this.genCfg.open = false;
            this.startGeneration(true, this.picker.payload(), this.genCfg.level);
        },

        confirmSaveDialog() {
            this.saveDlg.error = '';

            if (this.saveDlg.mode === 'overwrite') {
                this.saveDlg.saving = true;
                this.save({
                    agents: this.saveDlg.agents,
                    generator: this.saveDlg.meta?.generator || null,
                    intent: this.saveDlg.meta?.intent || null,
                    cost_usd: this.saveDlg.meta?.cost_usd ?? null,
                    duration_ms: this.saveDlg.meta?.duration_ms ?? null,
                }).then(ok => {
                    this.saveDlg.saving = false;
                    if (ok) this.saveDlg.open = false;
                    else this.saveDlg.error = this.saveError || 'Грешка при запис.';
                });
                return;
            }

            if (!this.saveDlg.name.trim()) {
                this.saveDlg.error = 'Въведи име на шаблона.';
                return;
            }

            this.saveDlg.saving = true;
            fetch(config.versionStoreUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': config.csrf, 'Accept': 'application/json' },
                body: JSON.stringify({
                    name: this.saveDlg.name.trim(),
                    is_active: this.saveDlg.isActive,
                    graph: this.export(),
                    agents: this.saveDlg.agents,
                    generator: this.saveDlg.meta?.generator || null,
                    intent: this.saveDlg.meta?.intent || null,
                    model_level: this.saveDlg.meta?.level || this.modelLevel || null,
                    cost_usd: this.saveDlg.meta?.cost_usd ?? null,
                    duration_ms: this.saveDlg.meta?.duration_ms ?? null,
                }),
            })
            .then(res => res.json().then(data => ({ ok: res.ok, data })))
            .then(({ ok, data }) => {
                if (!ok || !data.ok) {
                    this.saveDlg.error = data.error || 'Грешка при запис на шаблона.';
                    return;
                }
                this.absorbVersion(data.version);
                // Редакторът вече показва съдържанието на НОВИЯ шаблон.
                this.selectedVersionId = String(data.version.id);
                this._editingVersionId = String(data.version.id);
                this.runVersionId = String(data.version.id);
                const url = new URL(window.location);
                url.searchParams.set('version', data.version.id);
                window.history.replaceState({}, '', url);
                this._baseline = JSON.stringify(this.editor.export());
                this.savedAt = new Date().toLocaleTimeString();
                this.clearAssistantMarks();
                this.saveDlg.open = false;
            })
            .catch(e => { this.saveDlg.error = 'Мрежова грешка: ' + e.message; })
            .finally(() => { this.saveDlg.saving = false; });
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

                this.stalledRun = data.status === 'running' && data.worker_alive === false;

                return ['completed', 'failed'].includes(data.status);
            } catch (e) {
                return false;
            }
        },

        // Merge a poll payload into runData and refresh node visuals.
        ingestPoll(data) {
            const prevStatus = this.runStatus;
            this.runStatus = data.status ?? this.runStatus;
            this.approvals = data.approvals || this.approvals;
            if (data.cost_usd != null) this.runCostUsd = data.cost_usd;
            if (data.completed_at_iso) this.runCompletedAt = data.completed_at_iso;

            this.finalOutput = data.final_output ?? this.finalOutput;
            if (this.finalModal.open) this.finalModal.body = this.finalOutput || '';

            // Auto-open final popup when a live run completes (view mode already
            // handles this on first hydrate via config.autoOpenFinal).
            if (this.mode === 'run' && prevStatus !== 'completed' && data.status === 'completed') {
                this.$nextTick(() => this.openFinal());
            }

            // The poll is metadata-only (no input/output/raw_output/params — those
            // are fetched on demand via fetchNodeDetail). Merge preserves any
            // already-fetched detail; _detailStatus invalidates it on status change.
            (data.node_runs || []).forEach((nr) => {
                const key = String(nr.node_key);
                this.runData[key] = Object.assign({}, this.runData[key], {
                    status: nr.status,
                    duration_ms: nr.duration_ms,
                    started_at_iso: nr.started_at_iso,
                    completed_at_iso: nr.completed_at_iso,
                    error: nr.error,
                    // Empty model_used (old runs / still-running auto nodes) must
                    // never clobber a model already learned from params_snapshot.
                    model: nr.model_used || (this.runData[key] || {}).model,
                    tokens_used: nr.tokens_used,
                    output_preview: nr.output_preview,
                    output_chars: nr.output_chars,
                });
            });

            this._lastProgress = data.progress || {};
            this.applyStatuses(data.node_runs || [], this._lastProgress, data.status);
        },

        // Resume a failed run without any node edits (from the banner button).
        async resumeRun() {
            if (!this.resumeUrl || this.resuming) return;
            this.resuming = true;
            try {
                const res = await fetch(this.resumeUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': config.csrf },
                    body: JSON.stringify({}),
                });
                const json = await res.json();
                if (!res.ok || !json.ok) {
                    alert(json.error || 'Неуспешно подновяване на изпълнението.');
                    this.resuming = false;
                    return;
                }
                // Redirect to the same run URL — it is now 'running' so the
                // builder will enter live run mode with polling.
                window.location.href = config.builderUrl + '?run=' + this.viewRunId;
            } catch (e) {
                alert('Грешка при подновяване: ' + e.message);
                this.resuming = false;
            }
        },

        // Save node edits and resume from the failed node (modal "Запази и продължи").
        async saveAndResume() {
            if (!this.resumeUrl || this.resuming || !this.selected) return;
            this.resuming = true;

            const node = this.editor.getNodeFromId(this.selectedId);
            const nodeKey = node?.data?.node_key || String(this.selectedId);

            const payload = {
                node_key: nodeKey,
                model: this.selected.model || null,
                system_prompt: this.selected.system_prompt || null,
                prompt_template: this.selected.prompt_template || null,
                config: {
                    temperature: this.selected.config?.temperature ?? null,
                    num_predict: this.selected.config?.num_predict ?? null,
                    num_ctx: this.selected.config?.num_ctx ?? null,
                    top_p: this.selected.config?.top_p ?? null,
                    top_k: this.selected.config?.top_k ?? null,
                    repeat_penalty: this.selected.config?.repeat_penalty ?? null,
                    seed: this.selected.config?.seed ?? null,
                    qa: this.selected.config?.qa ?? null,
                },
            };

            try {
                const res = await fetch(this.resumeUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': config.csrf },
                    body: JSON.stringify(payload),
                });
                const json = await res.json();
                if (!res.ok || !json.ok) {
                    alert(json.error || 'Неуспешно запазване/подновяване.');
                    this.resuming = false;
                    return;
                }
                this.closeNodeModal();
                window.location.href = config.builderUrl + '?run=' + this.viewRunId;
            } catch (e) {
                alert('Грешка: ' + e.message);
                this.resuming = false;
            }
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
            const startNodeStatus = (runStatus === 'running' || runStatus === 'waiting_approval' || runStatus === 'completed')
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

                el.classList.remove('df-status-running', 'df-status-completed', 'df-status-failed', 'df-status-skipped', 'df-status-pending', 'df-status-paused');
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

            // Тестът е смислен само когато има записан вход — т.е. възелът е
            // стигнал до изпълнение (completed или failed).
            const testBtn = card.querySelector('.df-run-test');
            if (testBtn) testBtn.disabled = !(status === 'completed' || status === 'failed');

            this.paintModelBadge(card, key);

            // Update the small text label under the bar.
            if (label) {
                if (status === 'running' && progress && (progress.pages_total > 0 || progress.phase === 'discovery')) {
                    // Detailed crawl progress for the site explorer: pages found /
                    // processed / failed + the page currently being read (tooltip).
                    const phaseMap = {
                        discovery: 'Откриване на страници',
                        map:       'Обработка на страници',
                        merge:     'Обобщаване',
                        running:   'В момента работи…',
                    };
                    let txt = phaseMap[progress.phase] ?? 'В момента работи…';
                    if (progress.pages_total > 0) {
                        txt += ` · ${progress.pages_done || 0}/${progress.pages_total}`;
                        if (progress.pages_failed > 0) txt += ` (${progress.pages_failed} неуспешни)`;
                    }
                    label.textContent = txt;
                    label.title = progress.last_line || '';
                } else {
                    const labelText = {
                        pending:   'Изчаква своя ред',
                        running:   'В момента работи…',
                        paused:    'Чака одобрение',
                        completed: 'Завършен',
                        failed:    'Неуспешен',
                        skipped:   'Пропуснат',
                    };
                    label.textContent = labelText[status] ?? '';
                    label.title = '';
                }
            }
        },

        // Swap the badge text to the actually used model once the run reports it.
        // Text-only mutation (the row is always rendered) → card height constant.
        paintModelBadge(card, key) {
            const row = card.querySelector('.df-node-model');
            if (!row) return;
            const actual = (this.runData[key] || {}).model;
            if (!actual || actual === row.dataset.model) return;
            const badge = this.modelBadge(actual);
            const prov = row.querySelector('.df-model-provider');
            const name = row.querySelector('.df-model-name');
            if (prov) { prov.textContent = badge.providerLabel; prov.className = 'df-model-provider ' + badge.cls; }
            if (name) { name.textContent = badge.name; name.title = badge.full; }
            row.dataset.model = actual;
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
            // breaks:true — LLM output uses single newlines as visual line breaks
            // (e.g. slogan lists without markdown bullets); soft-break collapsing
            // would merge them into one line.
            return marked.parse(String(text), { breaks: true, gfm: true });
        },

        // On-demand fetch of the full node payload (input/output/raw_output/params)
        // — the poll ships metadata only. Cached per node until its status changes.
        async fetchNodeDetail(key) {
            const cached = this.runData[key] || {};
            if (cached._detailStatus && cached._detailStatus === cached.status) return cached;
            if (!config.nodeDetailUrlBase) return cached;
            try {
                const res = await fetch(config.nodeDetailUrlBase + '/' + encodeURIComponent(key), { headers: { 'Accept': 'application/json' } });
                if (!res.ok) return cached;
                const detail = await res.json();
                const current = this.runData[key] || {};
                this.runData[key] = Object.assign({}, current, {
                    output: detail.output,
                    raw_output: detail.raw_output,
                    input: detail.input,
                    params: detail.params,
                    model: detail.model_used || detail.params?.model || current.model,
                    tokens_used: detail.tokens_used ?? current.tokens_used,
                    prompt_tokens: detail.prompt_tokens,
                    completion_tokens: detail.completion_tokens,
                    cost_usd: detail.cost_usd,
                    duration_ms: detail.duration_ms ?? current.duration_ms,
                    _detailStatus: current.status,
                });
                return this.runData[key];
            } catch (e) {
                return cached;
            }
        },

        // ── Human-in-the-loop ────────────────────────────────────────────────
        pausedApprovalKey() {
            for (const [k, v] of Object.entries(this.runData)) {
                if (v.status === 'paused') return k;
            }
            return null;
        },

        pausedApprovalName() {
            const key = this.pausedApprovalKey();
            if (!key) return '';
            return this.approvals[key]?.node_name
                || this.editor?.getNodeFromId(key)?.data?.name
                || key;
        },

        // The paused node's INPUT is the material being approved — show it in
        // the result modal (its output is empty until the decision lands).
        async openApprovalInput() {
            const key = this.pausedApprovalKey();
            if (!key) return;
            this.resultModal = { open: true, title: 'Материал за одобрение — ' + this.pausedApprovalName(), body: 'Зареждане…' };
            const d = await this.fetchNodeDetail(key);
            if (this.resultModal.open) this.resultModal.body = d.input || '(няма материал)';
        },

        async sendApproval(decision) {
            const key = this.pausedApprovalKey();
            if (!key || this.approval.sending || !config.nodeDetailUrlBase) return;
            if (decision === 'reject' && !confirm('Отхвърлянето прекратява изпълнението. Сигурен ли си?')) return;
            this.approval.sending = true;
            try {
                const res = await fetch(`${config.nodeDetailUrlBase}/${encodeURIComponent(key)}/approval`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': config.csrf, 'Accept': 'application/json' },
                    body: JSON.stringify({ decision, comment: this.approval.comment || null }),
                });
                const json = await res.json().catch(() => ({}));
                if (!res.ok || !json.ok) {
                    alert(json.error || 'Неуспешно изпращане на решението.');
                    return;
                }
                this.approval.comment = '';
                await this.pollOnce();
            } catch (e) {
                alert('Грешка: ' + e.message);
            } finally {
                this.approval.sending = false;
            }
        },

        async openResult(key) {
            const node = this.editor.getNodeFromId(key);
            const title = node?.data?.name || ('Възел ' + key);
            let d = this.runData[key] || {};
            let body;
            if (!d.status || d.status === 'pending') {
                body = 'Този агент още не е стартирал.';
            } else if (d.status === 'running') {
                body = 'Агентът работи в момента — изходът ще се появи, когато приключи.';
            } else {
                this.resultModal = { open: true, title, body: d.output || d.output_preview || 'Зареждане…' };
                d = await this.fetchNodeDetail(key);
                if (this.resultModal.open && this.resultModal.title === title) {
                    this.resultModal.body = d.output || '(няма изход)';
                }
                return;
            }
            this.resultModal = { open: true, title, body };
        },

        async openLog(key) {
            const node = this.editor.getNodeFromId(key);
            const title = node?.data?.name || ('Възел ' + key);
            let d = this.runData[key] || {};
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
                    error: '', steps: '', params: null, systemPrompt: '', output: '',
                    input: 'Този агент още не е стартирал. Лог ще се появи, след като предходните агенти приключат.',
                };
                return;
            }

            // The poll carries metadata only — pull input/output/params on demand.
            d = await this.fetchNodeDetail(key);

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
                meta: { status: statusLabel, model: (p.model || d.model) || '—', duration: dur, tokens: d.tokens_used || '—', cost: d.cost_usd || null },
                error: d.error || '',
                steps,
                params,
                systemPrompt: p.system_prompt || '',
                input: (p.user_message || d.input) || (status === 'running' ? '(агентът все още работи)' : '—'),
                output: d.output || '',
            };
        },

        // ───────────────────────── Тест на агент ─────────────────────────
        // Ad-hoc experiments on a finished node: same input, editable prompts,
        // any provider/model. Nothing is persisted unless „Приложи“ is clicked.

        async openTest(key) {
            const node = this.editor.getNodeFromId(key);
            const t = this.testModal;
            // Opening a different node releases the running state of the popup;
            // an in-flight test keeps polling and lands in testAttempts anyway.
            if (t.nodeKey !== key && t._timer) { clearInterval(t._timer); t._timer = null; }
            if (t.nodeKey !== key) { t.running = false; t.token = null; t.elapsed = 0; }
            t.open = true;
            t.loading = true;
            t.nodeKey = key;
            t.nodeName = node?.data?.name || ('Възел ' + key);
            t.nodeType = node?.data?.type || '';
            t.error = '';
            t.appliedNotice = '';
            t.inputOpen = false;

            const d = await this.fetchNodeDetail(key);
            const p = d.params || {};
            t.original = {
                model: d.model || p.model || node?.data?.model || '',
                system_prompt: p.system_prompt ?? node?.data?.system_prompt ?? '',
                user_message: p.user_message ?? d.input ?? '',
                options: p.options || {},
                output: d.output || '',
                error: d.error || '',
                status: d.status || '',
                duration_ms: d.duration_ms,
                tokens_used: d.tokens_used,
                cost_usd: d.cost_usd,
            };
            this.resetTestForm();
            t.activeAttempt = (this.testAttempts[key] || []).length - 1;
            t.loading = false;
        },

        // „Възстанови оригинала“ — prompts/options/model back to the run's snapshot.
        resetTestForm() {
            const o = this.testModal.original || {};
            const f = this.testModal.form;
            f.system_prompt = o.system_prompt || '';
            f.user_message = o.user_message || '';
            f.temperature = o.options?.temperature ?? '';
            f.num_predict = o.options?.num_predict ?? '';
            f.provider = this.modelProviderOf(o.model) || 'ollama';
            if (f.provider === 'ollama' && !o.model) {
                f.model = (this.models[0] || {}).ollama_tag || '';
            } else {
                f.model = o.model;
            }
        },

        testProviders() {
            const labels = { ollama: 'Ollama (локален, безплатен)', openai: 'OpenAI', anthropic: 'Anthropic', deepseek: 'DeepSeek', gemini: 'Gemini', xai: 'xAI', qwen: 'Qwen' };
            return ['ollama', 'openai', 'anthropic', 'deepseek', 'gemini', 'xai', 'qwen'].map(p => ({
                key: p,
                label: labels[p],
                available: p === 'ollama' ? (this.plannerAvailability.ollama ?? true) : !!this.plannerAvailability[p],
            }));
        },

        testProviderChanged() {
            const opts = this.testModelOptions();
            this.testModal.form.model = opts.length ? opts[0].value : '';
        },

        testModelOptions() {
            const f = this.testModal.form;
            if (f.provider === 'ollama') {
                const type = this.testModal.nodeType;
                const rec = this.models.filter(m => (m.is_default_for || []).includes(type));
                const rest = this.models.filter(m => !(m.is_default_for || []).includes(type));
                const opts = [...rec, ...rest].map(m => ({
                    value: m.ollama_tag,
                    label: ((m.is_default_for || []).includes(type) ? '★ ' : '') + (m.display_name || m.ollama_tag) + ' · ' + m.ollama_tag,
                    title: m.description || m.category || '',
                }));
                // The run's model may be missing from the installed list (e.g.
                // remote Ollama host) — keep it selectable anyway.
                const orig = this.testModal.original?.model;
                if (orig && this.modelProviderOf(orig) === 'ollama' && !opts.some(o => o.value === orig)) {
                    opts.unshift({ value: orig, label: orig + ' (моделът от run-а)', title: 'Моделът, използван в оригиналния run' });
                }
                return opts;
            }
            const opts = ((config.cloudModels || {})[f.provider] || []).map(m => {
                const info = this.picker.cloudInfo(f.provider, m);
                return {
                    value: f.provider + '/' + m,
                    label: m + (info?.stars ? ' · ' + this.picker.ratingStars(info.stars) : ''),
                    title: info?.desc || '',
                };
            });
            const orig = this.testModal.original?.model;
            if (orig && this.modelProviderOf(orig) === f.provider && !opts.some(o => o.value === orig)) {
                opts.unshift({ value: orig, label: orig.split('/').slice(1).join('/') + ' (моделът от run-а)', title: 'Моделът, използван в оригиналния run' });
            }
            return opts;
        },

        // Hint under the model select: stars · description · estimated cost.
        testModelHint() {
            const f = this.testModal.form;
            if (!f.model) return '';
            if (f.provider === 'ollama') {
                const m = this.models.find(x => x.ollama_tag === f.model);
                const desc = (m?.description || '').split('.')[0];
                return [desc, 'безплатно (локален)'].filter(Boolean).join(' · ');
            }
            const info = this.picker.cloudInfo(f.provider, f.model.split('/').slice(1).join('/'));
            if (!info) return '';
            return [info.stars ? this.picker.ratingStars(info.stars) : '', info.desc || '', this.testCostEstimate()]
                .filter(Boolean).join(' · ');
        },

        // Rough cost for one generation: chars/2.5 ≈ tokens in (BG text), num_predict out.
        testCostEstimate() {
            const f = this.testModal.form;
            if (!f.model || f.provider === 'ollama') return 'безплатно';
            const info = this.picker.cloudInfo(f.provider, f.model.split('/').slice(1).join('/'));
            if (!info || (!info.in && !info.out)) return '';
            const inTok = (String(f.system_prompt).length + String(f.user_message).length) / 2.5;
            const outTok = Number(f.num_predict) > 0 ? Number(f.num_predict) : 1500;
            const cost = (inTok * (info.in || 0) + outTok * (info.out || 0)) / 1e6;
            return '~$' + cost.toFixed(4);
        },

        activeTestAttempt() {
            const list = this.testAttempts[this.testModal.nodeKey] || [];
            return list[this.testModal.activeAttempt] || null;
        },

        async runTest() {
            const t = this.testModal;
            if (t.running || !t.form.model || !t.form.user_message) return;
            t.error = '';
            t.appliedNotice = '';

            // Preserve the run's sampler options (num_ctx, top_p…), override
            // only what the form exposes.
            const options = Object.assign({}, t.original?.options || {});
            delete options.http_timeout;
            if (t.form.temperature !== '' && t.form.temperature !== null) options.temperature = Number(t.form.temperature);
            if (t.form.num_predict !== '' && t.form.num_predict !== null) options.num_predict = Number(t.form.num_predict);

            let res, data;
            try {
                res = await fetch(`${config.nodeDetailUrlBase}/${encodeURIComponent(t.nodeKey)}/test`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': config.csrf },
                    body: JSON.stringify({
                        model: t.form.model,
                        system_prompt: t.form.system_prompt,
                        user_message: t.form.user_message,
                        options,
                    }),
                });
                data = await res.json();
            } catch (e) {
                t.error = 'Мрежова грешка: ' + e.message;
                return;
            }
            if (!res.ok) {
                t.error = data?.error || data?.message || ('HTTP ' + res.status);
                return;
            }

            t.running = true;
            t.token = data.token;
            t.startedAt = Date.now();
            t.elapsed = 0;
            if (t._timer) clearInterval(t._timer);
            t._timer = setInterval(() => { t.elapsed = Math.round((Date.now() - t.startedAt) / 1000); }, 500);

            this.pollTest(t.nodeKey, data.token, { model: t.form.model, system_prompt: t.form.system_prompt });
        },

        // Poll the test token until it finishes. Captures node + form so the
        // attempt lands correctly even if the popup was closed or switched to
        // another node meanwhile.
        pollTest(key, token, formSnapshot) {
            const tick = async () => {
                let data = null;
                let expired = false;
                try {
                    const res = await fetch(`${config.nodeTestStatusUrlBase}/${token}`, { headers: { 'Accept': 'application/json' } });
                    expired = res.status === 404;
                    data = await res.json();
                } catch (e) { /* transient network error — keep polling */ }

                const finished = data && ['completed', 'failed'].includes(data.status);
                if (!finished && !expired) { setTimeout(tick, 2000); return; }

                if (finished) {
                    if (!this.testAttempts[key]) this.testAttempts[key] = [];
                    this.testAttempts[key].push({
                        status: data.status,
                        model: formSnapshot.model,
                        provider: this.modelProviderOf(formSnapshot.model),
                        system_prompt: formSnapshot.system_prompt,
                        at: new Date().toLocaleTimeString('bg-BG', { hour: '2-digit', minute: '2-digit' }),
                        duration_ms: data.duration_ms,
                        tokens_used: data.tokens_used,
                        cost_usd: data.cost_usd,
                        output: data.output || '',
                        raw_output: data.raw_output || '',
                        error: data.error || '',
                    });
                    if (this.testModal.nodeKey === key) {
                        this.testModal.activeAttempt = this.testAttempts[key].length - 1;
                    }
                }

                // Release the running state only if this is still the active test.
                if (this.testModal.token === token) {
                    this.testModal.running = false;
                    this.testModal.token = null;
                    if (this.testModal._timer) { clearInterval(this.testModal._timer); this.testModal._timer = null; }
                    if (expired) this.testModal.error = 'Токенът на теста изтече. Опитай отново.';
                }
            };
            setTimeout(tick, 1500);
        },

        // „Приложи в агента“: model always; system prompt only when edited.
        // The user message is never persisted — it's the rendered input, not
        // the prompt template.
        async applyAttempt(i) {
            const t = this.testModal;
            const a = (this.testAttempts[t.nodeKey] || [])[i];
            if (!a || a.status !== 'completed' || t.applying) return;

            const stripOutputBlock = (s) => String(s || '').replace(/\n\n---\nOUTPUT REQUIREMENTS:\n[\s\S]*$/, '');
            const sysEdited = (a.system_prompt || '') !== (t.original?.system_prompt || '');
            const sysToApply = sysEdited ? stripOutputBlock(a.system_prompt) : null;

            if (this.mode === 'edit') {
                const node = this.editor.getNodeFromId(t.nodeKey);
                if (!node) { t.error = 'Възелът не е намерен в редактора.'; return; }
                const data = this.normalizeNodeData(Object.assign({}, node.data, {
                    model: a.model,
                    ...(sysToApply && sysToApply.trim() !== '' ? { system_prompt: sysToApply } : {}),
                }));
                this.updateNodeLabel(t.nodeKey, data);
                t.appliedNotice = 'Записано в редактора — натисни „Запази“, за да остане.';
                return;
            }

            const what = sysEdited ? 'модела и системния промпт' : 'модела';
            if (!confirm(`Това ще промени ${what} на агент „${t.nodeName}“ в текущия flow (не пипа този run). Продължи?`)) return;

            t.applying = true;
            t.error = '';
            try {
                const res = await fetch(`${config.nodeDetailUrlBase}/${encodeURIComponent(t.nodeKey)}/apply-test`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': config.csrf },
                    body: JSON.stringify({ model: a.model, system_prompt: sysToApply }),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok || !data.ok) {
                    t.error = data.error || ('Прилагането се провали (HTTP ' + res.status + ').');
                    return;
                }
                t.appliedNotice = 'Приложено във flow-а ✓ — ще важи при следващия run.';
            } catch (e) {
                t.error = 'Мрежова грешка: ' + e.message;
            } finally {
                t.applying = false;
            }
        },

        openFinal() {
            this.finalModal = { open: true, body: this.finalOutput || '' };
        },

        // ── Асистент (Builder Copilot) ─────────────────────────────────────
        toggleChat() {
            this.chat.open = !this.chat.open;
            if (this.chat.open && !this.chat.loaded) this.loadChatHistory();
            if (this.chat.open) this.$nextTick(() => this.scrollChat());
        },

        scrollChat() {
            const el = this.$refs.chatScroll;
            if (el) el.scrollTop = el.scrollHeight;
        },

        chatUuid() {
            if (window.crypto?.randomUUID) return crypto.randomUUID();
            return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
                const r = Math.random() * 16 | 0;
                return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
            });
        },

        newChat() {
            this.chat.session = this.chatUuid();
            this.chat.messages = [];
        },

        async loadChatHistory() {
            this.chat.loaded = true;
            try {
                const res = await fetch(config.assistantHistoryUrl, { headers: { 'Accept': 'application/json' } });
                const data = await res.json();
                if (data.session) this.chat.session = data.session;
                this.chat.messages = (data.messages || []).map(m => ({
                    role: m.role,
                    content: m.content || '',
                    failed: !!m.failed,
                    cost_usd: m.cost_usd,
                    hasOps: !!m.has_ops,
                }));
                this.$nextTick(() => this.scrollChat());
            } catch (e) {
                console.error('assistant history error', e);
            }
        },

        async sendChat(text = null) {
            const message = String(text ?? this.chat.input ?? '').trim();
            if (!message || this.chat.sending) return;

            this.chat.input = '';
            this.chat.messages.push({ role: 'user', content: message });
            this.chat.sending = true;
            this.chat.stage = 'Мисля…';
            this.$nextTick(() => this.scrollChat());

            try {
                const res = await fetch(config.assistantSendUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': config.csrf, 'Accept': 'application/json' },
                    body: JSON.stringify({
                        message,
                        session: this.chat.session,
                        mode: this.mode,
                        // Работното копие: асистентът вижда ТЕКУЩИЯ канвас, вкл. незапазени промени.
                        graph: this.mode === 'edit' ? this.export() : null,
                    }),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) throw new Error(data.error || 'Грешка при изпращане.');
                this.chat.session = data.session || this.chat.session;
                await this.pollAssistant(data.token);
            } catch (e) {
                this.chat.messages.push({ role: 'assistant', content: e.message || 'Мрежова грешка.', failed: true, retryText: message });
            } finally {
                this.chat.sending = false;
                this.chat.stage = '';
                this.chat.partial = '';
                this.$nextTick(() => this.scrollChat());
            }
        },

        async pollAssistant(token) {
            // Дълъг turn е ОК, докато job-ът дава признаци на живот (stage/partial
            // опресняват updated_at); ~4 минути без промяна = умрял worker.
            let lastSeen = null, stale = 0;
            for (let i = 0; i < 1300; i++) {
                await new Promise(r => setTimeout(r, 1200));
                let res, data;
                try {
                    res = await fetch(config.assistantStatusUrlBase + '/' + token, { headers: { 'Accept': 'application/json' } });
                    data = await res.json();
                } catch (e) {
                    continue; // мрежов блип — следващият тик ще успее
                }
                if (res.status === 404) throw new Error(data.error || 'Заявката изтече — изпрати съобщението отново.');
                if (data.status === 'completed' && data.message) {
                    const m = data.message;
                    this.chat.messages.push({
                        role: 'assistant',
                        content: m.content || '',
                        cost_usd: m.cost_usd,
                        hasOps: (m.ops || []).length > 0,
                    });
                    this.applyAssistantOps(m.ops || []);
                    this.runAssistantUi(m.ui || []);
                    return;
                }
                if (data.status === 'failed') throw new Error(data.error || 'Асистентът се провали.');
                if (data.stage) this.chat.stage = data.stage;
                if (data.partial) { this.chat.partial = data.partial; this.$nextTick(() => this.scrollChat()); }

                stale = (data.updated_at && data.updated_at !== lastSeen) ? 0 : stale + 1;
                lastSeen = data.updated_at ?? lastSeen;
                if (stale >= 200) throw new Error('Асистентът спря да отговаря (timeout) — провери дали queue worker-ът върви.');
            }
            throw new Error('Времето за отговор изтече.');
        },

        // Прилага предложените операции върху канваса. Нищо не пипа в БД —
        // промените стават реални чак при 💾 Запис (= одобрението).
        applyAssistantOps(ops) {
            if (!ops.length || this.mode !== 'edit') return;
            this._assistantIdMap = {};
            const rid = (k) => this._assistantIdMap[k] ?? k;

            for (const op of ops) {
                try {
                    if (op.op === 'add') {
                        const id = this.addNodeData(op.data);
                        this._assistantIdMap[op.node_key] = id;
                        if (op.pos_x != null && op.pos_y != null) this.moveNodeTo(id, op.pos_x, op.pos_y);
                        this.markAssistantNode(id);
                    } else if (op.op === 'update') {
                        const id = rid(op.node_key);
                        const normalized = this.normalizeNodeData(op.data);
                        this.editor.updateNodeDataFromId(id, normalized);
                        this.updateNodeLabel(id, normalized);
                        this.markAssistantNode(id);
                    } else if (op.op === 'remove') {
                        this.editor.removeNodeId('node-' + rid(op.node_key));
                    } else if (op.op === 'connect') {
                        this.editor.addConnection(String(rid(op.from)), String(rid(op.to)), 'output_1', 'input_1');
                    } else if (op.op === 'disconnect') {
                        this.editor.removeSingleConnection(String(rid(op.from)), String(rid(op.to)), 'output_1', 'input_1');
                    }
                } catch (e) {
                    console.error('assistant op failed', op, e);
                }
            }

            this.ensureBoundaryNodes();
        },

        moveNodeTo(id, x, y) {
            const node = this.editor.drawflow?.drawflow?.Home?.data?.[id];
            const el = document.getElementById('node-' + id);
            if (!node || !el) return;
            node.pos_x = x;
            node.pos_y = y;
            el.style.left = x + 'px';
            el.style.top = y + 'px';
            this.editor.updateConnectionNodes('node-' + id);
        },

        markAssistantNode(id) {
            this.$nextTick(() => document.getElementById('node-' + id)?.classList.add('assistant-proposed'));
        },

        clearAssistantMarks() {
            document.querySelectorAll('#drawflow .assistant-proposed')
                .forEach(el => el.classList.remove('assistant-proposed'));
        },

        runAssistantUi(ui) {
            for (const action of ui) {
                if (action.action === 'open_node') {
                    const id = this._assistantIdMap[action.node_key] ?? action.node_key;
                    this.$nextTick(() => this.openNodeModal(String(id)));
                }
            }
        },

        escapeChat(s) {
            return String(s ?? '')
                .replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]))
                .replace(/\n/g, '<br>');
        },

        // Минимален markdown за отговорите: **bold**, `code`, редове/булети.
        chatMd(s) {
            let h = this.escapeChat(s);
            h = h.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
            h = h.replace(/`([^`]+)`/g, '<code class="bg-gray-200/80 px-1 rounded text-[12px]">$1</code>');
            h = h.replace(/(^|<br>)\s*[-•]\s+/g, '$1• ');
            return h;
        },

        // ───────────────────────── Памет на flow-а ─────────────────────────

        async openMemoryPanel() {
            this.memoryPanel.open = true;
            this.memoryPanel.loading = true;
            this.memoryPanel.error = '';
            try {
                const res = await fetch(config.memoryUrl, { headers: { 'Accept': 'application/json' } });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const data = await res.json();
                this.memoryPanel.enabled = !!data.enabled;
                this.memoryPanel.outputs = data.outputs || [];
                this.memoryPanel.lessons = data.lessons || [];
            } catch (e) {
                this.memoryPanel.error = 'Неуспешно зареждане на паметта: ' + e.message;
            } finally {
                this.memoryPanel.loading = false;
            }
        },

        async toggleMemory() {
            if (this.memoryPanel.toggling) return;
            this.memoryPanel.toggling = true;
            try {
                const res = await fetch(config.memoryToggleUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': config.csrf, 'Accept': 'application/json' },
                });
                if (res.ok) this.memoryPanel.enabled = !!(await res.json()).enabled;
            } catch (e) {
                // toggle failure is non-critical — the switch simply stays put
            } finally {
                this.memoryPanel.toggling = false;
            }
        },

        memFilteredRows() {
            const data = this.memoryPanel.tab === 'outputs' ? this.memoryPanel.outputs : this.memoryPanel.lessons;
            const q = this.memoryPanel.search.toLowerCase().trim();
            let rows = q ? data.filter(r =>
                ['node_name', 'node_key', 'title', 'summary', 'created_at'].some(k => r[k] && String(r[k]).toLowerCase().includes(q))
            ) : data;
            const col = this.memoryPanel.sortCol;
            const dir = this.memoryPanel.sortDir === 'asc' ? 1 : -1;
            return [...rows].sort((a, b) =>
                ((a[col] ?? '').toString()).localeCompare((b[col] ?? '').toString(), 'bg') * dir
            );
        },
        memPagedRows() {
            const rows = this.memFilteredRows();
            const s = (this.memoryPanel.page - 1) * this.memoryPanel.pageSize;
            return rows.slice(s, s + this.memoryPanel.pageSize);
        },
        memTotalPages() {
            return Math.max(1, Math.ceil(this.memFilteredRows().length / this.memoryPanel.pageSize));
        },
        memPagingLabel() {
            const total = this.memFilteredRows().length;
            if (!total) return '0 записа';
            const s = Math.min((this.memoryPanel.page - 1) * this.memoryPanel.pageSize + 1, total);
            const e = Math.min(this.memoryPanel.page * this.memoryPanel.pageSize, total);
            return s + '–' + e + ' от ' + total;
        },
        openMemoryPreview(nodeName, title, body) {
            this.memoryPanel.preview = { open: true, nodeName, title, body };
        },
        memSort(col) {
            if (this.memoryPanel.sortCol === col) {
                this.memoryPanel.sortDir = this.memoryPanel.sortDir === 'asc' ? 'desc' : 'asc';
            } else {
                this.memoryPanel.sortCol = col;
                this.memoryPanel.sortDir = 'asc';
            }
            this.memoryPanel.page = 1;
        },

        async clearMemory() {
            if (!confirm('Изтриване на цялата памет на flow-а (съдържание + поуки)? Следващите изпълнения започват „на чисто”.')) return;
            this.memoryPanel.clearing = true;
            try {
                const res = await fetch(config.memoryClearUrl, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': config.csrf, 'Accept': 'application/json' },
                });
                if (res.ok) {
                    this.memoryPanel.outputs = [];
                    this.memoryPanel.lessons = [];
                }
            } catch (e) {
                this.memoryPanel.error = 'Неуспешно изчистване: ' + e.message;
            } finally {
                this.memoryPanel.clearing = false;
            }
        },

        async openGenLog() {
            this.genLogModal = { open: true, loading: true, logs: [], error: '' };
            try {
                const res = await fetch(config.generationLogsUrl, { headers: { 'Accept': 'application/json' } });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const data = await res.json();
                this.genLogModal.logs = (data.groups || []).map(g => Object.assign({}, g, {
                    _expanded: false,
                    phases: (g.phases || []).map(p => Object.assign({ _expanded: false }, p)),
                }));
            } catch (e) {
                this.genLogModal.error = 'Неуспешно зареждане на логовете: ' + e.message;
            } finally {
                this.genLogModal.loading = false;
            }
        },

        // ───────────────────────── Agent generation (DAG) ─────────────────────────

        startGeneration(autoSave, phases = null, level = 'medium') {
            // Block double-starts only while a generation is actually running —
            // after a failure the modal stays active to show the error, and
            // "Опитай пак" must be able to restart.
            if (this.gen.active && !this.gen.error) return;
            const hasNodes = Object.values(this.editor.export().drawflow.Home.data || {})
                .some(n => !this.isBoundaryData(n.data));
            if (hasNodes && !config.generate) {
                if (!confirm('Това ще ИЗТРИЕ всички текущи агенти в графа и ще създаде нови. Продължаваме?')) return;
            }

            this.gen = {
                active: true,
                progress: 4,
                message: 'Стартиране…',
                stage: 'Стартиране…',
                error: null,
                token: null,
                autoSave: !!autoSave,
                phases,
                level,
                _timer: null,
                _rot: null,
                _stageChangedAt: Date.now(),
                _narratorStage: 'Стартиране…',
                _narratorIndex: 0,
                _steadyLineShown: false,
                _lastNarratorDelay: 0,
            };
            this.startRotatingMessages();

            fetch(config.generateUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': config.csrf, 'Accept': 'application/json' },
                body: JSON.stringify({
                    company_id: config.companyId,
                    flow_id: config.flowId,
                    name: config.flowName,
                    description: config.flowDescription,
                    // null → планира на .env defaults; иначе per-phase изборът от попъпа.
                    phases: phases || undefined,
                    // Ниво на runtime моделите за агентите (low|medium|high|ultra|god).
                    level,
                }),
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
                    if (data.stage) this.setGenerationStage(data.stage);
                    if (data.status === 'completed') {
                        this.gen.progress = 100;
                        await this.finishGeneration(data.agents || [], data);
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

        setGenerationStage(stage) {
            if (!stage || this.gen.stage === stage) return;

            this.gen.stage = stage;
            this.gen.message = stage;
            this.gen._stageChangedAt = Date.now();
            this.gen._narratorStage = stage;
            this.gen._narratorIndex = 0;
            this.gen._steadyLineShown = false;
        },

        startRotatingMessages() {
            const stageLines = {
                'Стартиране...': [
                    'Подготвям заявката за AI генератора…',
                    'Събирам контекста за този flow…',
                ],
                'Стартиране…': [
                    'Подготвям заявката за AI генератора…',
                    'Събирам контекста за този flow…',
                ],
                'Подготовка на заявката': [
                    'Чета описанието и целта на flow-а…',
                    'Изваждам основните задачи от описанието…',
                    'Подготвям контекста за проектиране на pipeline-а…',
                ],
                'Генериране на агенти': [
                    'Проектирам ролите на отделните агенти…',
                    'Разписвам инструкциите за всяка стъпка…',
                    'Подбирам подходящите типове агенти и модели…',
                    'Проверявам дали pipeline-ът покрива целта на flow-а…',
                ],
                'Обработка на резултата': [
                    'Подреждам агентите в смислена последователност…',
                    'Свързвам входовете и изходите между стъпките…',
                    'Почиствам резултата, преди да го превърна в граф…',
                ],
                'Проверка за web research': [
                    'Преценявам дали е нужен агент за търсене в мрежата…',
                    'Проверявам дали pipeline-ът има достатъчно контекст…',
                ],
                'Финализиране на pipeline-а': [
                    'Изграждам зависимостите между агентите…',
                    'Подреждам финалните връзки в графа…',
                    'Правя последна проверка на структурата…',
                ],
            };
            const fallbackLines = [
                'Анализирам описанието на flow-а…',
                'Обмислям най-подходящата структура на pipeline-а…',
                'Подготвям агентите така, че да работят като екип…',
            ];
            const nextDelay = () => 3800 + Math.floor(Math.random() * 1600);

            const scheduleNext = () => {
                const delay = nextDelay();
                this.gen._lastNarratorDelay = delay;
                this.gen._rot = setTimeout(() => {
                    if (!this.gen.active) return;

                    const stage = this.gen.stage || '';
                    const lines = stageLines[stage] || fallbackLines;
                    const stageAge = Date.now() - (this.gen._stageChangedAt || 0);

                    if (this.gen._stageChangedAt && stageAge < (this.gen._lastNarratorDelay || 4200)) {
                        scheduleNext();
                        return;
                    }

                    if (this.gen._narratorStage !== stage) {
                        this.gen._narratorStage = stage;
                        this.gen._narratorIndex = 0;
                        this.gen._steadyLineShown = false;
                    }

                    if (this.gen._narratorIndex < lines.length) {
                        this.gen.message = lines[this.gen._narratorIndex];
                        this.gen._narratorIndex++;
                    } else if (!this.gen._steadyLineShown) {
                        this.gen.message = 'Още малко — довършвам pipeline-а…';
                        this.gen._steadyLineShown = true;
                    }

                    scheduleNext();
                }, delay);
            };

            scheduleNext();
        },

        stopGenerationTimers() {
            if (this.gen._rot) { clearTimeout(this.gen._rot); this.gen._rot = null; }
        },

        failGeneration(msg) {
            this.stopGenerationTimers();
            this.gen.error = msg;
            this.gen.message = '';
        },

        async finishGeneration(agents, meta = {}) {
            this.stopGenerationTimers();
            this.gen.message = 'Готово — изграждам графа…';
            try {
                this.applyGeneratedGraph(agents);
            } catch (e) {
                console.error('applyGeneratedGraph failed', e);
                this.failGeneration('Грешка при изграждане на графа: ' + e.message);
                return;
            }

            // Нивото, с което е генериран планът — записва се на шаблона.
            if (meta.level) {
                this.modelLevel = meta.level;
                this.levelSelect = this.isStandardLevel(meta.level) ? meta.level : '';
            }

            // Първа генерация на flow-а (?generate=1) → авто-запис, който
            // bootstrap-ва шаблона "Default" (активен) без диалози.
            if (this.versions.length === 0) {
                await this.save({
                    agents,
                    generator: meta.generator || null,
                    intent: meta.intent || null,
                    cost_usd: meta.cost_usd ?? null,
                    duration_ms: meta.duration_ms ?? null,
                });
                this.gen.active = false;
                return;
            }

            // Иначе: потребителят решава — нов шаблон или презапис на текущия.
            this.gen.active = false;
            this.saveDlg = {
                open: true,
                mode: 'new',
                name: meta.generator?.label || 'Нов шаблон',
                isActive: true,
                agents,
                meta,
                saving: false,
                error: '',
            };
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

            // qa_verifier agents are NOT placed as nodes: the step-QA gate runs a
            // verifier synthesized from the gated node's own qa config (criteria +
            // threshold), so there is no separate node to wire or lay out.
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
