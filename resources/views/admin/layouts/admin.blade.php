<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Admin') — FlowAI Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
<body class="bg-gray-50 min-h-screen">
    <nav class="bg-gray-900 text-white px-6 py-3 flex items-center justify-between">
        <span class="font-bold text-lg">⚙ FlowAI Admin</span>
        <div class="flex items-center gap-4 text-sm">
            <a href="{{ route('admin.agent-templates.index') }}"
               class="hover:text-gray-300 {{ request()->routeIs('admin.agent-templates.*') ? 'text-white font-semibold' : 'text-gray-400' }}">
                Системни агенти
            </a>
            <form action="{{ route('admin.logout') }}" method="POST">
                @csrf
                <button class="text-gray-400 hover:text-white">Изход</button>
            </form>
        </div>
    </nav>
    <div class="max-w-5xl mx-auto px-6 py-8">
        @if(session('success'))
            <div class="mb-4 px-4 py-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm">
                ✓ {{ session('success') }}
            </div>
        @endif
        @yield('content')
    </div>
</body>
</html>
