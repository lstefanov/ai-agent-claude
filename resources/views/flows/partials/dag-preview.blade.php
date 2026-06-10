{{--
    Read-only DAG преглед на планирани агенти — същите визуални карти като в
    граф билдъра, подредени в същите колони (longest-path layering), със SVG
    връзки. Клик върху карта отваря модал с настройките на агента (редактируеми
    — промените се записват обратно в подадения agents масив по референция).

    Договор: host страницата създава Alpine scope, в който има обект `dag`,
    построен с window.planDagPreview(opts):

        opts = {
            agentTypes:    [{type, label, description, output_role}, ...],
            templateIcons: { type: icon },
            readOnly:      bool,
        }

    Отваряне: dag.show(agentsArray) — масивът се мутира при редакция.
--}}

<style>
    .dagp-card {
        position: absolute;
        width: 280px;
        border: 1px solid #dbe3ef;
        border-left: 5px solid #64748b;
        border-radius: 16px;
        background: linear-gradient(180deg, #fff 0%, #f8fafc 100%);
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.10);
        overflow: hidden;
        cursor: pointer;
        transition: box-shadow 160ms ease, transform 160ms ease;
    }
    .dagp-card:hover { transform: translateY(-1px); box-shadow: 0 14px 32px rgba(15, 23, 42, 0.16); }
    .dagp-role-body { border-left-color: #6366f1; }
    .dagp-role-hidden, .dagp-role-processing { border-left-color: #0ea5e9; }
    .dagp-role-appendix { border-left-color: #a855f7; }
    .dagp-role-quality { border-left-color: #f59e0b; }
    .dagp-header { display: flex; align-items: flex-start; gap: 10px; padding: 12px 12px 10px; }
    .dagp-icon {
        display: inline-flex; align-items: center; justify-content: center;
        width: 34px; height: 34px; border-radius: 12px;
        background: #eef2ff; color: #4338ca; font-size: 18px; flex: 0 0 auto;
    }
    .dagp-name { color: #111827; font-size: 13px; font-weight: 700; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .dagp-type { margin-top: 3px; color: #64748b; font-size: 11px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .dagp-footer {
        display: flex; align-items: center; justify-content: space-between; gap: 10px;
        border-top: 1px solid #edf2f7; padding: 6px 12px 8px;
        color: #94a3b8; font-size: 10px; text-transform: uppercase; letter-spacing: 0.08em; font-weight: 700;
    }
    .dagp-boundary {
        position: absolute;
        display: inline-flex; align-items: center; gap: 6px;
        border: 1px dashed #cbd5e1; border-radius: 999px;
        background: #f8fafc; color: #64748b;
        font-size: 11px; font-weight: 700; padding: 6px 12px;
        white-space: nowrap;
    }
</style>

{{-- Fullscreen overlay --}}
<div x-show="dag.open" x-cloak class="fixed inset-0 z-[70] flex flex-col bg-slate-900/70 backdrop-blur-sm p-4 sm:p-6"
     @keydown.escape.window="dag.modal.open ? dag.closeSettings() : (dag.open = false)">
    <div class="relative bg-white rounded-2xl shadow-2xl flex-1 min-h-0 flex flex-col overflow-hidden" @click.stop>
        <div class="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between gap-3 shrink-0">
            <div>
                <h3 class="text-lg font-bold text-gray-900">Преглед на агентите</h3>
                <p class="text-xs text-gray-400">Подредба като в граф билдъра. Клик върху агент → настройки<span x-show="!dag.opts.readOnly"> (редактируеми — влизат в шаблона при „Запази“)</span>.</p>
            </div>
            <button type="button" @click="dag.open = false" class="text-gray-400 hover:text-gray-600 text-2xl leading-none px-2">✕</button>
        </div>
        <div class="flex-1 min-h-0 overflow-auto bg-gray-50/60">
            <div class="relative" :style="`width:${dag.layout.width}px;height:${dag.layout.height}px`">
                <svg class="absolute inset-0 pointer-events-none" :width="dag.layout.width" :height="dag.layout.height">
                    <template x-for="(edge, ei) in dag.layout.edges" :key="'e' + ei">
                        <path :d="edge.path" fill="none" stroke="#94a3b8" stroke-width="2.5" opacity="0.65"></path>
                    </template>
                </svg>

                <div class="dagp-boundary" :style="`left:${dag.layout.start.x}px;top:${dag.layout.start.y}px`">▶ Старт</div>
                <div class="dagp-boundary" :style="`left:${dag.layout.end.x}px;top:${dag.layout.end.y}px`">■ Край</div>

                <template x-for="node in dag.layout.nodes" :key="'n' + node.idx">
                    <div class="dagp-card" :class="dag.roleClass(node.agent)"
                         :style="`left:${node.x}px;top:${node.y}px`"
                         @click="dag.openSettings(node.idx)">
                        <div class="dagp-header">
                            <div class="dagp-icon" x-text="dag.icon(node.agent)"></div>
                            <div class="min-w-0 flex-1">
                                <div class="dagp-name" x-text="node.agent.name || 'Агент'"></div>
                                <div class="dagp-type" x-text="dag.typeLabel(node.agent.type)"></div>
                            </div>
                        </div>
                        <div class="dagp-footer">
                            <span x-text="dag.modelLabel(node.agent)"></span>
                            <span>⚙ настройки</span>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    {{-- Agent settings modal --}}
    <div x-show="dag.modal.open" x-cloak class="fixed inset-0 z-[80] flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/40" @click="dag.closeSettings()"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[85vh] flex flex-col" @click.stop>
            <div class="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between shrink-0">
                <h3 class="text-base font-bold text-gray-900" x-text="'Настройки — ' + (dag.modal.agent?.name || '')"></h3>
                <button type="button" @click="dag.closeSettings()" class="text-gray-400 hover:text-gray-600 text-xl leading-none">✕</button>
            </div>
            <div class="p-5 overflow-y-auto space-y-3 text-sm" x-show="dag.modal.agent">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Име</label>
                        <input type="text" x-model="dag.modal.agent.name" :disabled="dag.opts.readOnly"
                               class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:bg-gray-50">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Тип</label>
                        <select x-model="dag.modal.agent.type" :disabled="dag.opts.readOnly"
                                class="w-full border border-gray-300 rounded-lg px-2 py-1.5 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:bg-gray-50">
                            <template x-for="t in dag.opts.agentTypes" :key="t.type">
                                <option :value="t.type" x-text="t.label + ' (' + t.type + ')'"></option>
                            </template>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Модел <span class="text-gray-400">(празно = авто; openai/… или anthropic/… = платен)</span></label>
                        <input type="text" x-model="dag.modal.agent.model" :disabled="dag.opts.readOnly"
                               class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 font-mono text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:bg-gray-50">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Език на изхода</label>
                        <input type="text" x-model="dag.modal.agent.output_language" :disabled="dag.opts.readOnly"
                               class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:bg-gray-50">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Роля / описание</label>
                    <input type="text" x-model="dag.modal.agent.role" :disabled="dag.opts.readOnly"
                           class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:bg-gray-50">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">System prompt</label>
                    <textarea x-model="dag.modal.agent.system_prompt" rows="4" :disabled="dag.opts.readOnly"
                              class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 font-mono text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:bg-gray-50"></textarea>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Prompt template</label>
                    <textarea x-model="dag.modal.agent.prompt_template" rows="6" :disabled="dag.opts.readOnly"
                              class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 font-mono text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:bg-gray-50"></textarea>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Зависи от <span class="text-gray-400">(uid-и)</span></label>
                        <input type="text" :value="(dag.modal.agent.depends_on || []).join(', ')" disabled
                               class="w-full border border-gray-200 rounded-lg px-2.5 py-1.5 font-mono text-xs bg-gray-50 text-gray-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">uid</label>
                        <input type="text" :value="dag.modal.agent.uid || ''" disabled
                               class="w-full border border-gray-200 rounded-lg px-2.5 py-1.5 font-mono text-xs bg-gray-50 text-gray-500">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Config (JSON)</label>
                    <textarea x-model="dag.modal.configText" rows="5" :disabled="dag.opts.readOnly"
                              class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 font-mono text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:bg-gray-50"></textarea>
                    <p x-show="dag.modal.error" class="text-xs text-red-600 mt-1" x-text="dag.modal.error"></p>
                </div>
            </div>
            <div class="px-5 py-3 border-t border-gray-100 flex justify-end gap-2 shrink-0">
                <button type="button" @click="dag.closeSettings()" class="px-3 py-1.5 rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50 text-sm">Затвори</button>
                <button type="button" x-show="!dag.opts.readOnly" @click="dag.saveSettings()"
                        class="px-4 py-1.5 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 text-sm font-medium">Запази промените</button>
            </div>
        </div>
    </div>
</div>

@once
<script>
window.planDagPreview = function (opts) {
    // Същата layered подредба като applyGeneratedGraph в билдъра (longest-path
    // depth → колони), с по-стегнат ред — картите тук нямат run блок.
    const COL_X0 = 220, COL_W = 340, ROW_Y0 = 40, ROW_H = 150, CARD_W = 280, CARD_H = 88;

    return {
        opts,
        agents: [],
        open: false,
        layout: { nodes: [], edges: [], start: { x: 0, y: 0 }, end: { x: 0, y: 0 }, width: 0, height: 0 },
        modal: { open: false, agent: null, configText: '', error: '' },

        show(agents) {
            this.agents = agents || [];
            this.computeLayout();
            this.open = true;
        },

        computeLayout() {
            // qa_verifier агентите не са възли в графа (inline QA gate).
            const chain = this.agents.filter(a => !(a.is_verifier || a.type === 'qa_verifier'));
            const uidToIdx = {};
            chain.forEach((a, i) => { if (a.uid) uidToIdx[a.uid] = i; });
            const anyDeps = chain.some(a => Array.isArray(a.depends_on) && a.depends_on.length);
            const preds = chain.map((a, i) => {
                if (!anyDeps) return i === 0 ? [] : [i - 1];
                return (a.depends_on || []).map(u => uidToIdx[u]).filter(j => j !== undefined && j !== i);
            });

            const depth = chain.map(() => 0);
            for (let pass = 0; pass < chain.length; pass++) {
                preds.forEach((ps, i) => ps.forEach(p => { depth[i] = Math.max(depth[i], depth[p] + 1); }));
            }

            const rowInCol = {};
            const nodes = [];
            chain.forEach((a, i) => {
                const col = depth[i];
                const row = (rowInCol[col] = (rowInCol[col] || 0) + 1) - 1;
                nodes.push({ agent: a, idx: this.agents.indexOf(a), x: COL_X0 + col * COL_W, y: ROW_Y0 + row * ROW_H });
            });

            const maxCol = chain.length ? Math.max(...depth) : 0;
            const ys = nodes.map(n => n.y);
            const yMid = ys.length ? Math.round((Math.min(...ys) + Math.max(...ys)) / 2) + CARD_H / 2 : 80;
            const start = { x: 40, y: yMid - 16 };
            const end = { x: COL_X0 + (maxCol + 1) * COL_W, y: yMid - 16 };

            // Връзки: preds → възел; roots ← Старт; sinks → Край.
            const edges = [];
            const curve = (x1, y1, x2, y2) => {
                const c = Math.max(40, (x2 - x1) * 0.5);
                return `M ${x1} ${y1} C ${x1 + c} ${y1}, ${x2 - c} ${y2}, ${x2} ${y2}`;
            };
            const hasSucc = chain.map(() => false);
            preds.forEach(ps => ps.forEach(p => { hasSucc[p] = true; }));
            chain.forEach((a, i) => {
                const t = nodes[i];
                if (preds[i].length === 0) {
                    edges.push({ path: curve(start.x + 96, start.y + 16, t.x, t.y + CARD_H / 2) });
                } else {
                    preds[i].forEach(p => {
                        const s = nodes[p];
                        edges.push({ path: curve(s.x + CARD_W, s.y + CARD_H / 2, t.x, t.y + CARD_H / 2) });
                    });
                }
                if (!hasSucc[i]) {
                    edges.push({ path: curve(t.x + CARD_W, t.y + CARD_H / 2, end.x, end.y + 16) });
                }
            });

            const maxRows = Math.max(1, ...Object.values(rowInCol));
            this.layout = {
                nodes,
                edges,
                start,
                end,
                width: end.x + 140,
                height: ROW_Y0 + maxRows * ROW_H + 60,
            };
        },

        typeLabel(type) {
            return (this.opts.agentTypes.find(t => t.type === type) || {}).label || type || 'Агент';
        },

        roleClass(agent) {
            const meta = this.opts.agentTypes.find(t => t.type === agent.type) || {};
            const role = agent.output_role || meta.output_role || 'body';
            return 'dagp-role-' + String(role).toLowerCase().replace(/[^a-z0-9_-]/g, '');
        },

        icon(agent) {
            if (agent.icon && agent.icon !== '🤖') return agent.icon;
            return this.opts.templateIcons[agent.type] || '🤖';
        },

        modelLabel(agent) {
            const m = String(agent.model || '');
            if (!m) return 'авто модел';
            return m.includes('/') ? '⤴ ' + m : m;
        },

        openSettings(idx) {
            this.modal.agent = this.agents[idx];
            this.modal.configText = JSON.stringify(this.modal.agent.config || {}, null, 2);
            this.modal.error = '';
            this.modal.open = true;
        },

        saveSettings() {
            // Редакциите по name/prompts/model са вече в обекта (x-model по
            // референция) — тук само config JSON-ът минава през валидация.
            try {
                this.modal.agent.config = JSON.parse(this.modal.configText || '{}');
            } catch (e) {
                this.modal.error = 'Невалиден JSON: ' + e.message;
                return;
            }
            this.modal.error = '';
            this.modal.open = false;
            this.computeLayout();
        },

        closeSettings() {
            this.modal.open = false;
            this.modal.error = '';
        },
    };
};
</script>
@endonce
