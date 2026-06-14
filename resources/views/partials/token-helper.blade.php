{{--
  Token Helper Panel
  ------------------
  Parameters:
    $textareaId     – static DOM id string (for non-Alpine forms)
    $xTextareaId    – Alpine expression for the id (for Alpine inline editors), e.g. "'sp-show-' + index"
    $agents         – array of agent name strings | null (no-flow / template context)
    $xAgents        – Alpine array variable name, e.g. 'agents' (for Alpine contexts)
    $xCurrentOrder  – Alpine expression for current agent order, e.g. 'agent.order'
--}}
@php
    $isAlpine   = isset($xAgents) && $xAgents;
    $staticId   = $textareaId ?? null;
    $agentCount = isset($agents) && is_array($agents) ? count($agents) : 0;
    $totalStatic = 6 + $agentCount;

    $companyTokens = [
        'company_name'        => 'Името на компанията, взето от профила. Пример: "Иванов и синове ООД". Наличен винаги.',
        'company_description' => 'Пълното описание на дейността, мисията и ценностите на компанията. Взето от профила.',
        'company_industry'    => 'Индустрията / секторът на компанията. Пример: "Технологии", "Финанси", "Търговия на дребно".',
    ];
    $flowTokens = [
        'input'      => 'Текущият вход за агента. В началото е темата на флоуто, след всеки агент се обновява с изхода му. Използвай за последния наличен контекст.',
        'topic'      => 'Алтернативно наименование на {{input}}. Обновява се след всеки агент по същия начин.',
        'flow_topic' => 'Оригиналната тема на флоуто — никога не се сменя, дори след изпълнение на агентите. Използвай когато искаш да се върнеш към първоначалното задание.',
    ];
@endphp

<div
    x-data="{ open: false }"
    @if($isAlpine) :data-tid="{{ $xTextareaId }}" @endif
    class="mt-1.5"
>
    {{-- Toggle button --}}
    <button type="button"
            @click="open = !open"
            class="inline-flex items-center gap-1.5 text-xs text-primary hover:text-primary-hover transition select-none">
        <span x-text="open ? '▼' : '▶'" class="opacity-50 text-[10px]"></span>
        <span>Налични токъни</span>
        @if($isAlpine)
        <span class="bg-primary text-white rounded-full px-1.5 py-0 text-[9px] font-bold leading-4"
              x-text="6 + {{ $xAgents }}.filter(a => a.order < {{ $xCurrentOrder }}).length"></span>
        @else
        <span class="bg-primary text-white rounded-full px-1.5 py-0 text-[9px] font-bold leading-4">{{ $totalStatic }}</span>
        @endif
    </button>

    {{-- Expanded panel --}}
    <div x-show="open" x-cloak
         class="mt-1.5 border border-info rounded-lg overflow-hidden text-sm">

        {{-- Panel header --}}
        <div class="px-3 py-1.5 bg-info-soft flex justify-between items-center border-b border-info">
            <span class="font-semibold text-primary text-xs">НАЛИЧНИ ТОКЪНИ</span>
            <span class="text-primary text-xs">Клик → вмъкни в курсора</span>
        </div>

        <div class="bg-info-soft/40 divide-y divide-line/60">

            {{-- ── COMPANY ──────────────────────────────────────────────── --}}
            <div class="px-3 py-2.5 space-y-1.5">
                <p class="text-[11px] font-bold text-violet-700 uppercase tracking-wider mb-2">🏢 Компания</p>

                @foreach($companyTokens as $tok => $desc)
                @php $display = '{' . '{' . $tok . '}' . '}'; @endphp
                <div class="flex items-start gap-2">
                    @if($isAlpine)
                    <button type="button"
                            @click="insertToken($el.closest('[data-tid]').dataset.tid, '{{ $tok }}')"
                            class="shrink-0 font-mono bg-violet-100 text-violet-800 hover:bg-violet-200 px-1.5 py-0.5 rounded text-xs cursor-pointer transition">{{ $display }}</button>
                    @else
                    <button type="button"
                            onclick="insertToken('{{ $staticId }}', '{{ $tok }}')"
                            class="shrink-0 font-mono bg-violet-100 text-violet-800 hover:bg-violet-200 px-1.5 py-0.5 rounded text-xs cursor-pointer transition">{{ $display }}</button>
                    @endif
                    <span class="text-muted text-xs leading-5 pt-0.5">{{ $desc }}</span>
                </div>
                @endforeach
            </div>

            {{-- ── FLOW INPUT ───────────────────────────────────────────── --}}
            <div class="px-3 py-2.5 space-y-1.5">
                <p class="text-[11px] font-bold text-sky-700 uppercase tracking-wider mb-2">📥 Вход на флоуто</p>

                @foreach($flowTokens as $tok => $desc)
                @php $display = '{' . '{' . $tok . '}' . '}'; @endphp
                <div class="flex items-start gap-2">
                    @if($isAlpine)
                    <button type="button"
                            @click="insertToken($el.closest('[data-tid]').dataset.tid, '{{ $tok }}')"
                            class="shrink-0 font-mono bg-sky-100 text-sky-800 hover:bg-sky-200 px-1.5 py-0.5 rounded text-xs cursor-pointer transition">{{ $display }}</button>
                    @else
                    <button type="button"
                            onclick="insertToken('{{ $staticId }}', '{{ $tok }}')"
                            class="shrink-0 font-mono bg-sky-100 text-sky-800 hover:bg-sky-200 px-1.5 py-0.5 rounded text-xs cursor-pointer transition">{{ $display }}</button>
                    @endif
                    <span class="text-muted text-xs leading-5 pt-0.5">{{ $desc }}</span>
                </div>
                @endforeach
            </div>

            {{-- ── AGENT OUTPUTS ────────────────────────────────────────── --}}
            <div class="px-3 py-2.5">
                <p class="text-[11px] font-bold text-emerald-700 uppercase tracking-wider mb-2">🤖 Изходи от агенти</p>

                @if($isAlpine)
                {{-- Alpine-driven: render chips from Alpine state --}}
                <template x-for="ag in {{ $xAgents }}.filter(a => a.order < {{ $xCurrentOrder }})" :key="ag._uid || ag.order">
                    <div class="flex items-start gap-2 mb-1.5">
                        <button type="button"
                                @click="insertToken($el.closest('[data-tid]').dataset.tid, ag.name)"
                                class="shrink-0 font-mono bg-emerald-100 text-emerald-800 hover:bg-emerald-200 px-1.5 py-0.5 rounded text-xs cursor-pointer transition"
                                x-text="'{' + '{' + ag.name + '}' + '}'"></button>
                        <span class="text-muted text-xs leading-5 pt-0.5">Пълният изход на агент "<span x-text="ag.name"></span>". Достъпен само след като агентът е изпълнен.</span>
                    </div>
                </template>
                <div x-show="{{ $xAgents }}.filter(a => a.order < {{ $xCurrentOrder }}).length === 0"
                     class="text-xs text-subtle italic bg-surface-subtle rounded p-2 border border-dashed border-line">
                    Няма предишни агенти в това флоу. Добави агенти преди текущия за да ползваш техните изходи.
                </div>

                @elseif(isset($agents) && is_array($agents) && count($agents) > 0)
                {{-- Server-side: static list of agent names --}}
                @foreach($agents as $name)
                @php $agDisplay = '{' . '{' . $name . '}' . '}'; @endphp
                <div class="flex items-start gap-2 mb-1.5">
                    <button type="button"
                            onclick="insertToken('{{ $staticId }}', {{ json_encode($name) }})"
                            class="shrink-0 font-mono bg-emerald-100 text-emerald-800 hover:bg-emerald-200 px-1.5 py-0.5 rounded text-xs cursor-pointer transition">{{ $agDisplay }}</button>
                    <span class="text-muted text-xs leading-5 pt-0.5">Пълният изход на агент "{{ $name }}". Достъпен само след като агентът е изпълнен преди текущия.</span>
                </div>
                @endforeach

                @else
                {{-- No flow context (template editing) --}}
                <div class="text-xs text-subtle italic bg-surface-subtle rounded p-2 border border-dashed border-line leading-relaxed">
                    Достъпни само при редакция в контекст на флоу. При добавяне на шаблона към флоу, тук ще се появят
                    <span class="font-mono bg-neutral-soft px-1 rounded not-italic text-muted">{{ '{' . '{' . 'ИмеНаАгент' . '}' . '}' }}</span>
                    токъни за всеки предходен агент.
                </div>
                @endif
            </div>

        </div>
    </div>
</div>
