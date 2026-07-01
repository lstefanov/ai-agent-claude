<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FlowAI — опиши работата, AI сглобява екипа от агенти</title>
    <meta name="description" content="FlowAI превръща описание на свободен текст в работещ пайплайн от AI агенти — планиран автоматично, изпълнен паралелно, с прозрачна цена и качество на всяка стъпка.">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-canvas text-ink antialiased">

    {{-- Top bar --}}
    <header class="border-b border-line">
        <div class="max-w-6xl mx-auto px-6 h-16 flex items-center justify-between">
            <a href="{{ route('companies.index') }}" class="flex items-center gap-2 text-primary font-display font-bold text-lg tracking-tight">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg>
                FlowAI
            </a>
            <nav class="flex items-center gap-2">
                <x-button variant="ghost" size="sm" :href="route('admin.login')">Admin</x-button>
                <x-button size="sm" icon="arrow-right" :href="route('companies.index')">Отвори платформата</x-button>
            </nav>
        </div>
    </header>

    {{-- Hero --}}
    <section class="max-w-6xl mx-auto px-6 pt-16 pb-20 grid lg:grid-cols-2 gap-12 items-center">
        <div>
            <span class="inline-flex items-center gap-1.5 text-xs font-medium text-muted bg-surface-subtle border border-line rounded-full px-3 py-1">
                <span class="w-1.5 h-1.5 rounded-full bg-accent"></span> Multi-agent AI workflows за бизнеси
            </span>
            <h1 class="mt-5 font-display font-bold text-ink text-4xl sm:text-5xl" style="text-wrap: balance; letter-spacing: -0.03em;">
                Опиши работата.<br>AI сглобява екипа от агенти.
            </h1>
            <p class="mt-5 text-lg text-muted max-w-xl" style="text-wrap: pretty;">
                FlowAI превръща описание на свободен текст в работещ пайплайн от единично-отговорни агенти —
                планиран автоматично като граф, изпълнен с реален паралелизъм, с прозрачна цена и качество на всяка стъпка.
            </p>
            <div class="mt-8 flex flex-wrap items-center gap-3">
                <x-button size="md" icon="sparkles" :href="route('companies.index')">Създай първия си flow</x-button>
                <x-button variant="secondary" size="md" href="#how">Виж как работи</x-button>
            </div>
            <p class="mt-5 text-sm text-subtle flex items-center gap-2">
                <x-icon name="cpu-chip" size="4" /> Локални <span class="font-mono text-xs">(Ollama)</span> + облачни модели · цена на всеки node в реално време
            </p>
        </div>

        {{-- "Control-room" manifest card --}}
        <div class="lg:justify-self-end w-full max-w-md">
            <div class="rounded-xl border border-line bg-surface shadow-card overflow-hidden">
                <div class="flex items-center justify-between px-4 py-2.5 border-b border-line bg-surface-subtle">
                    <div class="flex items-center gap-2 min-w-0">
                        <span class="flex gap-1 shrink-0"><span class="w-2.5 h-2.5 rounded-full bg-line-strong"></span><span class="w-2.5 h-2.5 rounded-full bg-line-strong"></span><span class="w-2.5 h-2.5 rounded-full bg-line-strong"></span></span>
                        <span class="font-mono text-xs text-muted truncate">flow · „FB пост за нов продукт"</span>
                    </div>
                    <x-badge color="accent" icon="arrow-path" :pulse="true">планира</x-badge>
                </div>
                <div class="p-4 font-mono text-xs space-y-2">
                    @php
                    $nodes = [
                        ['site_context',   'готов',        'success', 'check-circle'],
                        ['research · web', 'изпълнява се', 'accent',  'arrow-path'],
                        ['bg_writer',      'на опашка',    'neutral', 'clock'],
                        ['qa_verifier',    'на опашка',    'neutral', 'clock'],
                    ];
                    @endphp
                    @foreach($nodes as [$nodeName, $label, $color, $icon])
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-ink truncate">{{ $nodeName }}</span>
                        <x-badge :color="$color" :icon="$icon" :pulse="$color === 'accent'">{{ $label }}</x-badge>
                    </div>
                    @endforeach
                    <div class="flex items-center justify-between pt-2 mt-1 border-t border-line text-muted">
                        <span>cost so far</span>
                        <span class="text-ink tabular-nums">$0.0123</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- How it works --}}
    <section id="how" class="border-t border-line bg-surface-subtle scroll-mt-8">
        <div class="max-w-6xl mx-auto px-6 py-16">
            <h2 class="font-display font-bold text-2xl text-ink">Как работи</h2>
            <p class="mt-2 text-muted max-w-2xl text-sm">Три стъпки от идея до изпълнен резултат — без да пишеш код.</p>
            <div class="mt-8 grid md:grid-cols-3 gap-5">
                @php
                $steps = [
                    ['pencil-square', 'Опиши flow-а', 'На свободен текст казваш какво трябва да се случи. Колкото по-детайлно, толкова по-добри агенти.'],
                    ['share', 'AI планира графа', 'Планерът („агентът, който създава агенти") проектира DAG от единично-отговорни агенти — за ревю и редакция.'],
                    ['play', 'Изпълни паралелно', 'Графът се изпълнява на вълни с реален паралелизъм; step-QA гейтове, адаптивно препланиране и цена per node.'],
                ];
                @endphp
                @foreach($steps as $i => [$icon, $title, $desc])
                <div class="bg-surface border border-line rounded-xl p-5">
                    <div class="flex items-center gap-3 mb-3">
                        <span class="flex items-center justify-center w-9 h-9 rounded-lg bg-info-soft text-info-strong"><x-icon :name="$icon" size="5" /></span>
                        <span class="font-mono text-xs text-subtle">0{{ $i + 1 }}</span>
                    </div>
                    <h3 class="font-display font-semibold text-ink">{{ $title }}</h3>
                    <p class="mt-1.5 text-sm text-muted">{{ $desc }}</p>
                </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Features (editorial, not a card grid) --}}
    <section class="max-w-6xl mx-auto px-6 py-16">
        <h2 class="font-display font-bold text-2xl text-ink">Какво има отдолу</h2>
        <div class="mt-8 grid md:grid-cols-2 gap-x-12 gap-y-8">
            @php
            $features = [
                ['adjustments-horizontal', 'Контрол върху цената', 'Ниво на разход (low→god) пинва всеки агент към подходящ модел — локален или облачен. Виждаш цената преди да пуснеш и на всяка стъпка след това.'],
                ['shield-check', 'Качество, което се проверява', 'Вграден qa_verifier + step-QA гейтове; при ниски оценки планерът ревизира провалящия агент в движение (адаптивно препланиране).'],
                ['book-open', 'Памет и знание', 'Компанийна база знания (NotebookLM-стил) + памет от предишни изпълнения захранват агентите с достоверен контекст и ги пазят да не се повтарят.'],
                ['puzzle-piece', 'Действа в реални системи', 'MCP конектори към Gmail, Google Sheets, Drive, Docs и Calendar — агентите четат нужната информация и пишат, с одобрение преди запис.'],
            ];
            @endphp
            @foreach($features as [$icon, $title, $desc])
            <div class="flex gap-4">
                <span class="flex items-center justify-center w-10 h-10 rounded-lg bg-surface-subtle border border-line text-primary shrink-0"><x-icon :name="$icon" size="5" /></span>
                <div>
                    <h3 class="font-display font-semibold text-ink">{{ $title }}</h3>
                    <p class="mt-1 text-sm text-muted max-w-md">{{ $desc }}</p>
                </div>
            </div>
            @endforeach
        </div>
    </section>

    {{-- Final CTA --}}
    <section class="border-t border-line">
        <div class="max-w-6xl mx-auto px-6 py-16 flex flex-col items-center text-center">
            <h2 class="font-display font-bold text-2xl sm:text-3xl text-ink" style="letter-spacing: -0.02em;">Готов за първия си агентен flow?</h2>
            <p class="mt-3 text-muted max-w-xl">Добави фирма, опиши какво ти трябва и остави FlowAI да сглоби екипа.</p>
            <div class="mt-7">
                <x-button size="md" icon="arrow-right" :href="route('companies.index')">Отвори платформата</x-button>
            </div>
        </div>
    </section>

    <footer class="border-t border-line">
        <div class="max-w-6xl mx-auto px-6 py-6 flex items-center justify-between text-sm text-subtle">
            <span class="font-display font-semibold text-muted">FlowAI</span>
            <span class="font-mono text-xs">multi-agent workflow engine</span>
        </div>
    </footer>
</body>
</html>
