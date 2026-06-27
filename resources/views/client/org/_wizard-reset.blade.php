{{-- Споделен „Нулирай" контрол за онбординг wizard-а (бързи експерименти).
     Двустъпково потвърждение (без нативен dialog): червен бутон → inline confirm.
     POST към client.org.reset трие org state и връща в casting (стъпка 1).
     Form-ът е извън персона-формата → валиден HTML (без вложени форми). --}}
<div x-data="{ confirm: false }" class="shrink-0">
    {{-- Идле: червен бутон „Нулирай" --}}
    <button type="button" x-show="!confirm" @click="confirm = true"
            class="inline-flex items-center gap-1.5 h-8 px-3 text-xs font-medium rounded-md bg-surface text-danger border border-danger/30 hover:bg-danger-soft hover:border-danger/50 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger/40">
        <x-icon name="arrow-path" size="3.5" /> Нулирай
    </button>

    {{-- Потвърждение --}}
    <div x-show="confirm" x-cloak
         class="inline-flex items-center gap-2 rounded-md border border-danger/30 bg-danger-soft px-2.5 py-1.5">
        <span class="text-xs text-danger-strong">Изтрий Управителя и започни отначало?</span>
        <form method="POST" action="{{ route('client.org.reset') }}" class="inline">
            @csrf
            <button type="submit"
                    class="inline-flex items-center h-7 px-2.5 text-xs font-semibold rounded bg-danger text-white hover:opacity-90 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger/40">
                Да, нулирай
            </button>
        </form>
        <button type="button" @click="confirm = false"
                class="inline-flex items-center h-7 px-2 text-xs text-muted hover:text-ink transition">Отказ</button>
    </div>
</div>
