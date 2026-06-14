<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Admin') — FlowAI Admin</title>
    {{-- Fonts: IBM Plex Sans (display + body) · JetBrains Mono --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    {{-- Tailwind v4 + app assets via Vite (replaces the old Tailwind play CDN) --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <link href="https://cdn.jsdelivr.net/npm/tom-select@2/dist/css/tom-select.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2/dist/js/tom-select.complete.min.js"></script>
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
<body class="bg-surface-subtle min-h-screen">
    <nav class="bg-ink text-white px-6 py-3 flex items-center justify-between">
        <span class="font-bold text-lg flex items-center gap-2">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 7h-9"/><path d="M14 17H5"/><circle cx="17" cy="17" r="3"/><circle cx="7" cy="7" r="3"/></svg>
            FlowAI Admin
        </span>
        <div class="flex items-center gap-4 text-sm">
            <a href="{{ route('admin.agent-templates.index') }}"
               class="hover:text-subtle {{ request()->routeIs('admin.agent-templates.*') ? 'text-white font-semibold' : 'text-subtle' }}">
                Системни агенти
            </a>
            <a href="{{ route('admin.costs.index') }}"
               class="hover:text-subtle {{ request()->routeIs('admin.costs.*') ? 'text-white font-semibold' : 'text-subtle' }}">
                Разходи
            </a>
            <form action="{{ route('admin.logout') }}" method="POST">
                @csrf
                <button class="text-subtle hover:text-white">Изход</button>
            </form>
        </div>
    </nav>
    <div class="@yield('container-class', 'max-w-5xl') mx-auto px-6 py-8">
        @if(session('success'))
            <div class="mb-4 px-4 py-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm flex items-center gap-2">
                <svg class="w-4 h-4 shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                {{ session('success') }}
            </div>
        @endif
        @yield('content')
    </div>
</body>
</html>
