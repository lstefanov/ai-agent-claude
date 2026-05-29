# Flows Create UX Improvements — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement 6 UX improvements to `/flows/create`: Bulgarian agent names/descriptions/prompts, inline agent editing with drag-reorder, AI description improvement button, and Apple-style schedule picker.

**Architecture:** All backend changes are in `AgentGeneratorService` (AI prompt changes) and `FlowController` (new `improveDescription` endpoint + saving `system_prompt`). All frontend changes are in `flows/create.blade.php` using Alpine.js + SortableJS CDN. The cron UI is pure Alpine with no backend changes.

**Tech Stack:** Laravel 11, Blade, Alpine.js (already loaded), SortableJS (CDN), Ollama via `OllamaService`, Tailwind CSS (already loaded)

---

## Task 1: Migration + Model — add `system_prompt` to agents

**Files:**
- Create: `database/migrations/2026_05_29_120000_add_system_prompt_to_agents_table.php`
- Modify: `app/Models/Agent.php`

- [ ] **Step 1: Create migration**

```php
<?php
// database/migrations/2026_05_29_120000_add_system_prompt_to_agents_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->longText('system_prompt')->nullable()->after('prompt_template');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('system_prompt');
        });
    }
};
```

- [ ] **Step 2: Run migration**

```bash
cd /Users/lub/Sites/localhost/ai-agent-claude && php artisan migrate
```

Expected: `Migrating: 2026_05_29_120000_add_system_prompt_to_agents_table` then `Migrated`.

- [ ] **Step 3: Update Agent model `$fillable`**

In `app/Models/Agent.php`, replace the `$fillable` array:

```php
protected $fillable = [
    'flow_id', 'name', 'type', 'role', 'capabilities', 'strengths', 'limitations',
    'input_description', 'output_description', 'prompt_template', 'system_prompt',
    'model', 'model_reason', 'order', 'is_verifier', 'qa_threshold', 'depends_on',
    'config', 'is_active', 'output_language', 'output_tone', 'output_style', 'output_format',
];
```

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_05_29_120000_add_system_prompt_to_agents_table.php app/Models/Agent.php
git commit -m "feat: add system_prompt column to agents table"
```

---

## Task 2: AgentGeneratorService — Bulgarian names, descriptions, and prompts

**Files:**
- Modify: `app/Services/AgentGeneratorService.php`

- [ ] **Step 1: Replace the system prompt in `generate()`**

Replace the `$systemPrompt` heredoc (lines 21–34) with:

```php
$systemPrompt = <<<PROMPT
Ти си AI архитект на маркетингови и бизнес автоматизации.
Твоята задача: проектирай ПЪЛЕН, готов за продукция multi-agent pipeline.

СТРОГИ ПРАВИЛА:
1. Върни САМО валиден JSON масив — без markdown, без обяснения, без допълнителен текст
2. МИНИМУМ 5 агента, идеално 6-8 в зависимост от сложността
3. Всеки агент има ТОЧНО ЕДНА отговорност
4. system_prompt трябва да е детайлен (минимум 3 изречения) — на български
5. prompt_template трябва да е детайлен (минимум 5 изречения) — на български, с конкретни placeholder-и като {{company_description}}, {{input}}, {{topic}}
6. ВИНАГИ включвай: поне един researcher/analyzer, поне един content агент, точно един qa_verifier накрая
7. Избирай модели според задачата — виж списъка с модели

Генерирането на по-малко от 5 агента е ЗАБРАНЕНО.
PROMPT;
```

- [ ] **Step 2: Replace the user message JSON schema section**

Find the `Return a JSON array where each object has EXACTLY these fields:` block (lines ~67–84) and replace it with:

```php
$userMessage = <<<MSG
Компания: {$company->name}
Индустрия: {$company->industry}
Описание на компанията: {$company->description}

Flow за изграждане: "{$flow->description}"

НАЛИЧНИ МОДЕЛИ (избери внимателно за всеки агент):
{$modelsContext}

НЕОБХОДИМИ ТИПОВЕ АГЕНТИ (включи ВСИЧКИ приложими):
- researcher     → Събира контекст, тенденции, актуални новини, данни за конкуренти
- analyzer       → Анализира входа, извлича ключови инсайти, идентифицира възможности
- content_bg     → Пише текстово съдържание на български език
- content_en     → Пише текстово съдържание на английски език
- hashtag        → Генерира релевантни хаштагове (локални + международни)
- image_prompt   → Пише детайлни промпти за генериране на изображения с ComfyUI/Stable Diffusion
- caption_writer → Сглобява финалния пост от всички части (текст + хаштагове + CTA)
- translator     → Превежда съдържание между езици
- qa_verifier    → Преглежда качеството на финалния изход, оценява 0-100, ТРЯБВА да е последен агент
- summarizer     → Кондензира дълго съдържание в ключови точки
- decision       → Взима routing/условни решения
- publisher      → Форматира изхода за конкретни платформи (FB, IG, LinkedIn и др.)

ПРАВИЛА ЗА ПРОЕКТИРАНЕ НА PIPELINE:
- За social media flows: researcher → content → hashtag → image_prompt → caption_writer → qa_verifier
- За български текст: винаги използвай todorov/bggpt за генериране на текст
- За QA/верификация: използвай phi3.5 или phi3:mini (бързи, ефективни)
- За JSON/структуриран изход, image промпти, анализ: използвай mistral-nemo

Върни JSON масив, където всеки обект има ТОЧНО тези полета:
{
  "name": "Описателно българско име (напр. 'Изследовател на тенденции', 'Автор на Facebook постове')",
  "type": "един от типовете изброени по-горе",
  "role": "2-3 изречения на БЪЛГАРСКИ описващи: какво прави агентът, какъв вход получава и какъв изход произвежда",
  "capabilities": ["масив", "от", "способности"],
  "strengths": "в какво е силен агентът — на български",
  "limitations": "какво не може да прави — на български",
  "input_description": "описание на входа — на български",
  "output_description": "описание на изхода — на български",
  "system_prompt": "System prompt на БЪЛГАРСКИ. Минимум 3 изречения. Описва ролята, стила, езика и ограниченията на агента.",
  "prompt_template": "Промпт шаблон на БЪЛГАРСКИ. Минимум 5 изречения. Включи конкретни инструкции за формат, тон, дължина, какво да се включи/изключи. Използвай placeholder-и {{company_description}}, {{input}}, {{topic}} където е подходящо.",
  "model": "точен ollama tag от списъка по-горе",
  "model_reason": "защо е избран този модел — на български",
  "order": 1,
  "is_verifier": false,
  "qa_threshold": null,
  "config": {"temperature": 0.7, "num_predict": 1000}
}

За qa_verifier: is_verifier=true, qa_threshold=75, temperature=0.1
За image_prompt агенти: temperature=0.8, num_predict=500
За researcher/analyzer: temperature=0.3
MSG;
```

- [ ] **Step 3: Update `normalizeAgent()` to include `system_prompt`**

In `normalizeAgent()`, add `system_prompt` after `prompt_template`:

```php
'prompt_template'    => $agent['prompt_template'] ?? $agent['role'] ?? '',
'system_prompt'      => $agent['system_prompt'] ?? null,
```

- [ ] **Step 4: Commit**

```bash
git add app/Services/AgentGeneratorService.php
git commit -m "feat: generate Bulgarian agent names, descriptions, and prompts"
```

---

## Task 3: FlowController — `improveDescription` endpoint + save `system_prompt`

**Files:**
- Modify: `app/Http/Controllers/FlowController.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Add `improveDescription` method to FlowController**

Add this method before `generateAgents()` in `FlowController`:

```php
/**
 * AJAX: improve a flow description using AI.
 */
public function improveDescription(Request $request)
{
    $request->validate([
        'description' => 'required|string|min:5',
        'name'        => 'nullable|string',
        'company_id'  => 'nullable|exists:companies,id',
    ]);

    $name        = $request->name ?? '';
    $description = $request->description;

    $systemPrompt = 'Ти си експерт по бизнес автоматизация и дигитален маркетинг. Подобряваш описания на автоматизирани workflows. Отговаряй САМО с подобреното описание — без въведение, без обяснения, без кавички.';

    $userMessage = <<<MSG
Подобри следното описание на flow "{$name}".

Оригинално описание:
{$description}

Изисквания:
- Напиши 3-5 изречения на български
- Бъди конкретен за: какво прави flow-ът, за коя аудитория е, на какъв език е изходът, каква е структурата на pipeline-а
- Запази оригиналния смисъл, но го направи по-детайлен и по-ясен за AI агентите
- Върни САМО подобреното описание, без допълнителен текст
MSG;

    $improved = $this->ollama->chat(
        model: config('services.ollama.generator_model', 'mistral-nemo'),
        systemPrompt: $systemPrompt,
        userMessage: $userMessage,
        options: ['temperature' => 0.4, 'num_predict' => 300]
    );

    return response()->json(['improved' => trim($improved)]);
}
```

Note: `FlowController` already has `AgentGeneratorService` injected via constructor but not `OllamaService` directly. Add it:

Replace the constructor:

```php
public function __construct(
    private AgentGeneratorService $generator,
    private \App\Services\OllamaService $ollama,
) {}
```

- [ ] **Step 2: Update `store()` to save `system_prompt`**

In `store()`, add to the validation rules:

```php
'agents.*.system_prompt' => 'nullable|string',
```

In the `foreach` loop inside `store()`, add `system_prompt` to the `create()` call:

```php
'system_prompt'     => $agentData['system_prompt'] ?? null,
```

- [ ] **Step 3: Add route in `routes/web.php`**

Add after the `flows.generate-agents` route:

```php
// AJAX: improve flow description with AI
Route::post('flows/improve-description', [FlowController::class, 'improveDescription'])->name('flows.improve-description');
```

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/FlowController.php routes/web.php
git commit -m "feat: add improveDescription endpoint and save system_prompt on flow store"
```

---

## Task 4: `agents/edit.blade.php` — add `system_prompt` field

**Files:**
- Modify: `resources/views/agents/edit.blade.php`

- [ ] **Step 1: Add `system_prompt` textarea to the basic tab**

In the basic tab (`x-show="tab === 'basic'"`), after the "Роля / System prompt" textarea block (after line ~56), add:

```blade
<div>
    <label class="block text-sm font-medium text-gray-700 mb-1">System промпт</label>
    <p class="text-xs text-gray-400 mb-1">Описва ролята и поведението на агента. Инжектира се автоматично при всяко изпълнение.</p>
    <textarea name="system_prompt" rows="4"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500"
              placeholder="Ти си специализиран агент за...">{{ old('system_prompt', $agent->system_prompt) }}</textarea>
    @error('system_prompt') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
</div>
```

Also rename the existing "Роля / System prompt" label to just "Роля / Описание" since `system_prompt` now has its own field.

- [ ] **Step 2: Update `AgentController@update` to save `system_prompt`**

Check `app/Http/Controllers/AgentController.php`. In the `update()` method, add `system_prompt` to validation and update:

```php
'system_prompt' => 'nullable|string',
```

And in the update call:
```php
'system_prompt' => $validated['system_prompt'] ?? null,
```

- [ ] **Step 3: Commit**

```bash
git add resources/views/agents/edit.blade.php app/Http/Controllers/AgentController.php
git commit -m "feat: add system_prompt field to agent edit page"
```

---

## Task 5: `flows/create.blade.php` — cron schedule Apple-style UI (Feature 6)

**Files:**
- Modify: `resources/views/flows/create.blade.php`

- [ ] **Step 1: Add `schedule` state to Alpine `flowCreator()` data**

In the `flowCreator()` return object, add:

```js
schedule: {
    preset: 'none',   // 'none' | 'hourly' | 'daily' | 'weekly' | 'monthly' | 'custom'
    hour: '10',
    dayOfWeek: '1',   // 1=Monday
    dayOfMonth: '1',
    customCron: '',
    showCustom: false,
},
```

- [ ] **Step 2: Add computed cron property and restore logic**

Add this method to the Alpine component:

```js
get cronValue() {
    const s = this.schedule;
    if (s.preset === 'none') return '';
    if (s.preset === 'hourly') return '0 * * * *';
    if (s.preset === 'daily') return `0 ${s.hour} * * *`;
    if (s.preset === 'weekly') return `0 ${s.hour} * * ${s.dayOfWeek}`;
    if (s.preset === 'monthly') return `0 ${s.hour} ${s.dayOfMonth} * *`;
    if (s.preset === 'custom') return s.customCron;
    return '';
},
```

In `init()`, after restoring from sessionStorage, add schedule restore from existing cron value (for validation-error redirects):

```js
// Restore schedule from hidden input if present
const cronEl = document.querySelector('input[name="schedule_cron"]');
if (cronEl?.value && !this.schedule.preset) {
    this.schedule.preset = 'custom';
    this.schedule.customCron = cronEl.value;
    this.schedule.showCustom = true;
}
```

Also save `schedule` to sessionStorage in the submit listener:
```js
sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
    name:        this.flowName,
    description: this.flowDescription,
    agents:      this.agents,
    schedule:    this.schedule,
}));
```

And restore it:
```js
this.schedule = draft.schedule || this.schedule;
```

- [ ] **Step 3: Replace the cron input in the Blade template**

Find the `<div>` containing `Cron разписание` (lines 44–48 of create.blade.php) and replace the entire `<div>` with:

```blade
<div class="col-span-2">
    <label class="block text-sm font-medium text-gray-700 mb-1">
        График на изпълнение
        <span class="text-gray-400 font-normal">(по избор)</span>
    </label>

    {{-- Hidden input carries the cron value for form submission --}}
    <input type="hidden" name="schedule_cron" :value="cronValue">

    {{-- Preset buttons --}}
    <div class="grid grid-cols-5 gap-2 mb-3">
        <template x-for="preset in [
            { id: 'none',    icon: '—',  label: 'Никога',   sub: 'само ръчно' },
            { id: 'hourly',  icon: '🕐', label: 'На час',   sub: 'всеки час' },
            { id: 'daily',   icon: '📅', label: 'Дневно',   sub: 'веднъж/ден' },
            { id: 'weekly',  icon: '📆', label: 'Седмично', sub: 'веднъж/седм.' },
            { id: 'monthly', icon: '🗓', label: 'Месечно',  sub: 'веднъж/месец' },
        ]" :key="preset.id">
            <button type="button"
                    @click="schedule.preset = preset.id; schedule.showCustom = false"
                    :class="schedule.preset === preset.id
                        ? 'bg-indigo-600 border-indigo-600 text-white'
                        : 'bg-white border-gray-200 text-gray-700 hover:border-indigo-300'"
                    class="border rounded-xl p-2.5 text-center cursor-pointer transition text-sm">
                <span class="block text-lg leading-none mb-1" x-text="preset.icon"></span>
                <span class="block font-semibold text-xs" x-text="preset.label"></span>
                <span class="block text-[10px] opacity-70" x-text="preset.sub"></span>
            </button>
        </template>
    </div>

    {{-- Time picker — shown for daily/weekly/monthly --}}
    <div x-show="['daily','weekly','monthly'].includes(schedule.preset)" x-cloak
         class="flex flex-wrap items-center gap-3 mb-3">

        <template x-if="schedule.preset === 'weekly'">
            <div class="flex items-center gap-2">
                <label class="text-sm text-gray-600 whitespace-nowrap">Ден от седмицата:</label>
                <select x-model="schedule.dayOfWeek"
                        class="border border-gray-300 rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <option value="1">Понеделник</option>
                    <option value="2">Вторник</option>
                    <option value="3">Сряда</option>
                    <option value="4">Четвъртък</option>
                    <option value="5">Петък</option>
                    <option value="6">Събота</option>
                    <option value="0">Неделя</option>
                </select>
            </div>
        </template>

        <template x-if="schedule.preset === 'monthly'">
            <div class="flex items-center gap-2">
                <label class="text-sm text-gray-600 whitespace-nowrap">Ден от месеца:</label>
                <select x-model="schedule.dayOfMonth"
                        class="border border-gray-300 rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <template x-for="d in Array.from({length:28},(_,i)=>i+1)" :key="d">
                        <option :value="d" x-text="d"></option>
                    </template>
                </select>
            </div>
        </template>

        <div class="flex items-center gap-2">
            <label class="text-sm text-gray-600 whitespace-nowrap">В колко часа:</label>
            <select x-model="schedule.hour"
                    class="border border-gray-300 rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <template x-for="h in Array.from({length:24},(_,i)=>i)" :key="h">
                    <option :value="h" x-text="String(h).padStart(2,'0') + ':00'"></option>
                </template>
            </select>
        </div>
    </div>

    {{-- Human-readable summary --}}
    <div x-show="schedule.preset !== 'none'" x-cloak
         class="bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 text-xs text-gray-500 flex items-center gap-2 mb-2">
        <span>📋</span>
        <span>
            <template x-if="schedule.preset === 'hourly'">
                <span>Ще се изпълнява <strong class="text-gray-700">всеки час</strong></span>
            </template>
            <template x-if="schedule.preset === 'daily'">
                <span>Ще се изпълнява <strong class="text-gray-700">всеки ден в <span x-text="String(schedule.hour).padStart(2,'0') + ':00'"></span></strong></span>
            </template>
            <template x-if="schedule.preset === 'weekly'">
                <span>Ще се изпълнява <strong class="text-gray-700">всяка седмица в <span x-text="String(schedule.hour).padStart(2,'0') + ':00'"></span></strong></span>
            </template>
            <template x-if="schedule.preset === 'monthly'">
                <span>Ще се изпълнява <strong class="text-gray-700">всеки месец на <span x-text="schedule.dayOfMonth"></span>-ти в <span x-text="String(schedule.hour).padStart(2,'0') + ':00'"></span></strong></span>
            </template>
            <template x-if="schedule.preset === 'custom'">
                <span>Cron: <code class="font-mono" x-text="schedule.customCron"></code></span>
            </template>
            · <code class="font-mono text-gray-400" x-text="cronValue"></code>
        </span>
    </div>

    {{-- Advanced / custom cron --}}
    <button type="button" @click="schedule.showCustom = !schedule.showCustom; if(schedule.showCustom) schedule.preset = 'custom'"
            class="text-xs text-gray-400 hover:text-gray-600 underline transition">
        ⚙ По избор (напреднали)
    </button>
    <div x-show="schedule.showCustom" x-cloak class="mt-2">
        <input type="text" x-model="schedule.customCron"
               placeholder="напр. 0 10 * * 1-5"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500">
        <p class="text-xs text-gray-400 mt-1">Стандартен cron синтаксис: минута час ден-от-месец месец ден-от-седмица</p>
    </div>
</div>
```

- [ ] **Step 4: Remove the old 2-column grid wrapper** that contained Status + Cron, and restructure to separate Status (2-col grid) from the new schedule section (full width):

Replace the `<div class="grid grid-cols-2 gap-4">` block (lines 35–50) with:

```blade
<div class="grid grid-cols-2 gap-4">
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Статус</label>
        <select name="status" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
            <option value="draft">Draft</option>
            <option value="active">Active</option>
        </select>
    </div>
</div>
{{-- Schedule picker (full width) --}}
<div>
    {{-- ... the full schedule picker block from Step 3 ... --}}
</div>
```

- [ ] **Step 5: Commit**

```bash
git add resources/views/flows/create.blade.php
git commit -m "feat: replace cron text input with Apple-style schedule picker"
```

---

## Task 6: `flows/create.blade.php` — AI improve description button (Feature 5)

**Files:**
- Modify: `resources/views/flows/create.blade.php`

- [ ] **Step 1: Add Alpine state for the improve feature**

In the `flowCreator()` return object, add:

```js
isImproving: false,
improvedDescription: '',
showImprovePreview: false,
```

- [ ] **Step 2: Add `improveDescription` method to the Alpine component**

```js
async improveDescription() {
    if (!this.flowDescription.trim()) return;
    this.isImproving = true;
    this.showImprovePreview = false;
    this.improvedDescription = '';

    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    try {
        const resp = await fetch('{{ route('flows.improve-description') }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            body: JSON.stringify({
                description: this.flowDescription,
                name: this.flowName,
                company_id: {{ $company->id }},
            }),
        });
        const data = await resp.json();
        if (resp.ok && data.improved) {
            this.improvedDescription = data.improved;
            this.showImprovePreview = true;
        } else {
            alert(data.error || 'Грешка при подобряването. Опитай отново.');
        }
    } catch (e) {
        alert('Мрежова грешка: ' + e.message);
    } finally {
        this.isImproving = false;
    }
},

acceptImprovedDescription() {
    this.flowDescription = this.improvedDescription;
    this.showImprovePreview = false;
    this.improvedDescription = '';
},
```

- [ ] **Step 3: Update the description textarea in the Blade template**

Replace the existing `<div>` containing the description textarea with:

```blade
<div>
    <label class="block text-sm font-medium text-gray-700 mb-1">Описание на flow-а</label>
    <div class="relative">
        <textarea name="description" x-model="flowDescription" rows="4" required
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 pb-10 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  placeholder="Опиши подробно какво трябва да прави flow-ът. Колкото по-детайлно, толкова по-добри агенти ще генерира AI."></textarea>
        <button type="button"
                @click="improveDescription"
                :disabled="isImproving || !flowDescription.trim()"
                class="absolute bottom-2 right-2 bg-indigo-600 hover:bg-indigo-700 disabled:bg-indigo-300 disabled:cursor-not-allowed text-white text-xs font-semibold px-3 py-1.5 rounded-lg flex items-center gap-1.5 transition">
            <span x-show="isImproving" class="inline-block w-3 h-3 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
            <span x-text="isImproving ? 'Подобрявам...' : '✨ Подобри с AI'"></span>
        </button>
    </div>

    {{-- AI preview panel --}}
    <div x-show="showImprovePreview" x-cloak
         class="mt-3 bg-indigo-50 border border-indigo-200 rounded-xl p-4">
        <p class="text-xs font-semibold text-indigo-700 mb-2">✨ AI предлага подобрено описание:</p>
        <p class="text-sm text-gray-700 leading-relaxed mb-3" x-text="improvedDescription"></p>
        <div class="flex gap-2">
            <button type="button" @click="acceptImprovedDescription"
                    class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-1.5 rounded-lg transition">
                ✓ Приеми
            </button>
            <button type="button" @click="showImprovePreview = false"
                    class="bg-white border border-gray-300 text-gray-600 text-sm px-4 py-1.5 rounded-lg hover:bg-gray-50 transition">
                ✕ Откажи
            </button>
        </div>
    </div>
</div>
```

- [ ] **Step 4: Commit**

```bash
git add resources/views/flows/create.blade.php
git commit -m "feat: add AI improve description button with preview"
```

---

## Task 7: `flows/create.blade.php` — Inline agent editor + drag-reorder (Feature 4a)

**Files:**
- Modify: `resources/views/flows/create.blade.php`

- [ ] **Step 1: Add SortableJS CDN before closing `</body>` or in the script section**

At the top of the `<script>` block (before the `flowCreator` function), add:

```html
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.3/Sortable.min.js"></script>
```

- [ ] **Step 2: Add agent editing/reordering state and methods to `flowCreator()`**

Add these methods to the Alpine component:

```js
editingIndex: null,

openEdit(index) {
    this.editingIndex = index;
},

closeEdit() {
    this.editingIndex = null;
},

saveEdit() {
    // Re-number orders after any potential reorder
    this.renumberAgents();
    this.editingIndex = null;
},

deleteAgent(index) {
    if (confirm('Изтрий агент "' + this.agents[index].name + '"?')) {
        this.agents.splice(index, 1);
        this.renumberAgents();
        if (this.editingIndex === index) this.editingIndex = null;
    }
},

addAgent() {
    const newAgent = {
        name: 'Нов агент',
        type: 'content_bg',
        role: '',
        system_prompt: '',
        prompt_template: '',
        model: AVAILABLE_MODELS[0] || ALL_MODEL_TAGS[0] || '',
        model_reason: '',
        order: this.agents.length + 1,
        is_verifier: false,
        qa_threshold: null,
        capabilities: [],
        strengths: '',
        limitations: '',
        input_description: '',
        output_description: '',
        config: { temperature: 0.7, num_predict: 1000 },
    };
    this.agents.push(newAgent);
    this.editingIndex = this.agents.length - 1;
},

moveAgent(index, direction) {
    const newIndex = index + direction;
    if (newIndex < 0 || newIndex >= this.agents.length) return;
    const tmp = this.agents[index];
    this.agents[index] = this.agents[newIndex];
    this.agents[newIndex] = tmp;
    this.renumberAgents();
    if (this.editingIndex === index) this.editingIndex = newIndex;
},

renumberAgents() {
    this.agents.forEach((a, i) => { a.order = i + 1; });
},

initSortable() {
    const el = document.getElementById('agent-sortable-list');
    if (!el || typeof Sortable === 'undefined') return;
    Sortable.create(el, {
        handle: '.drag-handle',
        animation: 150,
        onEnd: (evt) => {
            // Reflect DOM order back into Alpine array
            const moved = this.agents.splice(evt.oldIndex, 1)[0];
            this.agents.splice(evt.newIndex, 0, moved);
            this.renumberAgents();
        },
    });
},
```

Also update `init()` to call `initSortable()` after agents are loaded:

```js
this.$nextTick(() => this.initSortable());
```

And watch for agents changes to reinit:

```js
this.$watch('agents', () => {
    this.$nextTick(() => this.initSortable());
});
```

- [ ] **Step 3: Replace the agent preview section (Step 3 card) in Blade**

Replace the entire `{{-- Step 3: Agent Preview --}}` section with:

```blade
{{-- Step 3: Agent Preview + Inline Editor --}}
<div x-show="agents.length > 0" x-cloak class="bg-white rounded-xl border border-gray-200 mb-6">
    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
        <h2 class="text-lg font-semibold text-gray-900">
            3. Агенти (<span x-text="agents.length"></span>)
        </h2>
        <span class="text-sm text-gray-400">Влачи за пренареждане или използвай ↑↓</span>
    </div>

    <div id="agent-sortable-list" class="divide-y divide-gray-50">
        <template x-for="(agent, index) in agents" :key="index">
            <div>
                {{-- Hidden inputs for form submission --}}
                <input type="hidden" :name="'agents['+index+'][name]'"              :value="agent.name">
                <input type="hidden" :name="'agents['+index+'][type]'"              :value="agent.type">
                <input type="hidden" :name="'agents['+index+'][role]'"              :value="agent.role">
                <input type="hidden" :name="'agents['+index+'][system_prompt]'"     :value="agent.system_prompt">
                <input type="hidden" :name="'agents['+index+'][strengths]'"         :value="agent.strengths">
                <input type="hidden" :name="'agents['+index+'][limitations]'"       :value="agent.limitations">
                <input type="hidden" :name="'agents['+index+'][input_description]'" :value="agent.input_description">
                <input type="hidden" :name="'agents['+index+'][output_description]'" :value="agent.output_description">
                <input type="hidden" :name="'agents['+index+'][prompt_template]'"   :value="agent.prompt_template">
                <input type="hidden" :name="'agents['+index+'][model_reason]'"      :value="agent.model_reason">
                <input type="hidden" :name="'agents['+index+'][order]'"             :value="agent.order">
                <input type="hidden" :name="'agents['+index+'][is_verifier]'"       :value="agent.is_verifier ? '1' : '0'">
                <input type="hidden" :name="'agents['+index+'][qa_threshold]'"      :value="agent.qa_threshold">
                <input type="hidden" :name="'agents['+index+'][config][temperature]'" :value="agent.config ? agent.config.temperature : 0.7">
                <input type="hidden" :name="'agents['+index+'][config][num_predict]'" :value="agent.config ? agent.config.num_predict : 1000">
                <input type="hidden" :name="'agents['+index+'][model]'"             :value="agent.model">
                <template x-if="agent.capabilities">
                    <template x-for="(cap, ci) in agent.capabilities" :key="ci">
                        <input type="hidden" :name="'agents['+index+'][capabilities]['+ci+']'" :value="cap">
                    </template>
                </template>

                {{-- Agent card row --}}
                <div class="p-4 flex gap-3 items-start">
                    {{-- Drag handle --}}
                    <div class="drag-handle flex flex-col gap-1 cursor-grab pt-1 opacity-30 hover:opacity-100 transition shrink-0"
                         title="Влачи за пренареждане">
                        <span class="block w-4 h-0.5 bg-gray-600 rounded"></span>
                        <span class="block w-4 h-0.5 bg-gray-600 rounded"></span>
                        <span class="block w-4 h-0.5 bg-gray-600 rounded"></span>
                    </div>

                    {{-- Order badge --}}
                    <span class="w-7 h-7 bg-indigo-100 text-indigo-700 rounded-full flex items-center justify-center text-xs font-bold shrink-0 mt-0.5"
                          x-text="agent.order"></span>

                    {{-- Info --}}
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap mb-1">
                            <span class="font-semibold text-gray-900 text-sm" x-text="agent.name"></span>
                            <span class="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded font-mono" x-text="agent.type"></span>
                            <span x-show="agent.is_verifier" class="text-xs bg-orange-100 text-orange-600 px-2 py-0.5 rounded-full">QA verifier</span>
                        </div>
                        <p class="text-xs text-gray-500 leading-relaxed" x-text="(agent.role || '').substring(0,120) + ((agent.role||'').length > 120 ? '...' : '')"></p>
                    </div>

                    {{-- Actions --}}
                    <div class="flex items-center gap-1 shrink-0">
                        <button type="button" @click="moveAgent(index, -1)" :disabled="index === 0"
                                class="text-gray-400 hover:text-gray-700 disabled:opacity-20 px-1.5 py-1 rounded text-sm transition"
                                title="Премести нагоре">↑</button>
                        <button type="button" @click="moveAgent(index, 1)" :disabled="index === agents.length - 1"
                                class="text-gray-400 hover:text-gray-700 disabled:opacity-20 px-1.5 py-1 rounded text-sm transition"
                                title="Премести надолу">↓</button>
                        <button type="button" @click="editingIndex === index ? closeEdit() : openEdit(index)"
                                class="bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs px-2.5 py-1.5 rounded-lg transition">
                            <span x-text="editingIndex === index ? '✕ Затвори' : '✏ Редактирай'"></span>
                        </button>
                        <button type="button" @click="deleteAgent(index)"
                                class="text-red-400 hover:text-red-600 px-1.5 py-1 text-sm transition"
                                title="Изтрий агент">✕</button>
                    </div>
                </div>

                {{-- Inline edit panel --}}
                <div x-show="editingIndex === index" x-cloak
                     class="mx-4 mb-4 bg-indigo-50 border border-indigo-200 rounded-xl p-4">
                    <h4 class="text-xs font-semibold text-indigo-700 uppercase tracking-wide mb-3">Редактиране на агент</h4>
                    <div class="grid grid-cols-2 gap-3 mb-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Име</label>
                            <input type="text" x-model="agent.name"
                                   class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Тип</label>
                            <select x-model="agent.type"
                                    class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="researcher">researcher</option>
                                <option value="analyzer">analyzer</option>
                                <option value="content_bg">content_bg</option>
                                <option value="content_en">content_en</option>
                                <option value="hashtag">hashtag</option>
                                <option value="image_prompt">image_prompt</option>
                                <option value="caption_writer">caption_writer</option>
                                <option value="translator">translator</option>
                                <option value="qa_verifier">qa_verifier</option>
                                <option value="summarizer">summarizer</option>
                                <option value="decision">decision</option>
                                <option value="publisher">publisher</option>
                            </select>
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs font-medium text-gray-600 mb-1">Роля / Описание (BG)</label>
                            <textarea x-model="agent.role" rows="2"
                                      class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs font-medium text-gray-600 mb-1">System промпт (BG)</label>
                            <textarea x-model="agent.system_prompt" rows="3"
                                      class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                      placeholder="Ти си специализиран агент за..."></textarea>
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs font-medium text-gray-600 mb-1">Промпт шаблон (BG)</label>
                            <textarea x-model="agent.prompt_template" rows="5"
                                      class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                      placeholder="Инструкции за агента с {{placeholder}}-и..."></textarea>
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs font-medium text-gray-600 mb-1">Модел</label>
                            <select x-model="agent.model"
                                    class="w-full border border-gray-300 rounded-lg px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                @foreach($models as $m)
                                    <option value="{{ $m->ollama_tag }}">
                                        {{ !$m->is_available ? '⚠ ' : '' }}{{ $m->display_name }} ({{ $m->ollama_tag }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" @click="closeEdit"
                                class="bg-white border border-gray-300 text-gray-600 text-sm px-3 py-1.5 rounded-lg hover:bg-gray-50 transition">
                            Откажи
                        </button>
                        <button type="button" @click="saveEdit"
                                class="bg-green-600 hover:bg-green-700 text-white text-sm font-semibold px-4 py-1.5 rounded-lg transition">
                            ✓ Запази
                        </button>
                    </div>
                </div>
            </div>
        </template>
    </div>

    {{-- Add agent --}}
    <div class="px-6 py-3 border-t border-dashed border-gray-200 flex justify-center">
        <button type="button" @click="addAgent"
                class="text-indigo-600 hover:text-indigo-800 text-sm font-semibold flex items-center gap-1.5 px-4 py-2 rounded-lg hover:bg-indigo-50 transition">
            ＋ Добави агент
        </button>
    </div>

    {{-- QA position warning --}}
    <div x-show="agents.length > 0 && agents[agents.length-1].type !== 'qa_verifier' && agents.some(a => a.type === 'qa_verifier')"
         x-cloak
         class="mx-4 mb-4 bg-amber-50 border border-amber-200 rounded-lg px-4 py-2 text-xs text-amber-700">
        ⚠ QA verifier агентът не е последен в pipeline-а. Препоръчително е да е на последна позиция.
    </div>
</div>
```

- [ ] **Step 4: Commit**

```bash
git add resources/views/flows/create.blade.php
git commit -m "feat: inline agent editor with drag-reorder, add/delete, and up/down buttons"
```

---

## Task 8: `flows/edit.blade.php` — cron schedule Apple-style UI (Feature 6)

**Files:**
- Modify: `resources/views/flows/edit.blade.php`

- [ ] **Step 1: Add Alpine component to the form**

Wrap the `<form>` in an Alpine component that mirrors the cron logic from create.blade.php. Change the opening form tag to include `x-data="scheduleEditor()"`:

```blade
<form action="{{ route('flows.update', $flow) }}" method="POST" class="space-y-6"
      x-data="scheduleEditor('{{ old('schedule_cron', $flow->schedule_cron) }}')">
```

- [ ] **Step 2: Add `scheduleEditor` Alpine function below the form**

```html
<script>
function scheduleEditor(existingCron) {
    return {
        schedule: { preset: 'none', hour: '10', dayOfWeek: '1', dayOfMonth: '1', customCron: '', showCustom: false },

        get cronValue() {
            const s = this.schedule;
            if (s.preset === 'none') return '';
            if (s.preset === 'hourly') return '0 * * * *';
            if (s.preset === 'daily') return `0 ${s.hour} * * *`;
            if (s.preset === 'weekly') return `0 ${s.hour} * * ${s.dayOfWeek}`;
            if (s.preset === 'monthly') return `0 ${s.hour} ${s.dayOfMonth} * *`;
            if (s.preset === 'custom') return s.customCron;
            return '';
        },

        init() {
            if (!existingCron) return;
            // Try to parse existing cron into a preset
            const parts = existingCron.trim().split(/\s+/);
            if (parts.length !== 5) { this.schedule.preset = 'custom'; this.schedule.customCron = existingCron; this.schedule.showCustom = true; return; }
            const [min, hour, dom, month, dow] = parts;
            if (min==='0' && hour==='*' && dom==='*' && month==='*' && dow==='*') { this.schedule.preset='hourly'; return; }
            if (min==='0' && dom==='*' && month==='*' && dow==='*') { this.schedule.preset='daily'; this.schedule.hour=hour; return; }
            if (min==='0' && dom==='*' && month==='*') { this.schedule.preset='weekly'; this.schedule.hour=hour; this.schedule.dayOfWeek=dow; return; }
            if (min==='0' && month==='*' && dow==='*') { this.schedule.preset='monthly'; this.schedule.hour=hour; this.schedule.dayOfMonth=dom; return; }
            this.schedule.preset = 'custom'; this.schedule.customCron = existingCron; this.schedule.showCustom = true;
        },
    };
}
</script>
```

- [ ] **Step 3: Replace the cron input in `flows/edit.blade.php`**

Replace the `<div>` containing `Cron разписание` with the same schedule picker Blade markup from Task 5 Step 3 (it's identical — copy it verbatim).

- [ ] **Step 4: Commit**

```bash
git add resources/views/flows/edit.blade.php
git commit -m "feat: Apple-style schedule picker on flows/edit page"
```

---

## Task 9: Manual smoke test

- [ ] **Step 1: Open `http://flowai.local/companies/1/flows/create`**
  - Verify cron picker shows 5 preset buttons
  - Select "Дневно", pick 14:00 — confirm summary shows "всеки ден в 14:00" and cron is `0 14 * * *`
  - Verify "По избор" button reveals raw cron input

- [ ] **Step 2: Test AI improve description**
  - Type a short description, click "✨ Подобри с AI"
  - Verify spinner shows, then preview panel appears with improved text
  - Click "Приеми" — verify textarea updates

- [ ] **Step 3: Test agent generation**
  - Fill name + description, click "Генерирай агенти с AI"
  - Verify generated agents have Bulgarian names (not snake_case)
  - Verify `role` field is 2-3 sentences in Bulgarian
  - Click "▼ Покажи всичко" — verify `system_prompt` and `prompt_template` are in Bulgarian and detailed

- [ ] **Step 4: Test inline agent editor**
  - Click "✏ Редактирай" on an agent — verify inline panel opens
  - Edit name, role, system_prompt, prompt_template
  - Click "✓ Запази" — verify card updates
  - Click "✕" delete — verify confirmation and removal
  - Click "＋ Добави агент" — verify new agent appears at bottom in edit mode

- [ ] **Step 5: Test drag-reorder**
  - Drag an agent to a different position — verify order numbers update
  - Use ↑↓ buttons to move agents — verify they move correctly

- [ ] **Step 6: Save the flow**
  - Click "💾 Запази flow с агентите"
  - Verify redirect to flow show page
  - Verify agents saved with correct Bulgarian names/descriptions

- [ ] **Step 7: Test agents/edit**
  - Navigate to edit an individual agent
  - Verify "System промпт" field is visible and editable
  - Save and verify it persists

- [ ] **Step 8: Test flows/edit cron picker**
  - Navigate to edit an existing flow with a cron schedule
  - Verify the cron picker pre-selects the correct preset
