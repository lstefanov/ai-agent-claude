<div class="bg-surface border border-line rounded-xl p-6 space-y-4">
    <div class="grid grid-cols-2 gap-4">
        <x-field label="Иконка (emoji)" name="icon">
            <x-input name="icon" :value="old('icon', $agentTemplate->icon ?? '🤖')" required />
        </x-field>
        <x-field label="Име на шаблона" name="name">
            <x-input name="name" :value="old('name', $agentTemplate->name ?? '')" required />
        </x-field>
        <x-field label="Кратко описание" name="description" class="col-span-2">
            <x-input name="description" :value="old('description', $agentTemplate->description ?? '')" required maxlength="500" />
        </x-field>
        <x-field label="Тип" name="type">
            @include('partials.agent-type-select', [
                'name'         => 'type',
                'selectedType' => old('type', $agentTemplate->type ?? ''),
                'selectId'     => 'company-agent-type-select',
            ])
        </x-field>
        <x-field label="Подредба" name="sort_order">
            <x-input type="number" name="sort_order" :value="old('sort_order', $agentTemplate->sort_order ?? 0)" min="0" />
        </x-field>
        <x-field label="Модел по подразбиране" name="model">
            <x-select name="model">
                <option value="">— авто —</option>
                @foreach($models as $m)
                    <option value="{{ $m->ollama_tag }}" @selected(old('model', $agentTemplate->model ?? '') === $m->ollama_tag)>{{ $m->display_name }}</option>
                @endforeach
            </x-select>
        </x-field>
        <x-field label="Temperature" name="config.temperature">
            <x-input type="number" name="config[temperature]" step="0.1" min="0" max="2"
                     :value="old('config.temperature', $agentTemplate->config['temperature'] ?? 0.7)" />
        </x-field>

        @foreach([
            ['role', 'Роля / Описание', 2, false],
            ['system_prompt', 'System Промпт', 3, 'co-tpl-sp-field'],
            ['prompt_template', 'Промпт Шаблон', 4, 'co-tpl-pt-field'],
        ] as [$field, $labelText, $rows, $id])
        <div class="col-span-2 space-y-1.5">
            <div class="flex items-center justify-between">
                <label @if($id) for="{{ $id }}" @endif class="block text-sm font-medium text-ink">{{ $labelText }}</label>
                <button type="button" onclick="generateAgentField('{{ $field }}', this)"
                        class="inline-flex items-center gap-1.5 bg-primary hover:bg-primary-hover disabled:opacity-50 disabled:cursor-not-allowed text-primary-fg text-xs font-medium px-3 py-1 rounded-md transition">
                    <x-icon name="sparkles" size="3.5" /> Генерирай с AI
                </button>
            </div>
            <x-textarea name="{{ $field }}" :id="$id ?: null" rows="{{ $rows }}" :class="$id ? 'font-mono' : ''">{{ old($field, $agentTemplate->$field ?? '') }}</x-textarea>
            @if($id)
                @include('partials.token-helper', ['textareaId' => $id, 'agents' => null, 'xAgents' => null])
            @endif
        </div>
        @endforeach
    </div>

    <div class="flex justify-end gap-3 pt-2">
        <x-button variant="secondary" :href="$cancelUrl">Откажи</x-button>
        <x-button type="submit" icon="check">Запази шаблона</x-button>
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
    btn.innerHTML = 'Генерира…';
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
