<div class="bg-white border border-gray-200 rounded-xl p-6 space-y-4">
    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Иконка (emoji)</label>
            <input type="text" name="icon" value="{{ old('icon', $agentTemplate->icon ?? '🤖') }}" required
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Ime на шаблона</label>
            <input type="text" name="name" value="{{ old('name', $agentTemplate->name ?? '') }}" required
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>
        <div class="col-span-2">
            <label class="block text-xs font-semibold text-gray-600 mb-1">Кратко описание (показва се в popup картата)</label>
            <input type="text" name="description" value="{{ old('description', $agentTemplate->description ?? '') }}" required maxlength="500"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Тип</label>
            @include('partials.agent-type-select', [
                'name'         => 'type',
                'selectedType' => old('type', $agentTemplate->type ?? ''),
                'selectId'     => 'admin-agent-type-select',
            ])
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Подредба (sort_order)</label>
            <input type="number" name="sort_order" value="{{ old('sort_order', $agentTemplate->sort_order ?? 0) }}" min="0"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Модел по подразбиране</label>
            <select name="model" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <option value="">— авто (ще се избере при добавяне) —</option>
                @foreach($models as $m)
                    <option value="{{ $m->ollama_tag }}" {{ old('model', $agentTemplate->model ?? '') === $m->ollama_tag ? 'selected' : '' }}>
                        {{ $m->display_name }} ({{ $m->ollama_tag }})
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Temperature</label>
            <input type="number" name="config[temperature]" step="0.1" min="0" max="2"
                   value="{{ old('config.temperature', $agentTemplate->config['temperature'] ?? 0.7) }}"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>
        <div class="col-span-2">
            <div class="flex items-center justify-between mb-1">
                <label class="block text-xs font-semibold text-gray-600">Роля / Описание</label>
                <button type="button" onclick="generateAgentField('role', this)"
                        class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-indigo-50 text-indigo-600 hover:bg-indigo-100 border border-indigo-200 transition disabled:opacity-40">
                    ✨ Генерирай с AI
                </button>
            </div>
            <textarea name="role" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">{{ old('role', $agentTemplate->role ?? '') }}</textarea>
        </div>
        <div class="col-span-2">
            <div class="flex items-center justify-between mb-1">
                <label class="block text-xs font-semibold text-gray-600">System Промпт</label>
                <button type="button" onclick="generateAgentField('system_prompt', this)"
                        class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-indigo-50 text-indigo-600 hover:bg-indigo-100 border border-indigo-200 transition disabled:opacity-40">
                    ✨ Генерирай с AI
                </button>
            </div>
            <textarea name="system_prompt" id="tpl-sp-field" rows="3" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500">{{ old('system_prompt', $agentTemplate->system_prompt ?? '') }}</textarea>
            @include('partials.token-helper', ['textareaId' => 'tpl-sp-field', 'agents' => null, 'xAgents' => null])
        </div>
        <div class="col-span-2">
            <div class="flex items-center justify-between mb-1">
                <label class="block text-xs font-semibold text-gray-600">Промпт Шаблон</label>
                <button type="button" onclick="generateAgentField('prompt_template', this)"
                        class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-indigo-50 text-indigo-600 hover:bg-indigo-100 border border-indigo-200 transition disabled:opacity-40">
                    ✨ Генерирай с AI
                </button>
            </div>
            <textarea name="prompt_template" id="tpl-pt-field" rows="4" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500">{{ old('prompt_template', $agentTemplate->prompt_template ?? '') }}</textarea>
            @include('partials.token-helper', ['textareaId' => 'tpl-pt-field', 'agents' => null, 'xAgents' => null])
        </div>
    </div>
    <div class="flex justify-end gap-3 pt-2">
        <a href="{{ $cancelUrl }}" class="border border-gray-300 bg-white text-gray-600 px-4 py-2 rounded-lg text-sm hover:bg-gray-50 transition">Откажи</a>
        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2 rounded-lg text-sm font-semibold transition">
            💾 Запази шаблона
        </button>
    </div>
</div>

<script>
window.FLOW_DESCRIPTION = '';

async function generateAgentField(fieldName, btn) {
    const form = btn.closest('form');
    const nameInput = form.querySelector('[name="name"]');
    if (!nameInput || !nameInput.value.trim()) {
        alert('Въведи първо името на агента.');
        return;
    }
    const textarea = form.querySelector('[name="' + fieldName + '"]');
    if (!textarea) return;
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '⏳ Генерира...';
    try {
        const resp = await fetch('/ai/generate-agent-field', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                field:            fieldName,
                agent_name:       nameInput.value.trim(),
                agent_type:       (form.querySelector('[name="type"]') || {}).value || '',
                flow_description: window.FLOW_DESCRIPTION || '',
                role:             (form.querySelector('[name="role"]') || {}).value || '',
                system_prompt:    (form.querySelector('[name="system_prompt"]') || {}).value || '',
                prompt_template:  (form.querySelector('[name="prompt_template"]') || {}).value || '',
            }),
        });
        const data = await resp.json();
        if (!resp.ok) {
            alert(data.error || 'Грешка при AI генерация. Провери дали Ollama работи.');
        } else if (data.generated) {
            textarea.value = data.generated;
        }
    } catch (e) {
        console.error('generateAgentField error', e);
        alert('Мрежова грешка при AI генерация.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    }
}
</script>
