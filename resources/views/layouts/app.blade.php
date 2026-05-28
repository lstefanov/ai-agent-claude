<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'FlowAI') — FlowAI</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">

    {{-- Navigation --}}
    <nav class="bg-indigo-700 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
            <a href="{{ route('companies.index') }}" class="text-xl font-bold tracking-tight hover:text-indigo-200 transition">
                ⚡ FlowAI
            </a>
            <div class="flex items-center gap-6 text-sm font-medium">
                <a href="{{ route('companies.index') }}" class="hover:text-indigo-200 transition {{ request()->routeIs('companies.*') ? 'text-white underline underline-offset-4' : 'text-indigo-200' }}">
                    Фирми
                </a>
                <a href="{{ route('models.index') }}" class="hover:text-indigo-200 transition {{ request()->routeIs('models.*') ? 'text-white underline underline-offset-4' : 'text-indigo-200' }}">
                    LLM Модели
                </a>
            </div>
        </div>
    </nav>

    {{-- Main Content --}}
    <main class="max-w-7xl mx-auto px-6 py-8">

        {{-- Flash Messages --}}
        @if(session('success'))
            <div class="mb-6 bg-green-50 border border-green-300 text-green-800 px-4 py-3 rounded-lg flex items-center gap-2">
                <span class="text-green-500">✓</span> {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div class="mb-6 bg-red-50 border border-red-300 text-red-800 px-4 py-3 rounded-lg flex items-center gap-2">
                <span class="text-red-500">✗</span> {{ session('error') }}
            </div>
        @endif

        @if($errors->any())
            <div class="mb-6 bg-red-50 border border-red-300 text-red-800 px-4 py-3 rounded-lg">
                <ul class="list-disc list-inside text-sm space-y-1">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </main>

</body>
</html>
