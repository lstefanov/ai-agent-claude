<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="UTF-8">
    <title>Admin Login — FlowAI</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    {{-- Fonts: IBM Plex Sans (display + body) · JetBrains Mono --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    {{-- Tailwind v4 + app assets via Vite (replaces the old Tailwind play CDN) --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-surface-subtle text-ink min-h-screen flex items-center justify-center px-4 antialiased">
    <div class="w-full max-w-sm">
        <div class="bg-surface border border-line rounded-xl shadow-card p-8">
            <h1 class="text-2xl font-display font-bold text-ink mb-6 flex items-center justify-center gap-2">
                <span class="text-primary">
                    <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 7h-9"/><path d="M14 17H5"/><circle cx="17" cy="17" r="3"/><circle cx="7" cy="7" r="3"/></svg>
                </span>
                FlowAI Admin
            </h1>
            <form action="{{ route('admin.login.post') }}" method="POST" class="space-y-4">
                @csrf
                <x-field label="Парола" name="password" required>
                    <x-input type="password" name="password" autofocus required autocomplete="current-password" />
                </x-field>
                <x-button type="submit" class="w-full">Влез</x-button>
            </form>
        </div>
    </div>
</body>
</html>
