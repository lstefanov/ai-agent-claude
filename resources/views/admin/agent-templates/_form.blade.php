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
            <select name="type" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                @foreach(['researcher','analyzer','content_bg','content_en','hashtag','image_prompt','translator','qa_verifier','summarizer','decision','publisher','email','orchestrator'] as $t)
                    <option value="{{ $t }}" {{ old('type', $agentTemplate->type ?? '') === $t ? 'selected' : '' }}>{{ $t }}</option>
                @endforeach
            </select>
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
            <label class="block text-xs font-semibold text-gray-600 mb-1">Роля / Описание</label>
            <textarea name="role" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">{{ old('role', $agentTemplate->role ?? '') }}</textarea>
        </div>
        <div class="col-span-2">
            <label class="block text-xs font-semibold text-gray-600 mb-1">System Промпт</label>
            <textarea name="system_prompt" rows="3" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500">{{ old('system_prompt', $agentTemplate->system_prompt ?? '') }}</textarea>
        </div>
        <div class="col-span-2">
            <label class="block text-xs font-semibold text-gray-600 mb-1">Промпт Шаблон</label>
            <textarea name="prompt_template" rows="4" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500">{{ old('prompt_template', $agentTemplate->prompt_template ?? '') }}</textarea>
        </div>
    </div>
    <div class="flex justify-end gap-3 pt-2">
        <a href="{{ $cancelUrl }}" class="border border-gray-300 bg-white text-gray-600 px-4 py-2 rounded-lg text-sm hover:bg-gray-50 transition">Откажи</a>
        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2 rounded-lg text-sm font-semibold transition">
            💾 Запази шаблона
        </button>
    </div>
</div>
