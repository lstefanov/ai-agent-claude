{{-- Споделен „Нулирай" контрол за онбординг wizard-а (бързи експерименти).
     Двустъпково потвърждение (без нативен dialog): червен бутон → dropdown confirm.
     POST към client.org.reset трие ВСИЧКО за компанията (освен Знания) и връща в
     casting (стъпка 1). Form-ът е извън персона-формата → валиден HTML (без вложени форми). --}}
<div x-data="{ confirm: false, submitting: false }"
     class="relative shrink-0"
     @keydown.escape.window="confirm = false">
    {{-- Идле: червен бутон „Нулирай" --}}
    <button type="button" x-show="!confirm" @click="confirm = true"
            class="inline-flex items-center gap-1.5 h-8 px-3 text-xs font-medium rounded-md bg-surface text-danger border border-danger/30 hover:bg-danger-soft hover:border-danger/50 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger/40">
        <x-icon name="arrow-path" size="3.5" /> Нулирай
    </button>

    {{-- Потвърждение — dropdown под бутона (не се смачква в тесен header) --}}
    <div x-show="confirm" x-cloak
         @click.outside="confirm = false"
         class="absolute right-0 top-full z-50 mt-2 w-72 sm:w-80 rounded-lg border border-danger/30 bg-surface shadow-popover p-3 space-y-3">
        <p class="text-xs text-danger-strong leading-snug">
            Изтрий ВСИЧКО за компанията (org, flows, предложения, билинг, интеграции) освен Знания?
        </p>
        <div class="flex items-center gap-2">
            <form method="POST" action="{{ route('client.org.reset') }}" class="inline"
                  @submit="submitting = true">
                @csrf
                <button type="submit" :disabled="submitting"
                        class="inline-flex items-center gap-1.5 h-7 px-2.5 text-xs font-semibold rounded bg-danger text-white hover:opacity-90 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger/40 disabled:opacity-60 disabled:pointer-events-none">
                    <span x-show="!submitting">Да, нулирай</span>
                    <span x-show="submitting" x-cloak class="inline-flex items-center gap-1.5">
                        <x-org.bolt-spinner size="12" /> Нулирам…
                    </span>
                </button>
            </form>
            <button type="button" @click="confirm = false"
                    class="inline-flex items-center h-7 px-2 text-xs text-muted hover:text-ink transition">Отказ</button>
        </div>
    </div>
</div>
