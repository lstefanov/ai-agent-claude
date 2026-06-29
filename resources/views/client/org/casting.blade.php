@extends('layouts.client')

@section('title', 'Наеми Управител')

@section('content')
<div class="max-w-5xl mx-auto px-6 py-8" x-data="casting()">
    <header class="mb-8 flex items-start justify-between gap-4">
        <div>
            <p class="text-xs font-mono uppercase tracking-wider text-muted mb-1">Стъпка 1 от 3 · Casting</p>
            <h1 class="text-2xl font-semibold text-ink">Наеми своя Управител</h1>
            <p class="text-muted mt-1 max-w-2xl">Управителят е агентът-стратег, който проучва бизнеса ти и проектира
                екипа от Директори и Асистенти. Дай му характер — той оформя <span class="text-ink">как</span> мисли, не колко е способен.</p>
        </div>
        @include('client.org._wizard-reset')
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
                @php($archAccents = ['purple', 'teal', 'blue', 'coral', 'pink'])
                @foreach ($archetypes as $arch)
                    @php($traits = (array) $arch->traits)
                    @php($accent = $archAccents[$loop->index % count($archAccents)])
                    @php($pickData = [
                        'name' => $arch->name,
                        'age' => $arch->age,
                        'gender' => $arch->gender,
                        'ethnicity' => $arch->ethnicity,
                        'background' => $arch->background,
                        'tone' => $arch->tone,
                        'bio' => $arch->bio_template,
                        'traits' => $traits,
                    ])
                    <button type="button" @click="pick(@js($pickData))"
                        class="w-full text-left rounded-xl border border-line bg-surface p-4 transition hover:border-line-strong focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                        :class="form.archetype_key === @js($arch->name) ? 'border-primary ring-2 ring-primary/30' : ''">
                        <div class="flex items-center gap-3">
                            @if ($arch->avatar_url)
                                <img src="{{ $arch->avatar_url }}" alt="{{ $arch->name }}"
                                     class="h-11 w-11 shrink-0 rounded-full object-cover ring-2 ring-char-{{ $accent }}-soft">
                            @else
                                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-char-{{ $accent }}-soft text-char-{{ $accent }}-strong font-semibold">
                                    {{ mb_substr($arch->name, 0, 1) }}</span>
                            @endif
                            <div class="min-w-0">
                                <p class="font-medium text-ink truncate">{{ $arch->name }}</p>
                                <p class="text-xs text-muted truncate">
                                    <x-prose :text="$arch->tone" inline />
                                    @if ($arch->gender || $arch->age)
                                        <span class="text-subtle">· {{ \Illuminate\Support\Str::ucfirst((string) $arch->gender) }}{{ $arch->gender && $arch->age ? ', ' : '' }}{{ $arch->age ? $arch->age.' г.' : '' }}</span>
                                    @endif
                                </p>
                            </div>
                        </div>
                        <div class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1.5">
                            @foreach (config('persona.traits') as $k => $tm)
                                @if (isset($traits[$k]))
                                    <div>
                                        <div class="flex justify-between text-[11px] text-muted"><span>{{ $tm['label'] }}</span><span class="tabular-nums">{{ (int) $traits[$k] }}</span></div>
                                        <div class="h-1.5 rounded-full bg-surface-subtle overflow-hidden"><div class="h-full rounded-full bg-char-{{ $accent }}" style="width: {{ (int) $traits[$k] }}%"></div></div>
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
            <form method="POST" action="{{ route('client.org.casting.hire') }}" @submit.prevent="hire($event)"
                  class="rounded-xl border border-line bg-surface p-5 space-y-4">
                @csrf
                <input type="hidden" name="archetype_key" :value="form.archetype_key">

                @include('client.org._persona-fields', ['modelPrefix' => 'form', 'withNames' => true])

                <div class="pt-1">
                    {{-- Идле: бутон „Наеми" --}}
                    <div class="flex justify-end" x-show="!creating">
                        <x-button type="submit" x-bind:disabled="!form.name.trim()">Наеми Управителя</x-button>
                    </div>

                    {{-- Зает: прогрес бар докато се рендира портретът на Управителя --}}
                    <div x-show="creating" x-cloak class="space-y-2">
                        <div class="flex items-center justify-between text-xs">
                            <span class="inline-flex items-center gap-2 text-muted"><x-org.bolt-spinner size="16" />Създавам Управителя и генерирам неговия аватар…</span>
                            <span class="tabular-nums text-muted" x-text="Math.round(progress) + '%'"></span>
                        </div>
                        <div class="h-2 w-full rounded-full bg-surface-subtle overflow-hidden">
                            <div class="h-full rounded-full bg-primary transition-all duration-300 ease-out"
                                 :style="`width: ${progress}%`"></div>
                        </div>
                    </div>
                </div>
            </form>
        </section>
    </div>
</div>

@push('scripts')
<script>
function casting() {
    return {
        ...window.personaFormBase({
            suggestUrl: '{{ route('client.org.personas.suggest-field') }}',
            csrf: '{{ csrf_token() }}',
            role: 'Управител',
        }),
        aiRole() { return 'Управител'; },
        aiContext() { return this.form; },
        aiApply(field, value) { this.form[field] = value; },
        // Наемане + прогрес (рендирането на портрета на 'org' опашката отнема време).
        creating: false, progress: 0, progressTimer: null, statusTimer: null,
        form: {
            name: '', age: null, gender: '', ethnicity: '', background: '', tone: '', bio: '',
            archetype_key: '',
            traits: { risk: 50, creativity: 50, precision: 50, autonomy: 60, tempo: 55 },
        },
        // Избор на готов кандидат → попълва цялата идентичност (име/демография) + характер,
        // за да наеме точно тази персона (и да преизползва готовия ѝ портрет при наемане).
        pick(a) {
            this.form.archetype_key = a.name;
            if (a.name) this.form.name = a.name;
            if (a.age != null) this.form.age = parseInt(a.age);
            if (a.gender) this.form.gender = a.gender;
            if (a.ethnicity) this.form.ethnicity = a.ethnicity;
            if (a.background) this.form.background = a.background;
            this.form.tone = a.tone || this.form.tone;
            this.form.bio = a.bio || this.form.bio;
            for (const k of Object.keys(this.form.traits)) {
                if (a.traits && a.traits[k] != null) this.form.traits[k] = parseInt(a.traits[k]);
            }
            // Статичните стойности на архетипа са общи → веднага ги специализираме за
            // бизнеса (всяко поле с базата си като seed). При грешка статичното остава.
            this.aiError = '';
            if (a.background) this.aiFill('background', { seed: a.background });
            if (a.tone) this.aiFill('tone', { seed: a.tone });
            if (a.bio) this.aiFill('bio', { seed: a.bio });
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
        // Наема Управителя по AJAX, после чака портрета (прогрес бар) преди проучването.
        hire(e) {
            if (this.creating || !this.form.name.trim()) return;
            this.creating = true; this.progress = 8;
            this.startProgress();
            const research = '{{ route('client.org.research') }}';
            fetch(e.target.action, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                body: new FormData(e.target),
            })
            .then(r => r.json())
            .then(d => {
                if (!d || !d.ok) return this.finishAndGo(research);
                if (d.status === 'ready' || d.status === 'failed' || !d.poll_url) return this.finishAndGo(d.redirect || research);
                this.pollAvatar(d.poll_url, d.redirect || research);
            })
            .catch(() => this.finishAndGo(research));
        },
        // Плавно изкачване към ~90% докато чакаме (асимптотично — никога не „заяжда").
        startProgress() {
            this.progressTimer = setInterval(() => {
                if (this.progress < 90) this.progress += Math.max(0.5, (90 - this.progress) * 0.07);
            }, 400);
        },
        // Поллинг на статуса на аватара; max ~90s таван → продължаваме така или иначе.
        pollAvatar(url, redirect) {
            let waited = 0;
            const tick = async () => {
                waited += 2;
                try {
                    const d = await (await fetch(url, { headers: { 'Accept': 'application/json' } })).json();
                    if (d.status === 'ready' || d.status === 'failed' || waited >= 90) return this.finishAndGo(redirect);
                } catch (_) {
                    if (waited >= 90) return this.finishAndGo(redirect);
                }
            };
            this.statusTimer = setInterval(tick, 2000);
        },
        // Завършва прогреса до 100% и преминава към проучването.
        finishAndGo(redirect) {
            clearInterval(this.progressTimer); clearInterval(this.statusTimer);
            this.progress = 100;
            setTimeout(() => { window.location.href = redirect || '{{ route('client.org.research') }}'; }, 350);
        },
    };
}
</script>
@endpush
@endsection
