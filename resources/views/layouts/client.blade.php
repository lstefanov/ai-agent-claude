<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Портал') — FlowAI</title>
    {{-- Fonts: IBM Plex Sans (display + body) · JetBrains Mono --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    {{-- Същият дизайн-систем като админа (Vite-компилиран Tailwind v4) --}}
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
            'Организация'        => ['route' => 'client.org.start',   'match' => 'client.org.*'],
            'Моите Flows'        => ['route' => 'client.flows.index', 'match' => 'client.flows.*'],
            'Табло'              => ['route' => 'client.dashboard',   'match' => 'client.dashboard'],
        ];
    @endphp
    <nav class="bg-surface border-b border-line sticky top-0 z-40" x-data="{ open: false, menu: false }">
        <div class="max-w-7xl mx-auto px-6 flex items-stretch justify-between h-16">
            <div class="flex items-stretch gap-6">
                <a href="{{ route('client.home') }}"
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
            </div>

            {{-- Right: изпъкващ CTA + фирма dropdown --}}
            <div class="flex items-center gap-3">
                <a href="{{ route('client.org.tasks.new') }}"
                   class="hidden sm:inline-flex items-center justify-center gap-2 h-10 px-4 text-sm font-semibold rounded-md bg-primary text-primary-fg hover:bg-primary-hover shadow-card transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
                    <x-icon name="plus" size="4" /> Нова задача
                </a>

                <div class="relative hidden md:block" @click.outside="menu = false">
                    <button type="button" @click="menu = !menu" :aria-expanded="menu"
                            class="inline-flex items-center gap-2 h-10 px-3 rounded-md text-sm text-muted hover:text-ink hover:bg-surface-subtle transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
                        <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-info-soft text-primary text-xs font-semibold">
                            {{ \Illuminate\Support\Str::of($currentCompany->name ?? '?')->substr(0, 1)->upper() }}
                        </span>
                        <span class="max-w-[12rem] truncate font-medium text-ink">{{ $currentCompany->name ?? '' }}</span>
                        <x-icon name="chevron-down" size="4" />
                    </button>
                    <div x-show="menu" x-cloak x-transition.origin.top.right
                         class="absolute right-0 mt-1 w-56 bg-surface border border-line rounded-lg shadow-popover py-1 z-50">
                        @if($currentUser)
                            <div class="px-3 py-2 border-b border-line">
                                <p class="text-sm font-medium text-ink truncate">{{ $currentUser->name }}</p>
                                <p class="text-xs text-subtle">{{ $currentUser->role === 'owner' ? 'Собственик' : 'Потребител' }}</p>
                            </div>
                        @endif
                        <a href="{{ route('client.login') }}" class="block px-3 py-2 text-sm text-muted hover:text-ink hover:bg-surface-subtle transition">Смени фирма/потребител</a>
                        <form action="{{ route('client.logout') }}" method="POST">
                            @csrf
                            <button type="submit" class="w-full text-left px-3 py-2 text-sm text-danger hover:bg-danger-soft transition">Изход</button>
                        </form>
                    </div>
                </div>

                {{-- Mobile toggle --}}
                <button type="button" class="md:hidden inline-flex items-center text-muted hover:text-ink rounded-md px-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                        @click="open = !open" :aria-expanded="open" aria-label="Меню">
                    <x-icon name="bars-3" x-show="!open" />
                    <x-icon name="x-mark" x-show="open" x-cloak />
                </button>
            </div>
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
                <a href="{{ route('client.org.tasks.new') }}" class="block px-3 py-2 rounded-md text-sm font-semibold bg-primary text-primary-fg">＋ Нова задача</a>
                <form action="{{ route('client.logout') }}" method="POST" class="pt-1">
                    @csrf
                    <button type="submit" class="w-full text-left px-3 py-2 rounded-md text-sm font-medium text-danger hover:bg-danger-soft transition">Изход</button>
                </form>
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
</body>
</html>
