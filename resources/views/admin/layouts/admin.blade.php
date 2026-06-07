<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Admin') — FlowAI Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2/dist/css/tom-select.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2/dist/js/tom-select.complete.min.js"></script>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        /* Tom Select — match existing input style */
        .ts-wrapper.single .ts-control {
            border-radius: 0.5rem;
            border: 1px solid #d1d5db;
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
            line-height: 1.25rem;
            background: #fff;
            box-shadow: none;
            cursor: pointer;
        }
        .ts-wrapper.single.focus .ts-control {
            border-color: #6366f1;
            outline: none;
            box-shadow: 0 0 0 2px rgba(99,102,241,0.3);
        }
        .ts-wrapper .ts-dropdown {
            border-radius: 0.5rem;
            border: 1px solid #e5e7eb;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.08);
            font-size: 0.875rem;
        }
        .ts-wrapper .ts-dropdown .ts-dropdown-content { max-height: 280px; }
        .ts-wrapper .ts-dropdown .optgroup-header {
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.07em;
            text-transform: uppercase;
            color: #6b7280;
            padding: 0.5rem 0.75rem 0.25rem;
            background: #f9fafb;
        }
        .ts-wrapper .ts-dropdown .option { padding: 0.5rem 0.75rem; }
        .ts-wrapper .ts-dropdown .option.active { background: #eef2ff; color: #3730a3; }
        .ts-wrapper .ts-dropdown .option:hover { background: #f5f3ff; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    <nav class="bg-gray-900 text-white px-6 py-3 flex items-center justify-between">
        <span class="font-bold text-lg">⚙ FlowAI Admin</span>
        <div class="flex items-center gap-4 text-sm">
            <a href="{{ route('admin.agent-templates.index') }}"
               class="hover:text-gray-300 {{ request()->routeIs('admin.agent-templates.*') ? 'text-white font-semibold' : 'text-gray-400' }}">
                Системни агенти
            </a>
            <a href="{{ route('admin.costs.index') }}"
               class="hover:text-gray-300 {{ request()->routeIs('admin.costs.*') ? 'text-white font-semibold' : 'text-gray-400' }}">
                Разходи
            </a>
            <form action="{{ route('admin.logout') }}" method="POST">
                @csrf
                <button class="text-gray-400 hover:text-white">Изход</button>
            </form>
        </div>
    </nav>
    <div class="@yield('container-class', 'max-w-5xl') mx-auto px-6 py-8">
        @if(session('success'))
            <div class="mb-4 px-4 py-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm">
                ✓ {{ session('success') }}
            </div>
        @endif
        @yield('content')
    </div>
</body>
</html>
