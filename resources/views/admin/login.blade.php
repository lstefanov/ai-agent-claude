<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="UTF-8">
    <title>Admin Login — FlowAI</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-900 min-h-screen flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-xl p-8 w-full max-w-sm">
        <h1 class="text-xl font-bold text-gray-900 mb-6 text-center">⚙ FlowAI Admin</h1>
        <form action="{{ route('admin.login.post') }}" method="POST" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Парола</label>
                <input type="password" name="password" autofocus required
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500
                              @error('password') border-red-400 @enderror">
                @error('password')
                    <p class="text-red-600 text-xs mt-1">{{ $message }}</p>
                @enderror
            </div>
            <button type="submit"
                    class="w-full bg-indigo-600 hover:bg-indigo-700 text-white py-2 rounded-lg font-semibold transition">
                Влез
            </button>
        </form>
    </div>
</body>
</html>
