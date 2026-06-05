<div class="flex items-center gap-4 px-5 py-4 border-b border-gray-100 last:border-0 hover:bg-gray-50"
     data-template-row data-id="{{ $template->id }}">
    <span class="text-2xl w-8 text-center">{{ $template->icon }}</span>
    <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2">
            <span class="font-semibold text-gray-900 text-sm">{{ $template->name }}</span>
            <span class="text-xs bg-violet-100 text-violet-700 px-2 py-0.5 rounded font-mono">{{ $template->type }}</span>
        </div>
        <p class="text-xs text-gray-500 truncate">{{ $template->description }}</p>
    </div>
    <div class="flex gap-2 shrink-0">
        <button type="button"
                data-toggle-active
                data-url="{{ route('admin.agent-templates.toggle-active', $template) }}"
                class="px-3 py-1.5 rounded-lg text-xs border transition
                    {{ $template->is_active
                        ? 'border-green-200 text-green-600 hover:bg-green-50'
                        : 'border-gray-200 text-gray-500 hover:bg-gray-50' }}">
            {{ $template->is_active ? '⏸ Изключи' : '▶ Включи' }}
        </button>
        <a href="{{ route('admin.agent-templates.edit', $template) }}"
           class="border border-gray-200 bg-white text-gray-600 hover:bg-gray-50 px-3 py-1.5 rounded-lg text-xs">
            ✏ Редактирай
        </a>
        <form action="{{ route('admin.agent-templates.destroy', $template) }}" method="POST"
              onsubmit="return confirm('Изтрий шаблон {{ $template->name }}?')">
            @csrf @method('DELETE')
            <button class="border border-red-200 text-red-500 hover:bg-red-50 px-3 py-1.5 rounded-lg text-xs">
                ✕ Изтрий
            </button>
        </form>
    </div>
</div>
