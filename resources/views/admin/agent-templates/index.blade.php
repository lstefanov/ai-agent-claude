@extends('admin.layouts.admin')

@section('title', 'Системни агент шаблони')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">⚙ Системни агент шаблони</h1>
        <p class="text-sm text-gray-500 mt-1">Видими за всички компании в picker-а</p>
    </div>
    <a href="{{ route('admin.agent-templates.create') }}"
       class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-semibold transition">
        ＋ Нов шаблон
    </a>
</div>

@if($active->isEmpty() && $inactive->isEmpty())
    <div class="bg-white border border-dashed border-gray-300 rounded-xl p-12 text-center text-gray-400">
        <p class="text-3xl mb-3">🤖</p>
        <p class="text-sm">Няма системни шаблони. <a href="{{ route('admin.agent-templates.create') }}" class="text-indigo-600 underline">Добави първия.</a></p>
    </div>
@else
    {{-- Активни --}}
    <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-2">
        Активни агенти <span class="text-gray-400 font-normal">(<span data-count="active">{{ $active->count() }}</span>)</span>
    </h2>
    <div class="bg-white border border-gray-200 rounded-xl overflow-hidden mb-8" data-container="active">
        @foreach($active as $template)
            @include('admin.agent-templates._row', ['template' => $template])
        @endforeach
        <p class="px-5 py-6 text-center text-sm text-gray-400 {{ $active->isEmpty() ? '' : 'hidden' }}" data-empty="active">
            Няма активни шаблони.
        </p>
    </div>

    {{-- Неактивни --}}
    <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-2">
        Неактивни агенти <span class="text-gray-400 font-normal">(<span data-count="inactive">{{ $inactive->count() }}</span>)</span>
    </h2>
    <div class="bg-white border border-gray-200 rounded-xl overflow-hidden opacity-90" data-container="inactive">
        @foreach($inactive as $template)
            @include('admin.agent-templates._row', ['template' => $template])
        @endforeach
        <p class="px-5 py-6 text-center text-sm text-gray-400 {{ $inactive->isEmpty() ? '' : 'hidden' }}" data-empty="inactive">
            Няма неактивни шаблони.
        </p>
    </div>
@endif

<script>
document.addEventListener('click', async function (e) {
    const btn = e.target.closest('[data-toggle-active]');
    if (!btn) return;

    const row = btn.closest('[data-template-row]');
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '⏳';

    try {
        const resp = await fetch(btn.dataset.url, {
            method: 'PATCH',
            headers: {
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
            },
        });
        const data = await resp.json();
        if (!resp.ok) {
            alert(data.message || 'Грешка при промяна на статуса.');
            btn.innerHTML = original;
            return;
        }

        const isActive = data.is_active;
        const targetKey = isActive ? 'active' : 'inactive';
        const sourceKey = isActive ? 'inactive' : 'active';
        const target = document.querySelector('[data-container="' + targetKey + '"]');
        const targetEmpty = target.querySelector('[data-empty="' + targetKey + '"]');

        // Премести реда в другия контейнер (преди празното съобщение).
        target.insertBefore(row, targetEmpty);

        // Обнови бутона.
        btn.innerHTML = isActive ? '⏸ Изключи' : '▶ Включи';
        btn.className = 'px-3 py-1.5 rounded-lg text-xs border transition ' + (isActive
            ? 'border-green-200 text-green-600 hover:bg-green-50'
            : 'border-gray-200 text-gray-500 hover:bg-gray-50');

        // Обнови празни състояния и броячи.
        updateContainer(targetKey);
        updateContainer(sourceKey);
    } catch (err) {
        console.error('toggleActive error', err);
        alert('Мрежова грешка.');
        btn.innerHTML = original;
    } finally {
        btn.disabled = false;
    }
});

function updateContainer(key) {
    const container = document.querySelector('[data-container="' + key + '"]');
    if (!container) return;
    const rows = container.querySelectorAll('[data-template-row]');
    const empty = container.querySelector('[data-empty="' + key + '"]');
    if (empty) empty.classList.toggle('hidden', rows.length > 0);
    const count = document.querySelector('[data-count="' + key + '"]');
    if (count) count.textContent = rows.length;
}
</script>
@endsection
