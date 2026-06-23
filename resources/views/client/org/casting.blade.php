@extends('layouts.client')

@section('title', 'Наеми Управител')

@section('content')
<div class="max-w-5xl mx-auto px-6 py-8" x-data="casting()">
    <header class="mb-8">
        <p class="text-xs font-mono uppercase tracking-wider text-muted mb-1">Стъпка 1 от 3 · Casting</p>
        <h1 class="text-2xl font-semibold text-ink">Наеми своя Управител</h1>
        <p class="text-muted mt-1 max-w-2xl">Управителят е агентът-стратег, който проучва бизнеса ти и проектира
            екипа от Директори и Асистенти. Дай му характер — той оформя <span class="text-ink">как</span> мисли, не колко е способен.</p>
    </header>

    @if ($manager)
        <x-alert class="mb-6">Вече имаш Управител ({{ $manager->display_name }}). Може да го предефинираш или да
            продължиш към <a href="{{ route('client.org.research') }}" class="text-primary font-medium underline">проучването</a>.</x-alert>
    @endif

    <div class="grid lg:grid-cols-[1fr_1.1fr] gap-8">
        {{-- Кандидати-архетипи --}}
        <section>
            <h2 class="text-sm font-semibold text-ink mb-3">Препоръчани характери</h2>
            <div class="space-y-3">
                @foreach ($archetypes as $arch)
                    @php($traits = (array) $arch->traits)
                    <button type="button" @click="pick(@js($arch->name), @js($arch->tone), @js($traits), @js($arch->bio_template))"
                        class="w-full text-left rounded-xl border border-line bg-surface p-4 transition hover:border-line-strong focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                        :class="form.archetype_key === @js($arch->name) ? 'border-primary ring-2 ring-primary/30' : ''">
                        <div class="flex items-center gap-3">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-char-purple-soft text-char-purple-strong font-semibold">
                                {{ mb_substr($arch->name, 0, 1) }}</span>
                            <div class="min-w-0">
                                <p class="font-medium text-ink truncate">{{ $arch->name }}</p>
                                <p class="text-xs text-muted truncate">{{ $arch->tone }}</p>
                            </div>
                        </div>
                        <div class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1.5">
                            @foreach (['risk' => 'Риск', 'creativity' => 'Креативност', 'precision' => 'Прецизност', 'tempo' => 'Темпо'] as $k => $label)
                                @if (isset($traits[$k]))
                                    <div>
                                        <div class="flex justify-between text-[11px] text-muted"><span>{{ $label }}</span><span class="tabular-nums">{{ (int) $traits[$k] }}</span></div>
                                        <div class="h-1.5 rounded-full bg-surface-subtle overflow-hidden"><div class="h-full rounded-full bg-char-purple" style="width: {{ (int) $traits[$k] }}%"></div></div>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </button>
                @endforeach
            </div>
        </section>

        {{-- Форма „създай свой" --}}
        <section>
            <h2 class="text-sm font-semibold text-ink mb-3">Профил на Управителя</h2>
            <form method="POST" action="{{ route('client.org.casting.hire') }}" class="rounded-xl border border-line bg-surface p-5 space-y-4">
                @csrf
                <input type="hidden" name="archetype_key" :value="form.archetype_key">

                <x-field label="Име" required>
                    <x-input name="name" x-model="form.name" required maxlength="80" placeholder="напр. Алекс Иванов" />
                </x-field>

                <div class="grid grid-cols-2 gap-3">
                    <x-field label="Възраст">
                        <x-input type="number" name="age" x-model.number="form.age" @input="reseed()" min="18" max="90" placeholder="28" />
                    </x-field>
                    <x-field label="Пол">
                        <x-select name="gender" x-model="form.gender">
                            <option value="">—</option>
                            <option value="мъж">Мъж</option>
                            <option value="жена">Жена</option>
                        </x-select>
                    </x-field>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <x-field label="Произход">
                        <x-input name="ethnicity" x-model="form.ethnicity" maxlength="40" placeholder="напр. българин" />
                    </x-field>
                    <x-field label="Бекграунд">
                        <x-input name="background" x-model="form.background" maxlength="120" placeholder="напр. growth маркетинг" />
                    </x-field>
                </div>

                <x-field label="Тон">
                    <x-input name="tone" x-model="form.tone" maxlength="80" placeholder="напр. амбициозен, стратегически" />
                </x-field>

                <x-field label="Кратко био">
                    <x-textarea name="bio" x-model="form.bio" rows="2" maxlength="600" placeholder="Един-два реда за характера." />
                </x-field>

                {{-- Черти (барове) — авто-подсказани от възрастта, ръчно коригируеми --}}
                <div>
                    <p class="text-xs font-medium text-muted mb-2">Черти <span class="text-subtle">(подсказват тона — възрастта ги предлага)</span></p>
                    <div class="space-y-2.5">
                        <template x-for="t in traitDefs" :key="t.key">
                            <div>
                                <div class="flex justify-between text-xs"><span class="text-ink" x-text="t.label"></span><span class="tabular-nums text-muted" x-text="form.traits[t.key]"></span></div>
                                <input type="range" min="0" max="100" x-model.number="form.traits[t.key]" :name="'traits[' + t.key + ']'"
                                    class="w-full accent-[var(--color-char-purple)]">
                            </div>
                        </template>
                    </div>
                </div>

                <div class="flex justify-end pt-1">
                    <x-button type="submit" x-bind:disabled="!form.name.trim()">Наеми Управителя</x-button>
                </div>
            </form>
        </section>
    </div>
</div>

@push('scripts')
<script>
function casting() {
    return {
        traitDefs: [
            { key: 'risk', label: 'Риск' },
            { key: 'creativity', label: 'Креативност' },
            { key: 'precision', label: 'Прецизност' },
            { key: 'autonomy', label: 'Автономност' },
            { key: 'tempo', label: 'Темпо' },
        ],
        form: {
            name: '', age: null, gender: '', ethnicity: '', background: '', tone: '', bio: '',
            archetype_key: '',
            traits: { risk: 50, creativity: 50, precision: 50, autonomy: 60, tempo: 55 },
        },
        // Избор на архетип → попълва тон/черти/био.
        pick(name, tone, traits, bio) {
            this.form.archetype_key = name;
            this.form.tone = tone || this.form.tone;
            this.form.bio = bio || this.form.bio;
            for (const k of Object.keys(this.form.traits)) {
                if (traits && traits[k] != null) this.form.traits[k] = parseInt(traits[k]);
            }
        },
        // Възрастта подсказва черти (огледало на seedTraitsFromDemographics — само ориентир).
        reseed() {
            const age = parseInt(this.form.age);
            if (!age) return;
            let t = { risk: 50, creativity: 50, precision: 50, autonomy: 60, tempo: 55 };
            if (age <= 30) { t.risk += 25; t.creativity += 25; t.precision -= 10; t.tempo += 15; }
            else if (age >= 55) { t.risk -= 20; t.creativity -= 5; t.precision += 30; t.tempo -= 10; }
            else if (age >= 45) { t.precision += 12; t.risk -= 8; }
            for (const k of Object.keys(t)) this.form.traits[k] = Math.max(0, Math.min(100, t[k]));
        },
    };
}
</script>
@endpush
@endsection
