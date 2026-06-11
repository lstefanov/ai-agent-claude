{{--
    Per-phase provider/model picker за хибридно планиране + цена на живо.

    Договор: host страницата създава Alpine scope, в който има обект `picker`,
    построен с window.plannerPhasePicker(initialPhases, opts):

        opts = {
            providers:    ['ollama','openai','anthropic','deepseek','gemini','xai','qwen'],
            availability: { provider: bool },
            cloudModels:  { provider: [model, ...] },
            ollamaModels: [{ ollama_tag, display_name }, ...],
            pricing:      { provider: { model: {in, out} } },
        }

    Ползва се от: builder-а (попъп „Генериране на агенти" → Хибрид) и A/B
    страницата (карта „Хибрид" → Конфигурирай).
--}}

<div class="space-y-3">
    <div class="flex items-center justify-between gap-2">
        <div class="text-xs text-gray-400">Провайдър и модел за всяка фаза на планирането.</div>
        <button type="button" @click="picker.randomize()"
                class="shrink-0 text-xs bg-white border border-violet-300 hover:border-violet-500 text-violet-700 font-medium px-2.5 py-1.5 rounded-lg transition"
                title="Умна комбинация: силен модел за дизайна, евтини/безплатни за леките фази, без повторение на една и съща настройка навсякъде.">
            🎲 Генерирай комбинация
        </button>
    </div>

    <template x-for="phase in picker.phaseOrder" :key="phase">
        <div class="border border-gray-200 rounded-xl p-3.5 bg-gray-50/50">
            <div class="flex flex-wrap items-start justify-between gap-2 mb-2">
                <div class="min-w-0">
                    <div class="text-sm font-semibold text-gray-900" x-text="picker.meta[phase].name"></div>
                    <div class="text-xs text-gray-500 mt-0.5" x-text="picker.meta[phase].desc"></div>
                    <div class="text-[11px] text-gray-400 mt-0.5 italic" x-text="picker.meta[phase].example"></div>
                </div>
                <div class="text-xs font-medium tabular-nums shrink-0"
                     :class="picker.phaseCost(phase) === null ? 'text-gray-400' : (picker.phaseCost(phase) > 0 ? 'text-amber-600' : 'text-green-600')"
                     x-text="picker.phaseCostLabel(phase)"></div>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block text-[11px] font-medium text-gray-500 mb-0.5">Провайдър</label>
                    {{-- :selected е задължителен: опциите от x-for се щамповат СЛЕД
                         като x-model е bind-нат, иначе селектът визуално пада на
                         първата опция, въпреки че състоянието е вярно. --}}
                    <select x-model="picker.phases[phase].provider" @change="picker.providerChanged(phase)"
                            class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-violet-500">
                        <template x-for="p in picker.opts.providers" :key="p">
                            <option :value="p" :selected="picker.phases[phase].provider === p"
                                    :disabled="!picker.opts.availability[p]"
                                    x-text="picker.providerLabel(p) + (picker.opts.availability[p] ? '' : ' — недостъпен')"></option>
                        </template>
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] font-medium text-gray-500 mb-0.5">Модел</label>
                    <select x-model="picker.phases[phase].model"
                            class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-violet-500">
                        <template x-for="m in picker.modelsFor(phase)" :key="m.value">
                            <option :value="m.value" :selected="picker.phases[phase].model === m.value"
                                    :title="m.title || ''" x-text="m.label"></option>
                        </template>
                    </select>
                    <p class="text-[11px] text-gray-400 mt-1"
                       x-show="picker.phaseModelHint(phase)"
                       x-text="picker.phaseModelHint(phase)"></p>
                </div>
            </div>
        </div>
    </template>

    <div class="flex items-center justify-between rounded-xl bg-violet-50 border border-violet-200 px-4 py-3">
        <div class="text-sm font-semibold text-violet-900">Приблизителна цена на генерацията</div>
        <div class="text-sm font-bold tabular-nums"
             :class="picker.totalCost() > 0 ? 'text-amber-700' : 'text-green-700'"
             x-text="picker.totalCostLabel()"></div>
    </div>
    <p class="text-[11px] text-gray-400 -mt-1">
        Оценката е по типични обеми токени на фаза (intent ~1.5K/0.8K, design ~9K/8K, critique ~9K/4K) — реалната цена
        зависи от описанието на flow-а. Ревизията е адаптивна (изпълнява се само при нужда по време на run) и не влиза в оценката.
    </p>

    <details class="rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-600">
        <summary class="cursor-pointer select-none font-medium text-gray-800">ℹ Как да комбинирам фазите? Примери</summary>
        <div class="mt-3 space-y-3 text-xs leading-relaxed">
            <p>
                Дизайнът (pipeline design) определя качеството на плана — там си заслужава силен модел. Леките фази
                (intent, critique) се справят отлично и с евтини/безплатни модели, така че хибридът пести пари без
                да губи качество.
            </p>
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                <div class="rounded-lg bg-gray-50 border border-gray-100 p-3">
                    <div class="font-semibold text-gray-800 mb-1">⭐ Препоръчан хибрид (~$0.09)</div>
                    <div class="font-mono text-[11px] text-gray-600">design = anthropic<br>intent = gemini<br>critique = gemini<br>revision = openai</div>
                    <div class="text-gray-400 mt-1">Плаща се само Claude дизайнът; останалото е безплатно.</div>
                </div>
                <div class="rounded-lg bg-gray-50 border border-gray-100 p-3">
                    <div class="font-semibold text-gray-800 mb-1">🆓 Изцяло безплатно ($0)</div>
                    <div class="font-mono text-[11px] text-gray-600">всички фази = gemini<br><span class="text-gray-400">или ollama (локално)</span></div>
                    <div class="text-gray-400 mt-1">$0, но по-слаб дизайн от Claude/GPT-4o.</div>
                </div>
                <div class="rounded-lg bg-gray-50 border border-gray-100 p-3">
                    <div class="font-semibold text-gray-800 mb-1">💧 Почти безплатно (~$0.005)</div>
                    <div class="font-mono text-[11px] text-gray-600">всички фази = deepseek</div>
                    <div class="text-gray-400 mt-1">Стабилно качество на минимална цена.</div>
                </div>
                <div class="rounded-lg bg-gray-50 border border-gray-100 p-3">
                    <div class="font-semibold text-gray-800 mb-1">🏠 Локален + Claude дизайн (~$0.13)</div>
                    <div class="font-mono text-[11px] text-gray-600">design = anthropic<br>intent = ollama (qwen3:14b)<br>critique = ollama (qwen3:14b)<br>revision = ollama</div>
                    <div class="text-gray-400 mt-1">Леките фази остават на твоя хардуер — нула данни към облака извън дизайна.</div>
                </div>
                <div class="rounded-lg bg-gray-50 border border-gray-100 p-3">
                    <div class="font-semibold text-gray-800 mb-1">🔑 Само OpenAI (~$0.07)</div>
                    <div class="font-mono text-[11px] text-gray-600">design = openai (gpt-4o)<br>останалите = openai:gpt-4o-mini</div>
                    <div class="text-gray-400 mt-1">Един API ключ, предвидимо качество, без втора регистрация.</div>
                </div>
                <div class="rounded-lg bg-gray-50 border border-gray-100 p-3">
                    <div class="font-semibold text-gray-800 mb-1">💎 Максимално качество (~$0.31)</div>
                    <div class="font-mono text-[11px] text-gray-600">всички фази = anthropic<br>(claude-sonnet-4-6)</div>
                    <div class="text-gray-400 mt-1">Най-скъпият вариант — ползвай го като еталон при сравнение на шаблони.</div>
                </div>
                <div class="rounded-lg bg-gray-50 border border-gray-100 p-3">
                    <div class="font-semibold text-gray-800 mb-1">⚡ Само Grok (~$0.04)</div>
                    <div class="font-mono text-[11px] text-gray-600">design = xai (grok-4.3)<br>останалите = xai (grok-4.1-fast)</div>
                    <div class="text-gray-400 mt-1">Един API ключ, топ мултиезичност и 1–2M контекст за дълги описания.</div>
                </div>
                <div class="rounded-lg bg-gray-50 border border-gray-100 p-3">
                    <div class="font-semibold text-gray-800 mb-1">🐉 Само Qwen (~$0.02)</div>
                    <div class="font-mono text-[11px] text-gray-600">design = qwen (qwen3.7-plus)<br>останалите = qwen (qwen3.5-flash)</div>
                    <div class="text-gray-400 mt-1">Най-евтиният изцяло платен стек — флагман дизайн на цена на кафе.</div>
                </div>
                <div class="rounded-lg bg-gray-50 border border-gray-100 p-3">
                    <div class="font-semibold text-gray-800 mb-1">🧬 Бюджетен микс (~$0.015)</div>
                    <div class="font-mono text-[11px] text-gray-600">design = deepseek (v4-pro)<br>intent/critique = qwen (qwen3.5-flash)<br>revision = xai (grok-4.1-fast)</div>
                    <div class="text-gray-400 mt-1">Reasoning дизайн почти без пари; леките фази на ultra-cheap модели.</div>
                </div>
            </div>
            <p class="text-gray-400">
                Същите комбинации могат да се запишат за постоянно в <code class="bg-gray-100 px-1 rounded">.env</code>
                чрез <code class="bg-gray-100 px-1 rounded">PLANNER_*_PROVIDER</code> / <code class="bg-gray-100 px-1 rounded">PLANNER_*_MODEL</code>.
            </p>
        </div>
    </details>
</div>

@once
<script>
// Фабрика за picker състоянието (вграждат го builder-ът и A/B страницата като
// под-обект на своя Alpine компонент). Токен евристики per фаза за оценката.
window.plannerPhaseTokens = {
    intent_analysis: { in: 1500, out: 800 },
    pipeline_design: { in: 9000, out: 8000 },
    plan_critique: { in: 9000, out: 4000 },
    // agent_revision: адаптивна — не влиза в оценката на генерацията.
};

// Приблизителна цена за phases-map ({phase: {provider, model}}) — JS двойник
// на LlmUsage::costFor (exact → prefix match; липсваща цена = безплатно).
window.plannerCostEstimate = function (phases, pricing) {
    let total = 0;
    for (const phase in window.plannerPhaseTokens) {
        const spec = phases[phase];
        if (!spec) continue;
        const tokens = window.plannerPhaseTokens[phase];
        const table = (pricing || {})[spec.provider] || {};
        let price = table[spec.model] || null;
        if (!price) {
            for (const tag in table) {
                if (String(spec.model || '').startsWith(tag)) { price = table[tag]; break; }
            }
        }
        if (!price) continue; // ollama / непознат модел → безплатно
        total += (tokens.in / 1e6) * (price.in || 0) + (tokens.out / 1e6) * (price.out || 0);
    }
    return total;
};

window.plannerPhasePicker = function (initialPhases, opts) {
    const phaseOrder = ['intent_analysis', 'pipeline_design', 'plan_critique', 'agent_revision'];
    const phases = {};
    for (const phase of phaseOrder) {
        const spec = (initialPhases || {})[phase] || {};
        phases[phase] = { provider: spec.provider || 'openai', model: spec.model || '' };
    }

    return {
        opts,
        phases,
        phaseOrder,
        meta: {
            intent_analysis: {
                name: '1. Анализ на интента',
                desc: 'Чете описанието на flow-а и извлича какво точно се иска: краен резултат, език, източници, ключови задачи, сложност.',
                example: 'Лека фаза — евтин/безплатен модел е напълно достатъчен.',
            },
            pipeline_design: {
                name: '2. Дизайн на pipeline-а',
                desc: 'Най-тежката фаза: проектира самите агенти — роли, промптове, инструменти, зависимости (DAG). Качеството на плана идва основно от тук.',
                example: 'Силен модел (напр. Claude Sonnet) дава най-добрия дизайн.',
            },
            plan_critique: {
                name: '3. Критика на плана',
                desc: 'Втори преглед: намира пропуски, цикли и висящи връзки; поправя ги или одобрява плана.',
                example: 'Лека фаза — евтин/безплатен модел върши работа.',
            },
            agent_revision: {
                name: '4. Ревизия на агент (адаптивна)',
                desc: 'Изпълнява се САМО по време на run, когато агент се проваля на QA gate — планерът пренаписва промптовете/модела му.',
                example: 'Не влиза в цената на генерацията; ползва се рядко.',
            },
        },

        providerLabel(p) {
            return {
                ollama: '🦙 Ollama (локален)',
                openai: '🤖 OpenAI',
                anthropic: '🧠 Anthropic',
                deepseek: '🐋 DeepSeek',
                gemini: '✨ Gemini',
                xai: '⚡ Grok (xAI)',
                qwen: '🐉 Qwen (Alibaba)',
            }[p] || p;
        },

        // Колко е подходящ Ollama модел за дадена фаза: 3 = отличен, 2 = добър,
        // 1 = слаб. Детерминистична евристика по category + strengths от
        // llm_models — всички фази искат стриктен structured/JSON изход, а
        // дизайнът освен това иска силен reasoning модел.
        rateForPhase(m, phase) {
            const strengths = m.strengths || [];
            const has = s => strengths.includes(s);

            if (phase === 'pipeline_design') {
                if ((has('reasoning') || has('thinking')) && has('powerful')) return 3;
                if (has('reasoning') || has('thinking') || ['json', 'reasoning'].includes(m.category)) return 2;
                return 1;
            }

            if (m.category === 'json' || has('structured_tasks') || has('json_output') || has('structured_output') || has('instruction_following')) return 3;
            if (['reasoning', 'general', 'qa'].includes(m.category)) return 2;
            return 1;
        },

        ratingStars(rate) {
            return { 3: '★★★', 2: '★★', 1: '★' }[rate] || '★';
        },

        ratingWord(rate) {
            return { 3: 'Отличен', 2: 'Добър', 1: 'Слаб' }[rate] || '';
        },

        // Ред под модел-селекта (само при ollama): оценка за ТАЗИ фаза + силни страни.
        ollamaHint(phase) {
            const spec = this.phases[phase];
            if (spec.provider !== 'ollama') return '';
            const m = (this.opts.ollamaModels || []).find(x => x.ollama_tag === spec.model);
            if (!m) return '';
            const r = this.rateForPhase(m, phase);
            const top = (m.strengths || []).slice(0, 3).map(s => s.replace(/_/g, ' ')).join(', ');
            return this.ratingStars(r) + ' ' + this.ratingWord(r) + ' за тази фаза' + (top ? ' — ' + top : '');
        },

        // Ред под модел-селекта на фаза (всички провайдъри): оценка/описание
        // на избрания модел + цена за тази фаза.
        phaseModelHint(phase) {
            const spec = this.phases[phase];
            if (!spec.model) return '';
            if (spec.provider === 'ollama') {
                const hint = this.ollamaHint(phase);
                return hint ? hint + ' · ' + this.phaseCostLabel(phase) : '';
            }
            const info = this.cloudInfo(spec.provider, spec.model) || {};
            if (!info.stars && !info.desc) return '';
            return (info.stars ? this.ratingStars(info.stars) + ' ' : '')
                + (info.desc ? info.desc + ' · ' : '')
                + this.phaseCostLabel(phase);
        },

        // UI метаданни за cloud модел от pricing таблицата (exact → prefix
        // match, както LlmUsage::costFor): {stars, desc, in, out} или null.
        cloudInfo(provider, model) {
            const table = (this.opts.pricing || {})[provider] || {};
            if (table[model]) return table[model];
            for (const tag in table) {
                if (String(model || '').startsWith(tag)) return table[tag];
            }
            return null;
        },

        // Приблизителна цена на ЦЯЛА генерация (intent+design+critique) с един
        // и същ модел навсякъде — за опциите на single-provider dropdown-ите.
        fullGenCost(provider, model) {
            const spec = { provider, model };
            return window.plannerCostEstimate(
                { intent_analysis: spec, pipeline_design: spec, plan_critique: spec },
                this.opts.pricing,
            );
        },

        fullGenCostLabel(provider, model) {
            const cost = this.fullGenCost(provider, model);
            return cost > 0 ? '~$' + cost.toFixed(4) : 'безплатно';
        },

        // Опции за single-provider избор (карта на A/B страницата, builder
        // попъп): целият план върви на модела, затова ollama се оценява по
        // най-тежката фаза (pipeline_design), а cloud носи звезди + цена на
        // генерацията. Текущо избран модел извън списъка (custom .env
        // override) се добавя най-отгоре.
        singleModelOptions(provider, current) {
            let list;
            if (provider === 'ollama') {
                list = (this.opts.ollamaModels || [])
                    .map(m => ({
                        value: m.ollama_tag,
                        label: m.display_name + ' (' + m.ollama_tag + ') · ' + this.ratingStars(this.rateForPhase(m, 'pipeline_design')),
                        title: this.ratingWord(this.rateForPhase(m, 'pipeline_design')) + ' за планиране — ' + (m.strengths || []).map(s => s.replace(/_/g, ' ')).join(', '),
                        _rate: this.rateForPhase(m, 'pipeline_design'),
                    }))
                    .sort((a, b) => b._rate - a._rate);
            } else {
                list = (this.opts.cloudModels[provider] || []).map(m => {
                    const info = this.cloudInfo(provider, m) || {};
                    return {
                        value: m,
                        label: m + (info.stars ? ' · ' + this.ratingStars(info.stars) : '') + ' · ' + this.fullGenCostLabel(provider, m),
                        title: info.desc || '',
                    };
                });
            }
            if (current && !list.some(m => m.value === current)) {
                list.unshift({ value: current, label: current });
            }
            return list;
        },

        // Ред под single-provider селект: описание/оценка + цена на генерацията.
        singleModelHint(provider, model) {
            if (!model) return '';
            if (provider === 'ollama') {
                const m = (this.opts.ollamaModels || []).find(x => x.ollama_tag === model);
                if (!m) return '';
                const r = this.rateForPhase(m, 'pipeline_design');
                const top = (m.strengths || []).slice(0, 3).map(s => s.replace(/_/g, ' ')).join(', ');
                return this.ratingStars(r) + ' ' + this.ratingWord(r) + ' за планиране' + (top ? ' — ' + top : '') + ' · безплатно';
            }
            const info = this.cloudInfo(provider, model) || {};
            return (info.stars ? this.ratingStars(info.stars) + ' ' : '')
                + (info.desc ? info.desc + ' · ' : '')
                + this.fullGenCostLabel(provider, model);
        },

        // Каскадни опции: cloud моделите от pricing-а / ollama от llm_models
        // (сортирани по оценка за фазата, най-подходящите първи). Текущо избран
        // модел извън списъка (custom .env override) се добавя.
        modelsFor(phase) {
            const spec = this.phases[phase];
            let list;
            if (spec.provider === 'ollama') {
                list = (this.opts.ollamaModels || [])
                    .map(m => ({
                        value: m.ollama_tag,
                        label: m.display_name + ' (' + m.ollama_tag + ') · ' + this.ratingStars(this.rateForPhase(m, phase)),
                        title: this.ratingWord(this.rateForPhase(m, phase)) + ' за тази фаза — ' + (m.strengths || []).map(s => s.replace(/_/g, ' ')).join(', '),
                        _rate: this.rateForPhase(m, phase),
                    }))
                    .sort((a, b) => b._rate - a._rate);
            } else {
                list = (this.opts.cloudModels[spec.provider] || []).map(m => {
                    const info = this.cloudInfo(spec.provider, m) || {};
                    return {
                        value: m,
                        label: m + (info.stars ? ' · ' + this.ratingStars(info.stars) : ''),
                        title: info.desc || '',
                    };
                });
            }
            if (spec.model && !list.some(m => m.value === spec.model)) {
                list.unshift({ value: spec.model, label: spec.model });
            }
            return list;
        },

        providerChanged(phase) {
            // Първо нулирай модела: modelsFor() unshift-ва текущия (вече стар)
            // модел като custom опция и той би останал „пръв и избран".
            this.phases[phase].model = '';
            const first = this.modelsFor(phase)[0];
            this.phases[phase].model = first ? first.value : '';
        },

        // 🎲 Умна комбинация: силен модел за дизайна, евтини/безплатни за
        // леките фази, само налични провайдъри, никога 4 еднакви настройки.
        randomize() {
            const avail = p => !!this.opts.availability[p];
            const pick = pool => pool[Math.floor(Math.random() * pool.length)];
            const cloudDefault = p => ({ provider: p, model: (this.opts.cloudModels[p] || [])[0] || '' });
            // Ollama кандидати само с оценка ★★★ за съответната фаза — слаб
            // локален модел (coder/vision/...) никога не влиза в комбинацията.
            const ollamaTop = phase => (this.opts.ollamaModels || [])
                .filter(m => this.rateForPhase(m, phase) === 3)
                .map(m => ({ provider: 'ollama', model: m.ollama_tag }));

            let designPool = [];
            for (const p of ['anthropic', 'openai', 'deepseek', 'gemini', 'xai', 'qwen']) {
                if (avail(p)) designPool.push(cloudDefault(p));
            }
            if (avail('ollama')) designPool = designPool.concat(ollamaTop('pipeline_design'));
            if (!designPool.length) return;

            const lightPoolFor = phase => {
                let pool = [];
                if (avail('gemini')) pool.push(cloudDefault('gemini'));
                if (avail('deepseek')) {
                    // Леките фази не им трябва V4-Pro — предпочети flash варианта.
                    const flash = (this.opts.cloudModels.deepseek || []).find(m => m.includes('flash'));
                    pool.push(flash ? { provider: 'deepseek', model: flash } : cloudDefault('deepseek'));
                }
                if (avail('qwen')) {
                    const flash = (this.opts.cloudModels.qwen || []).find(m => m.includes('flash'));
                    pool.push(flash ? { provider: 'qwen', model: flash } : cloudDefault('qwen'));
                }
                if (avail('xai')) {
                    const fast = (this.opts.cloudModels.xai || []).find(m => m.includes('fast'));
                    pool.push(fast ? { provider: 'xai', model: fast } : cloudDefault('xai'));
                }
                if (avail('openai')) {
                    const mini = (this.opts.cloudModels.openai || []).find(m => m.includes('mini'));
                    if (mini) pool.push({ provider: 'openai', model: mini });
                }
                if (avail('ollama')) pool = pool.concat(ollamaTop(phase));
                return pool;
            };

            this.phases.pipeline_design = { ...pick(designPool) };
            for (const phase of ['intent_analysis', 'plan_critique', 'agent_revision']) {
                const pool = lightPoolFor(phase);
                this.phases[phase] = pool.length ? { ...pick(pool) } : { ...this.phases.pipeline_design };
            }

            // Guard: всичките 4 фази на едно и също provider:model е анти-пример.
            const key = s => s.provider + ':' + (s.model || '');
            const designKey = key(this.phases.pipeline_design);
            if (this.phaseOrder.every(ph => key(this.phases[ph]) === designKey)) {
                const alt = lightPoolFor('intent_analysis').filter(o => key(o) !== designKey);
                if (alt.length) this.phases.intent_analysis = { ...pick(alt) };
            }
        },

        phaseCost(phase) {
            if (!window.plannerPhaseTokens[phase]) return null;
            return window.plannerCostEstimate({ [phase]: this.phases[phase] }, this.opts.pricing);
        },

        phaseCostLabel(phase) {
            const cost = this.phaseCost(phase);
            if (cost === null) return 'при нужда';
            return cost > 0 ? '~$' + cost.toFixed(4) : 'безплатно';
        },

        totalCost() {
            return window.plannerCostEstimate(this.phases, this.opts.pricing);
        },

        totalCostLabel() {
            const cost = this.totalCost();
            return cost > 0 ? '~$' + cost.toFixed(4) : 'безплатно';
        },

        // POST payload — празен модел пътува като null (default на провайдъра).
        payload() {
            const out = {};
            for (const phase of this.phaseOrder) {
                out[phase] = {
                    provider: this.phases[phase].provider,
                    model: this.phases[phase].model || null,
                };
            }
            return out;
        },
    };
};
</script>
@endonce
