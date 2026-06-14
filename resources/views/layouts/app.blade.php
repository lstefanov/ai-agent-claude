<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'FlowAI') — FlowAI</title>
    {{-- Fonts: IBM Plex Sans (display + body) · JetBrains Mono --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    {{-- Tailwind v4 + app assets via Vite (replaces the old Tailwind play CDN) --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <link href="https://cdn.jsdelivr.net/npm/tom-select@2/dist/css/tom-select.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2/dist/js/tom-select.complete.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/focus@3.x.x/dist/cdn.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @stack('head')
</head>
<body class="bg-surface-subtle text-ink min-h-screen antialiased">

    {{-- Skip link --}}
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:top-2 focus:left-2 focus:bg-surface focus:text-ink focus:px-3 focus:py-2 focus:rounded-md focus:shadow-popover">
        Към съдържанието
    </a>

    {{-- Navigation --}}
    @php
        $navItems = [
            'Фирми'      => ['route' => 'companies.index', 'match' => 'companies.*'],
            'LLM Модели' => ['route' => 'models.index',    'match' => 'models.*'],
            'Admin'      => ['route' => 'admin.login',     'match' => 'admin.*'],
        ];
    @endphp
    <nav class="bg-surface border-b border-line sticky top-0 z-40" x-data="{ open: false }">
        <div class="max-w-7xl mx-auto px-6 flex items-stretch justify-between h-16">
            <a href="{{ route('companies.index') }}"
               class="flex items-center gap-2 text-primary font-display font-bold text-lg tracking-tight hover:text-primary-hover transition rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg>
                FlowAI
            </a>

            {{-- Desktop nav --}}
            <div class="hidden md:flex items-stretch gap-1 text-sm font-medium">
                @foreach($navItems as $label => $item)
                    <a href="{{ route($item['route']) }}"
                       @if(request()->routeIs($item['match'])) aria-current="page" @endif
                       class="flex items-center px-4 border-b-2 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40
                              {{ request()->routeIs($item['match'])
                                 ? 'border-primary text-primary font-semibold'
                                 : 'border-transparent text-muted hover:text-ink hover:border-line-strong' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>

            {{-- Mobile toggle --}}
            <button type="button" class="md:hidden inline-flex items-center text-muted hover:text-ink rounded-md px-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                    @click="open = !open" :aria-expanded="open" aria-label="Меню">
                <x-icon name="bars-3" x-show="!open" />
                <x-icon name="x-mark" x-show="open" x-cloak />
            </button>
        </div>

        {{-- Mobile drawer --}}
        <div class="md:hidden border-t border-line bg-surface" x-show="open" x-cloak
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 -translate-y-1"
             x-transition:enter-end="opacity-100 translate-y-0">
            <div class="px-4 py-2 space-y-1">
                @foreach($navItems as $label => $item)
                    <a href="{{ route($item['route']) }}"
                       @if(request()->routeIs($item['match'])) aria-current="page" @endif
                       class="block px-3 py-2 rounded-md text-sm font-medium transition
                              {{ request()->routeIs($item['match'])
                                 ? 'bg-info-soft text-primary'
                                 : 'text-muted hover:text-ink hover:bg-surface-subtle' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </div>
    </nav>

    {{-- Main Content --}}
    <main id="main" class="max-w-7xl mx-auto px-6 py-8">

        {{-- Flash messages --}}
        @if(session('success'))
            <x-alert type="success" :timeout="5000" class="mb-6">{{ session('success') }}</x-alert>
        @endif

        @if(session('error'))
            <x-alert type="error" :timeout="8000" class="mb-6">{{ session('error') }}</x-alert>
        @endif

        @if($errors->any())
            <x-alert type="error" :dismissible="false" class="mb-6">
                <p class="font-medium mb-1">Моля, поправи грешките:</p>
                <ul class="list-disc list-inside space-y-1">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-alert>
        @endif

        @yield('content')
    </main>

    @stack('scripts')
<script>
function insertToken(id, token) {
    const el = document.getElementById(id);
    if (!el) return;
    el.focus();
    const start = el.selectionStart ?? el.value.length;
    const end   = el.selectionEnd   ?? el.value.length;
    el.value = el.value.slice(0, start) + '{' + '{' + token + '}' + '}' + el.value.slice(end);
    const pos = start + token.length + 4;
    el.selectionStart = el.selectionEnd = pos;
    el.dispatchEvent(new Event('input', { bubbles: true }));
}
</script>
</body>
</html>
