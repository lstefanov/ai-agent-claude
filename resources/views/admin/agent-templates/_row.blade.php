<div class="flex items-center gap-4 px-5 py-4 border-b border-line last:border-0 hover:bg-surface-subtle"
     data-template-row data-id="{{ $template->id }}">
    <span class="text-2xl w-8 text-center">{{ $template->icon }}</span>
    <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2">
            <span class="font-semibold text-ink text-sm">{{ $template->name }}</span>
            <span class="text-xs bg-violet-100 text-violet-700 px-2 py-0.5 rounded font-mono">{{ $template->type }}</span>
        </div>
        <p class="text-xs text-muted truncate">{{ $template->description }}</p>
    </div>
    <div class="flex gap-2 shrink-0">
        <button type="button"
                data-toggle-active
                data-url="{{ route('admin.agent-templates.toggle-active', $template) }}"
                class="px-3 py-1.5 rounded-lg text-xs border transition
                    {{ $template->is_active
                        ? 'border-green-200 text-green-600 hover:bg-green-50'
                        : 'border-line text-muted hover:bg-surface-subtle' }}">
            {{ $template->is_active ? '⏸ Изключи' : '▶ Включи' }}
        </button>
        <a href="{{ route('admin.agent-templates.edit', $template) }}"
           class="border border-line bg-surface text-muted hover:bg-surface-subtle px-3 py-1.5 rounded-lg text-xs">
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
