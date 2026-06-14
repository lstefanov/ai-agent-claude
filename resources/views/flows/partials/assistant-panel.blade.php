{{-- Builder Copilot: плаващ бутон + чат drawer върху канваса. Споделя Alpine
     scope-а на flowBuilder() — включва се вътре в канвас контейнера. --}}
<button type="button" @click="toggleChat()" x-show="!chat.open"
        class="absolute right-4 bottom-4 z-30 w-12 h-12 rounded-full bg-primary text-primary-fg shadow-lg hover:bg-primary-hover hover:scale-105 transition flex items-center justify-center"
        title="Асистент на builder-а" aria-label="Асистент на builder-а"><x-icon name="sparkles" size="6" /></button>

<div x-show="chat.open" x-cloak
     x-transition:enter="transition ease-out duration-150"
     x-transition:enter-start="translate-x-6 opacity-0"
     x-transition:enter-end="translate-x-0 opacity-100"
     class="absolute inset-y-0 right-0 z-30 w-[410px] max-w-[92vw] bg-surface border-l border-line shadow-2xl flex flex-col">

    {{-- Хедър --}}
    <div class="px-4 py-2.5 border-b border-line flex items-center justify-between bg-info-soft shrink-0">
        <div class="flex items-center gap-2 min-w-0">
            <span class="flex items-center justify-center w-7 h-7 rounded-lg bg-surface text-primary shrink-0"><x-icon name="sparkles" size="4" /></span>
            <div class="min-w-0">
                <div class="text-sm font-semibold text-ink">Асистент</div>
                <div class="text-[11px] text-muted truncate"
                     x-text="mode === 'edit' ? 'Чете, съветва и предлага промени по графа' : 'Режимът е само за четене — съвети без промени'"></div>
            </div>
        </div>
        <div class="flex items-center gap-0.5 shrink-0">
            <button @click="newChat()" type="button"
                    class="w-7 h-7 rounded-lg text-subtle hover:text-muted hover:bg-surface"
                    title="Нов разговор">⟳</button>
            <button @click="chat.open = false" type="button"
                    class="w-7 h-7 rounded-lg text-subtle hover:text-muted hover:bg-surface"
                    title="Затвори">✕</button>
        </div>
    </div>

    {{-- Съобщения --}}
    <div class="flex-1 overflow-y-auto px-3 py-3 space-y-2.5" x-ref="chatScroll">
        <template x-if="!chat.messages.length && !chat.sending">
            <div class="text-center mt-8 space-y-2">
                <div class="flex justify-center mb-1"><span class="flex items-center justify-center w-12 h-12 rounded-xl bg-info-soft text-info-strong"><x-icon name="sparkles" size="6" /></span></div>
                <p class="text-sm text-muted px-5">Питай ме за flow-а, агентите, настройките или старите изпълнения — в режим редакция мога и да променям графа.</p>
                <div class="flex flex-col gap-1.5 px-2 pt-2">
                    <template x-for="s in chatSuggestions" :key="s">
                        <button @click="sendChat(s)" type="button"
                                class="text-left text-xs px-3 py-2 rounded-lg border border-info bg-info-soft text-primary hover:bg-info-soft"
                                x-text="s"></button>
                    </template>
                </div>
            </div>
        </template>

        <template x-for="(m, i) in chat.messages" :key="i">
            <div :class="m.role === 'user' ? 'flex justify-end' : 'flex justify-start'">
                <div class="max-w-[88%] px-3 py-2 text-sm rounded-2xl"
                     :class="m.role === 'user'
                        ? 'bg-primary text-white rounded-br-sm'
                        : (m.failed
                            ? 'bg-red-50 border border-red-200 text-red-700 rounded-bl-sm'
                            : 'bg-neutral-soft text-ink rounded-bl-sm')">
                    <div class="break-words [&_code]:font-mono" x-html="m.role === 'user' ? escapeChat(m.content) : chatMd(m.content)"></div>
                    <div x-show="m.role === 'assistant' && (m.cost_usd || m.hasOps)"
                         class="mt-1.5 pt-1 border-t border-line/70 flex items-center gap-2 text-[10px] text-subtle">
                        <span x-show="m.hasOps" class="text-primary font-semibold">✏ предложени промени — прегледай и запази</span>
                        <span x-show="m.cost_usd" x-text="m.cost_usd ? ('$' + Number(m.cost_usd).toFixed(4)) : ''"></span>
                    </div>
                    <button x-show="m.failed && !chat.sending && (m.retryText || chat.messages[i-1]?.role === 'user')"
                            @click="sendChat(m.retryText || chat.messages[i-1].content)" type="button"
                            class="mt-1.5 text-xs font-bold text-red-700 underline underline-offset-2 hover:text-red-900">↻ Опитай отново</button>
                </div>
            </div>
        </template>

        <div x-show="chat.sending" x-cloak class="flex justify-start">
            <div class="bg-neutral-soft rounded-2xl rounded-bl-sm px-3 py-2 text-sm max-w-[88%]">
                {{-- Pseudo-streaming: частичният текст от стъпките на turn-а расте тук --}}
                <div x-show="chat.partial" class="text-ink break-words [&_code]:font-mono mb-1.5"
                     x-html="chatMd(chat.partial)"></div>
                <div class="text-muted flex items-center gap-2">
                    <span class="inline-block w-3.5 h-3.5 border-2 border-primary border-t-transparent rounded-full animate-spin"></span>
                    <span x-text="chat.stage || 'Мисля…'"></span>
                </div>
            </div>
        </div>
    </div>

    {{-- Композер --}}
    <div class="border-t border-line p-3 shrink-0 bg-surface">
        <form @submit.prevent="sendChat()">
            <div class="flex items-end gap-2">
                <textarea x-model="chat.input" rows="2"
                          placeholder="Питай или поискай промяна… (Enter изпраща)"
                          @keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); sendChat(); }"
                          class="flex-1 text-sm border border-line rounded-xl px-3 py-2 resize-none focus:ring-primary/40 focus:border-primary"></textarea>
                <button type="submit" :disabled="chat.sending || !chat.input.trim()"
                        class="px-3.5 py-2.5 rounded-xl bg-primary text-primary-fg text-sm font-medium hover:bg-primary-hover disabled:opacity-40 inline-flex items-center"
                        title="Изпрати" aria-label="Изпрати"><x-icon name="paper-airplane" size="4" /></button>
            </div>
        </form>
    </div>
</div>
