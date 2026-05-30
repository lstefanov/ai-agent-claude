# Agent Template Picker — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a popup agent-template picker to the Flow create page, with per-company and system templates managed via dedicated CRUD sections.

**Architecture:** New `agent_templates` table stores both system (company_id = null) and company-specific templates. A lazy-loaded JSON endpoint feeds the Alpine-powered popup modal. Admin routes protected by a session-based password gate (the app has no user auth system).

**Tech Stack:** Laravel 11, Alpine.js 3, Blade, Tailwind CSS (CDN), PHPUnit/Feature tests with RefreshDatabase

---

## File Map

| File | Action | Purpose |
|---|---|---|
| `database/migrations/…_create_agent_templates_table.php` | Create | New table |
| `database/seeders/AgentTemplateSeeder.php` | Create | 8 system templates |
| `database/seeders/DatabaseSeeder.php` | Modify | Call seeder |
| `app/Models/AgentTemplate.php` | Create | Eloquent model |
| `app/Http/Middleware/IsAdmin.php` | Create | Session-based admin gate |
| `app/Http/Controllers/AgentTemplateController.php` | Create | Picker API + company CRUD |
| `app/Http/Controllers/Admin/AgentTemplateController.php` | Create | Admin system template CRUD |
| `app/Http/Controllers/Admin/AdminAuthController.php` | Create | Login/logout for admin gate |
| `bootstrap/app.php` | Modify | Register `is_admin` middleware alias |
| `routes/web.php` | Modify | Add all new routes |
| `resources/views/admin/layouts/admin.blade.php` | Create | Admin layout |
| `resources/views/admin/login.blade.php` | Create | Admin login form |
| `resources/views/admin/agent-templates/index.blade.php` | Create | System templates list |
| `resources/views/admin/agent-templates/create.blade.php` | Create | Create system template |
| `resources/views/admin/agent-templates/edit.blade.php` | Create | Edit system template |
| `resources/views/companies/agent-templates/index.blade.php` | Create | Company templates list |
| `resources/views/companies/agent-templates/create.blade.php` | Create | Create company template |
| `resources/views/companies/agent-templates/edit.blade.php` | Create | Edit company template |
| `resources/views/companies/show.blade.php` | Modify | Add "Агенти" link |
| `resources/views/flows/create.blade.php` | Modify | Replace addAgent() with picker modal |
| `resources/views/layouts/app.blade.php` | Modify | Add Admin nav link |
| `tests/Feature/AgentTemplatePickerTest.php` | Create | Picker endpoint tests |
| `tests/Feature/AgentTemplateAdminTest.php` | Create | Admin CRUD tests |
| `tests/Feature/CompanyAgentTemplateTest.php` | Create | Company CRUD tests |

---

## Task 1: Migration — `agent_templates` table

**Files:**
- Create: `database/migrations/2026_05_30_100000_create_agent_templates_table.php`

- [ ] **Step 1: Create the migration file**

```php
<?php
// database/migrations/2026_05_30_100000_create_agent_templates_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description');
            $table->string('icon', 10)->default('🤖');
            $table->string('type', 50);
            $table->text('role')->nullable();
            $table->text('system_prompt')->nullable();
            $table->longText('prompt_template')->nullable();
            $table->string('model', 100)->default('');
            $table->json('capabilities')->nullable();
            $table->text('strengths')->nullable();
            $table->text('limitations')->nullable();
            $table->text('input_description')->nullable();
            $table->text('output_description')->nullable();
            $table->boolean('is_verifier')->default(false);
            $table->unsignedTinyInteger('qa_threshold')->nullable();
            $table->json('config')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_templates');
    }
};
```

- [ ] **Step 2: Run the migration**

```bash
cd /Users/lub/Sites/localhost/ai-agent-claude && php artisan migrate
```

Expected: `agent_templates` table created, no errors.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_05_30_100000_create_agent_templates_table.php
git commit -m "feat: add agent_templates migration"
```

---

## Task 2: `AgentTemplate` model

**Files:**
- Create: `app/Models/AgentTemplate.php`

- [ ] **Step 1: Write a failing test**

Create `tests/Feature/AgentTemplatePickerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AgentTemplate;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentTemplatePickerTest extends TestCase
{
    use RefreshDatabase;

    public function test_picker_returns_system_and_company_templates(): void
    {
        $company = Company::create([
            'name' => 'Test Co', 'description' => '', 'industry' => 'IT', 'language' => 'bg',
        ]);

        AgentTemplate::create([
            'company_id' => null,
            'name' => 'Email Изпращач', 'description' => 'Изпраща имейл', 'icon' => '📧',
            'type' => 'email', 'sort_order' => 1,
        ]);

        AgentTemplate::create([
            'company_id' => $company->id,
            'name' => 'Социален Пост', 'description' => 'FB пост', 'icon' => '💬',
            'type' => 'content_bg', 'sort_order' => 1,
        ]);

        $response = $this->getJson("/agent-templates/picker?company_id={$company->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'system')
            ->assertJsonCount(1, 'company')
            ->assertJsonPath('system.0.name', 'Email Изпращач')
            ->assertJsonPath('company.0.name', 'Социален Пост');
    }

    public function test_picker_excludes_other_company_templates(): void
    {
        $company1 = Company::create(['name' => 'Co1', 'description' => '', 'industry' => 'IT', 'language' => 'bg']);
        $company2 = Company::create(['name' => 'Co2', 'description' => '', 'industry' => 'IT', 'language' => 'bg']);

        AgentTemplate::create([
            'company_id' => $company2->id,
            'name' => 'Other Co Template', 'description' => 'x', 'icon' => '🔍',
            'type' => 'analyzer', 'sort_order' => 1,
        ]);

        $response = $this->getJson("/agent-templates/picker?company_id={$company1->id}");

        $response->assertOk()->assertJsonCount(0, 'company');
    }
}
```

- [ ] **Step 2: Run — expect failure (class not found)**

```bash
cd /Users/lub/Sites/localhost/ai-agent-claude && php artisan test tests/Feature/AgentTemplatePickerTest.php
```

Expected: FAIL — `App\Models\AgentTemplate` not found.

- [ ] **Step 3: Create the model**

```php
<?php
// app/Models/AgentTemplate.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentTemplate extends Model
{
    protected $fillable = [
        'company_id', 'name', 'description', 'icon', 'type',
        'role', 'system_prompt', 'prompt_template', 'model',
        'capabilities', 'strengths', 'limitations',
        'input_description', 'output_description',
        'is_verifier', 'qa_threshold', 'config', 'sort_order',
    ];

    protected $casts = [
        'capabilities' => 'array',
        'config'       => 'array',
        'is_verifier'  => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isSystem(): bool
    {
        return $this->company_id === null;
    }
}
```

- [ ] **Step 4: Run tests — expect pass**

```bash
cd /Users/lub/Sites/localhost/ai-agent-claude && php artisan test tests/Feature/AgentTemplatePickerTest.php
```

Expected: FAIL still — route not found. That's correct; we haven't added the route yet.

- [ ] **Step 5: Commit the model**

```bash
git add app/Models/AgentTemplate.php tests/Feature/AgentTemplatePickerTest.php
git commit -m "feat: add AgentTemplate model and picker tests"
```

---

## Task 3: Picker API endpoint

**Files:**
- Create: `app/Http/Controllers/AgentTemplateController.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Create the controller with picker method**

```php
<?php
// app/Http/Controllers/AgentTemplateController.php

namespace App\Http\Controllers;

use App\Models\AgentTemplate;
use App\Models\Company;
use App\Models\LlmModel;
use Illuminate\Http\Request;

class AgentTemplateController extends Controller
{
    public function picker(Request $request)
    {
        $companyId = $request->integer('company_id');

        $system = AgentTemplate::whereNull('company_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id','name','description','icon','type','role','system_prompt',
                   'prompt_template','model','capabilities','strengths','limitations',
                   'input_description','output_description','is_verifier',
                   'qa_threshold','config']);

        $company = AgentTemplate::where('company_id', $companyId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id','name','description','icon','type','role','system_prompt',
                   'prompt_template','model','capabilities','strengths','limitations',
                   'input_description','output_description','is_verifier',
                   'qa_threshold','config']);

        return response()->json(compact('system', 'company'));
    }

    public function index(Company $company)
    {
        $templates = AgentTemplate::where('company_id', $company->id)
            ->orderBy('sort_order')->orderBy('name')->get();

        return view('companies.agent-templates.index', compact('company', 'templates'));
    }

    public function create(Company $company)
    {
        $models = LlmModel::where('is_enabled', true)
            ->orderBy('display_name')->get();

        return view('companies.agent-templates.create', compact('company', 'models'));
    }

    public function store(Request $request, Company $company)
    {
        $data = $this->validateTemplate($request);
        $data['company_id'] = $company->id;
        AgentTemplate::create($data);

        return redirect()->route('companies.agent-templates.index', $company)
            ->with('success', 'Шаблонът е създаден.');
    }

    public function edit(Company $company, AgentTemplate $agentTemplate)
    {
        abort_if($agentTemplate->company_id !== $company->id, 403);

        $models = LlmModel::where('is_enabled', true)
            ->orderBy('display_name')->get();

        return view('companies.agent-templates.edit', compact('company', 'agentTemplate', 'models'));
    }

    public function update(Request $request, Company $company, AgentTemplate $agentTemplate)
    {
        abort_if($agentTemplate->company_id !== $company->id, 403);

        $agentTemplate->update($this->validateTemplate($request));

        return redirect()->route('companies.agent-templates.index', $company)
            ->with('success', 'Шаблонът е обновен.');
    }

    public function destroy(Company $company, AgentTemplate $agentTemplate)
    {
        abort_if($agentTemplate->company_id !== $company->id, 403);

        $agentTemplate->delete();

        return redirect()->route('companies.agent-templates.index', $company)
            ->with('success', 'Шаблонът е изтрит.');
    }

    private function validateTemplate(Request $request): array
    {
        return $request->validate([
            'name'               => 'required|string|max:255',
            'description'        => 'required|string|max:500',
            'icon'               => 'required|string|max:10',
            'type'               => 'required|string|max:50',
            'role'               => 'nullable|string',
            'system_prompt'      => 'nullable|string',
            'prompt_template'    => 'nullable|string',
            'model'              => 'nullable|string|max:100',
            'is_verifier'        => 'boolean',
            'qa_threshold'       => 'nullable|integer|min:0|max:100',
            'sort_order'         => 'integer|min:0',
            'config.temperature' => 'nullable|numeric|min:0|max:2',
            'config.num_predict' => 'nullable|integer|min:-1',
        ]);
    }
}
```

- [ ] **Step 2: Add routes to `routes/web.php`**

Add after the existing `// Agent edit` block:

```php
// Agent template picker (AJAX for popup)
Route::get('agent-templates/picker', [AgentTemplateController::class, 'picker'])->name('agent-templates.picker');

// Company agent templates (CRUD)
Route::get('companies/{company}/agent-templates', [AgentTemplateController::class, 'index'])->name('companies.agent-templates.index');
Route::get('companies/{company}/agent-templates/create', [AgentTemplateController::class, 'create'])->name('companies.agent-templates.create');
Route::post('companies/{company}/agent-templates', [AgentTemplateController::class, 'store'])->name('companies.agent-templates.store');
Route::get('companies/{company}/agent-templates/{agentTemplate}/edit', [AgentTemplateController::class, 'edit'])->name('companies.agent-templates.edit');
Route::put('companies/{company}/agent-templates/{agentTemplate}', [AgentTemplateController::class, 'update'])->name('companies.agent-templates.update');
Route::delete('companies/{company}/agent-templates/{agentTemplate}', [AgentTemplateController::class, 'destroy'])->name('companies.agent-templates.destroy');
```

Also add the import at the top of `routes/web.php`:

```php
use App\Http\Controllers\AgentTemplateController;
```

- [ ] **Step 3: Run the picker tests — expect pass**

```bash
cd /Users/lub/Sites/localhost/ai-agent-claude && php artisan test tests/Feature/AgentTemplatePickerTest.php
```

Expected: 2 tests pass.

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/AgentTemplateController.php routes/web.php
git commit -m "feat: add AgentTemplate picker endpoint and company CRUD routes"
```

---

## Task 4: Seeder with 8 system templates

**Files:**
- Create: `database/seeders/AgentTemplateSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`

- [ ] **Step 1: Create the seeder**

```php
<?php
// database/seeders/AgentTemplateSeeder.php

namespace Database\Seeders;

use App\Models\AgentTemplate;
use Illuminate\Database\Seeder;

class AgentTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'icon' => '📧', 'name' => 'Email Изпращач', 'type' => 'email', 'sort_order' => 1,
                'description' => 'Извлича имейл адрес от описанието на Flow-а и изпраща репорт автоматично.',
                'role' => 'Изпраща финалния репорт на посочения в описанието имейл адрес.',
                'system_prompt' => 'Ти си агент за изпращане на имейли. Извличаш имейл адреси от текст и изпращаш доклади.',
                'prompt_template' => 'Извлечи имейл адреса от следния текст и изпрати репорта: {input}',
                'config' => ['temperature' => 0.1, 'num_predict' => 100],
            ],
            [
                'icon' => '🔍', 'name' => 'Уеб Изследовател', 'type' => 'researcher', 'sort_order' => 2,
                'description' => 'Търси в интернет и събира актуална информация по зададена тема.',
                'role' => 'Провежда уеб търсения и синтезира актуална информация от различни източници.',
                'system_prompt' => 'Ти си изследовател. Търсиш в интернет и предоставяш актуална, проверена информация с цитати на източници.',
                'prompt_template' => 'Изследвай следната тема и предостави структуриран доклад с източници: {input}',
                'config' => ['temperature' => 0.3, 'num_predict' => 2000],
            ],
            [
                'icon' => '📊', 'name' => 'Анализатор', 'type' => 'analyzer', 'sort_order' => 3,
                'description' => 'Анализира входни данни и извлича ключови структурирани изводи.',
                'role' => 'Анализира данни, текст или информация и извлича структурирани изводи и препоръки.',
                'system_prompt' => 'Ти си аналитик. Анализираш предоставената информация и извличаш ключови изводи в структуриран формат.',
                'prompt_template' => 'Анализирай следното и предостави структурирани изводи:\n\n{input}',
                'config' => ['temperature' => 0.4, 'num_predict' => 1500],
            ],
            [
                'icon' => '✍️', 'name' => 'Съдържание BG', 'type' => 'content_bg', 'sort_order' => 4,
                'description' => 'Създава качествено текстово съдържание на български език.',
                'role' => 'Генерира оригинално, качествено текстово съдържание на правилен български.',
                'system_prompt' => 'Ти си копирайтър. Пишеш качествено, ангажиращо съдържание на правилен български език.',
                'prompt_template' => 'Напиши съдържание по следната тема:\n\n{input}',
                'config' => ['temperature' => 0.7, 'num_predict' => 1500],
            ],
            [
                'icon' => '🌐', 'name' => 'Преводач', 'type' => 'translator', 'sort_order' => 5,
                'description' => 'Превежда текст между езици с запазен стил и тон.',
                'role' => 'Превежда текст точно и естествено, запазвайки стила и тона на оригинала.',
                'system_prompt' => 'Ти си преводач. Превеждаш текст точно и естествено, запазвайки стила, тона и смисъла на оригинала.',
                'prompt_template' => 'Преведи следния текст:\n\n{input}',
                'config' => ['temperature' => 0.3, 'num_predict' => 2000],
            ],
            [
                'icon' => '✅', 'name' => 'QA Верификатор', 'type' => 'qa_verifier', 'sort_order' => 6,
                'description' => 'Проверява качеството на изходния текст по зададен праг.',
                'role' => 'Верифицира качеството на генерираното съдържание и дава оценка.',
                'system_prompt' => 'Ти си QA специалист. Оценяваш качеството на текст по скала 0-100 и обясняваш оценката си.',
                'prompt_template' => 'Оцени качеството на следния текст по скала 0-100:\n\n{input}',
                'is_verifier' => true,
                'qa_threshold' => 75,
                'config' => ['temperature' => 0.2, 'num_predict' => 500],
            ],
            [
                'icon' => '📝', 'name' => 'Обобщителят', 'type' => 'summarizer', 'sort_order' => 7,
                'description' => 'Съкращава дълъг текст до кратко и ясно резюме.',
                'role' => 'Създава кратки, информативни резюмета на дълги текстове.',
                'system_prompt' => 'Ти си специалист по резюмиране. Създаваш кратки, информативни резюмета, запазвайки ключовите факти.',
                'prompt_template' => 'Резюмирай следния текст в не повече от 3-5 изречения:\n\n{input}',
                'config' => ['temperature' => 0.4, 'num_predict' => 500],
            ],
            [
                'icon' => '🤔', 'name' => 'Решение', 'type' => 'decision', 'sort_order' => 8,
                'description' => 'Взема решение по зададени критерии и обяснява избора.',
                'role' => 'Анализира варианти и взема информирано решение с обосновка.',
                'system_prompt' => 'Ти си Decision maker. Анализираш варианти по зададени критерии и избираш оптималното решение с ясна обосновка.',
                'prompt_template' => 'Анализирай вариантите и вземи решение:\n\n{input}',
                'config' => ['temperature' => 0.5, 'num_predict' => 800],
            ],
        ];

        foreach ($templates as $data) {
            AgentTemplate::firstOrCreate(
                ['company_id' => null, 'name' => $data['name']],
                $data
            );
        }
    }
}
```

- [ ] **Step 2: Register seeder in `DatabaseSeeder.php`**

Open `database/seeders/DatabaseSeeder.php` and add the call:

```php
public function run(): void
{
    $this->call([
        AgentTemplateSeeder::class,
    ]);
}
```

- [ ] **Step 3: Run the seeder**

```bash
cd /Users/lub/Sites/localhost/ai-agent-claude && php artisan db:seed --class=AgentTemplateSeeder
```

Expected: 8 rows inserted into `agent_templates`.

Verify:

```bash
php artisan tinker --execute="echo App\Models\AgentTemplate::count();"
```

Expected: `8`

- [ ] **Step 4: Commit**

```bash
git add database/seeders/AgentTemplateSeeder.php database/seeders/DatabaseSeeder.php
git commit -m "feat: add AgentTemplateSeeder with 8 system templates"
```

---

## Task 5: Admin password gate (middleware + login)

**Files:**
- Create: `app/Http/Middleware/IsAdmin.php`
- Create: `app/Http/Controllers/Admin/AdminAuthController.php`
- Modify: `bootstrap/app.php`

No new test file — admin login tested inline in `AgentTemplateAdminTest.php` (Task 7).

- [ ] **Step 1: Create `IsAdmin` middleware**

```php
<?php
// app/Http/Middleware/IsAdmin.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class IsAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if (! session('admin_authenticated')) {
            return redirect()->route('admin.login');
        }

        return $next($request);
    }
}
```

- [ ] **Step 2: Create `AdminAuthController`**

```php
<?php
// app/Http/Controllers/Admin/AdminAuthController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AdminAuthController extends Controller
{
    public function showLogin()
    {
        if (session('admin_authenticated')) {
            return redirect()->route('admin.agent-templates.index');
        }

        return view('admin.login');
    }

    public function login(Request $request)
    {
        $request->validate(['password' => 'required|string']);

        if ($request->password !== config('app.admin_password')) {
            return back()->withErrors(['password' => 'Грешна парола.']);
        }

        session(['admin_authenticated' => true]);

        return redirect()->route('admin.agent-templates.index');
    }

    public function logout()
    {
        session()->forget('admin_authenticated');

        return redirect()->route('admin.login');
    }
}
```

- [ ] **Step 3: Add `ADMIN_PASSWORD` to `.env`**

Open `.env` and add:

```
ADMIN_PASSWORD=changeme
```

- [ ] **Step 4: Add `admin_password` to `config/app.php`**

Open `config/app.php` and add inside the returned array:

```php
'admin_password' => env('ADMIN_PASSWORD', 'changeme'),
```

- [ ] **Step 5: Register middleware alias in `bootstrap/app.php`**

```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'is_admin' => \App\Http\Middleware\IsAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
```

- [ ] **Step 6: Add admin auth routes to `routes/web.php`**

Add at the top of the imports:

```php
use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AgentTemplateController as AdminAgentTemplateController;
```

Add routes after the existing `// Home` route:

```php
// Admin auth
Route::get('admin/login', [AdminAuthController::class, 'showLogin'])->name('admin.login');
Route::post('admin/login', [AdminAuthController::class, 'login'])->name('admin.login.post');
Route::post('admin/logout', [AdminAuthController::class, 'logout'])->name('admin.logout');

// Admin agent templates (protected)
Route::middleware('is_admin')->prefix('admin')->name('admin.')->group(function () {
    Route::get('agent-templates', [AdminAgentTemplateController::class, 'index'])->name('agent-templates.index');
    Route::get('agent-templates/create', [AdminAgentTemplateController::class, 'create'])->name('agent-templates.create');
    Route::post('agent-templates', [AdminAgentTemplateController::class, 'store'])->name('agent-templates.store');
    Route::get('agent-templates/{agentTemplate}/edit', [AdminAgentTemplateController::class, 'edit'])->name('agent-templates.edit');
    Route::put('agent-templates/{agentTemplate}', [AdminAgentTemplateController::class, 'update'])->name('agent-templates.update');
    Route::delete('agent-templates/{agentTemplate}', [AdminAgentTemplateController::class, 'destroy'])->name('agent-templates.destroy');
});
```

- [ ] **Step 7: Commit**

```bash
git add app/Http/Middleware/IsAdmin.php \
        app/Http/Controllers/Admin/AdminAuthController.php \
        bootstrap/app.php routes/web.php config/app.php .env
git commit -m "feat: add admin password gate middleware and auth routes"
```

---

## Task 6: Admin `AgentTemplateController` (system templates CRUD)

**Files:**
- Create: `app/Http/Controllers/Admin/AgentTemplateController.php`

- [ ] **Step 1: Create the controller**

```php
<?php
// app/Http/Controllers/Admin/AgentTemplateController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgentTemplate;
use App\Models\LlmModel;
use Illuminate\Http\Request;

class AgentTemplateController extends Controller
{
    public function index()
    {
        $templates = AgentTemplate::whereNull('company_id')
            ->orderBy('sort_order')->orderBy('name')->get();

        return view('admin.agent-templates.index', compact('templates'));
    }

    public function create()
    {
        $models = LlmModel::where('is_enabled', true)->orderBy('display_name')->get();

        return view('admin.agent-templates.create', compact('models'));
    }

    public function store(Request $request)
    {
        $data = $this->validateTemplate($request);
        $data['company_id'] = null;
        AgentTemplate::create($data);

        return redirect()->route('admin.agent-templates.index')
            ->with('success', 'Системният шаблон е създаден.');
    }

    public function edit(AgentTemplate $agentTemplate)
    {
        abort_if($agentTemplate->company_id !== null, 403);

        $models = LlmModel::where('is_enabled', true)->orderBy('display_name')->get();

        return view('admin.agent-templates.edit', compact('agentTemplate', 'models'));
    }

    public function update(Request $request, AgentTemplate $agentTemplate)
    {
        abort_if($agentTemplate->company_id !== null, 403);

        $agentTemplate->update($this->validateTemplate($request));

        return redirect()->route('admin.agent-templates.index')
            ->with('success', 'Системният шаблон е обновен.');
    }

    public function destroy(AgentTemplate $agentTemplate)
    {
        abort_if($agentTemplate->company_id !== null, 403);

        $agentTemplate->delete();

        return redirect()->route('admin.agent-templates.index')
            ->with('success', 'Системният шаблон е изтрит.');
    }

    private function validateTemplate(Request $request): array
    {
        return $request->validate([
            'name'               => 'required|string|max:255',
            'description'        => 'required|string|max:500',
            'icon'               => 'required|string|max:10',
            'type'               => 'required|string|max:50',
            'role'               => 'nullable|string',
            'system_prompt'      => 'nullable|string',
            'prompt_template'    => 'nullable|string',
            'model'              => 'nullable|string|max:100',
            'is_verifier'        => 'boolean',
            'qa_threshold'       => 'nullable|integer|min:0|max:100',
            'sort_order'         => 'integer|min:0',
            'config.temperature' => 'nullable|numeric|min:0|max:2',
            'config.num_predict' => 'nullable|integer|min:-1',
        ]);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Http/Controllers/Admin/AgentTemplateController.php
git commit -m "feat: add Admin AgentTemplateController for system templates CRUD"
```

---

## Task 7: Admin views (layout + login + templates CRUD)

**Files:**
- Create: `resources/views/admin/layouts/admin.blade.php`
- Create: `resources/views/admin/login.blade.php`
- Create: `resources/views/admin/agent-templates/index.blade.php`
- Create: `resources/views/admin/agent-templates/create.blade.php`
- Create: `resources/views/admin/agent-templates/edit.blade.php`

- [ ] **Step 1: Write failing admin tests**

Create `tests/Feature/AgentTemplateAdminTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AgentTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentTemplateAdminTest extends TestCase
{
    use RefreshDatabase;

    private function actAsAdmin(): void
    {
        session(['admin_authenticated' => true]);
    }

    public function test_admin_login_page_loads(): void
    {
        $this->get(route('admin.login'))->assertOk();
    }

    public function test_admin_login_redirects_with_correct_password(): void
    {
        config(['app.admin_password' => 'secret']);

        $this->post(route('admin.login.post'), ['password' => 'secret'])
            ->assertRedirect(route('admin.agent-templates.index'));
    }

    public function test_admin_login_fails_with_wrong_password(): void
    {
        config(['app.admin_password' => 'secret']);

        $this->post(route('admin.login.post'), ['password' => 'wrong'])
            ->assertSessionHasErrors('password');
    }

    public function test_admin_index_requires_auth(): void
    {
        $this->get(route('admin.agent-templates.index'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_admin_index_lists_system_templates(): void
    {
        $this->actAsAdmin();
        AgentTemplate::create([
            'company_id' => null, 'name' => 'Тест Шаблон',
            'description' => 'Описание', 'icon' => '🤖', 'type' => 'analyzer',
        ]);

        $this->get(route('admin.agent-templates.index'))
            ->assertOk()
            ->assertSee('Тест Шаблон');
    }

    public function test_admin_can_create_system_template(): void
    {
        $this->actAsAdmin();

        $this->post(route('admin.agent-templates.store'), [
            'name' => 'Нов Шаблон', 'description' => 'Описание', 'icon' => '🆕',
            'type' => 'summarizer', 'sort_order' => 99,
        ])->assertRedirect(route('admin.agent-templates.index'));

        $this->assertDatabaseHas('agent_templates', ['name' => 'Нов Шаблон', 'company_id' => null]);
    }

    public function test_admin_can_delete_system_template(): void
    {
        $this->actAsAdmin();
        $template = AgentTemplate::create([
            'company_id' => null, 'name' => 'За Изтриване',
            'description' => 'x', 'icon' => '🗑', 'type' => 'decision',
        ]);

        $this->delete(route('admin.agent-templates.destroy', $template))
            ->assertRedirect(route('admin.agent-templates.index'));

        $this->assertDatabaseMissing('agent_templates', ['id' => $template->id]);
    }
}
```

- [ ] **Step 2: Run — expect failure (views missing)**

```bash
cd /Users/lub/Sites/localhost/ai-agent-claude && php artisan test tests/Feature/AgentTemplateAdminTest.php
```

Expected: Tests for login/store/delete pass (no view needed), index/create fail with view not found.

- [ ] **Step 3: Create admin layout**

```blade
{{-- resources/views/admin/layouts/admin.blade.php --}}
<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Admin') — FlowAI Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
<body class="bg-gray-50 min-h-screen">
    <nav class="bg-gray-900 text-white px-6 py-3 flex items-center justify-between">
        <span class="font-bold text-lg">⚙ FlowAI Admin</span>
        <div class="flex items-center gap-4 text-sm">
            <a href="{{ route('admin.agent-templates.index') }}"
               class="hover:text-gray-300 {{ request()->routeIs('admin.agent-templates.*') ? 'text-white font-semibold' : 'text-gray-400' }}">
                Системни агенти
            </a>
            <form action="{{ route('admin.logout') }}" method="POST">
                @csrf
                <button class="text-gray-400 hover:text-white">Изход</button>
            </form>
        </div>
    </nav>
    <div class="max-w-5xl mx-auto px-6 py-8">
        @if(session('success'))
            <div class="mb-4 px-4 py-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm">
                ✓ {{ session('success') }}
            </div>
        @endif
        @yield('content')
    </div>
</body>
</html>
```

- [ ] **Step 4: Create admin login view**

```blade
{{-- resources/views/admin/login.blade.php --}}
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
```

- [ ] **Step 5: Create admin templates index view**

```blade
{{-- resources/views/admin/agent-templates/index.blade.php --}}
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

@if($templates->isEmpty())
    <div class="bg-white border border-dashed border-gray-300 rounded-xl p-12 text-center text-gray-400">
        <p class="text-3xl mb-3">🤖</p>
        <p class="text-sm">Няма системни шаблони. <a href="{{ route('admin.agent-templates.create') }}" class="text-indigo-600 underline">Добави първия.</a></p>
    </div>
@else
    <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
        @foreach($templates as $template)
        <div class="flex items-center gap-4 px-5 py-4 border-b border-gray-100 last:border-0 hover:bg-gray-50">
            <span class="text-2xl w-8 text-center">{{ $template->icon }}</span>
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2">
                    <span class="font-semibold text-gray-900 text-sm">{{ $template->name }}</span>
                    <span class="text-xs bg-violet-100 text-violet-700 px-2 py-0.5 rounded font-mono">{{ $template->type }}</span>
                </div>
                <p class="text-xs text-gray-500 truncate">{{ $template->description }}</p>
            </div>
            <div class="flex gap-2 shrink-0">
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
        @endforeach
    </div>
@endif
@endsection
```

- [ ] **Step 6: Create admin template form partial (shared for create/edit)**

Create `resources/views/admin/agent-templates/_form.blade.php`:

```blade
{{-- resources/views/admin/agent-templates/_form.blade.php --}}
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
```

- [ ] **Step 7: Create admin `create.blade.php`**

```blade
{{-- resources/views/admin/agent-templates/create.blade.php --}}
@extends('admin.layouts.admin')

@section('title', 'Нов системен шаблон')

@section('content')
<div class="mb-6">
    <a href="{{ route('admin.agent-templates.index') }}" class="text-indigo-600 hover:underline text-sm">← Системни шаблони</a>
    <h1 class="text-2xl font-bold text-gray-900 mt-2">Нов системен шаблон</h1>
</div>
<form action="{{ route('admin.agent-templates.store') }}" method="POST">
    @csrf
    @php $cancelUrl = route('admin.agent-templates.index'); $agentTemplate = null; @endphp
    @include('admin.agent-templates._form')
</form>
@endsection
```

- [ ] **Step 8: Create admin `edit.blade.php`**

```blade
{{-- resources/views/admin/agent-templates/edit.blade.php --}}
@extends('admin.layouts.admin')

@section('title', 'Редактирай: ' . $agentTemplate->name)

@section('content')
<div class="mb-6">
    <a href="{{ route('admin.agent-templates.index') }}" class="text-indigo-600 hover:underline text-sm">← Системни шаблони</a>
    <h1 class="text-2xl font-bold text-gray-900 mt-2">✏ {{ $agentTemplate->name }}</h1>
</div>
<form action="{{ route('admin.agent-templates.update', $agentTemplate) }}" method="POST">
    @csrf @method('PUT')
    @php $cancelUrl = route('admin.agent-templates.index'); @endphp
    @include('admin.agent-templates._form')
</form>
@endsection
```

- [ ] **Step 9: Run admin tests — expect all pass**

```bash
cd /Users/lub/Sites/localhost/ai-agent-claude && php artisan test tests/Feature/AgentTemplateAdminTest.php
```

Expected: 7 tests pass.

- [ ] **Step 10: Commit**

```bash
git add resources/views/admin/ \
        app/Http/Controllers/Admin/AgentTemplateController.php
git commit -m "feat: add admin agent templates CRUD views"
```

---

## Task 8: Company agent templates CRUD views

**Files:**
- Create: `resources/views/companies/agent-templates/index.blade.php`
- Create: `resources/views/companies/agent-templates/create.blade.php`
- Create: `resources/views/companies/agent-templates/edit.blade.php`
- Create: `resources/views/companies/agent-templates/_form.blade.php`

- [ ] **Step 1: Write failing company template tests**

Create `tests/Feature/CompanyAgentTemplateTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AgentTemplate;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyAgentTemplateTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create([
            'name' => 'Test Co', 'description' => '', 'industry' => 'IT', 'language' => 'bg',
        ]);
    }

    public function test_index_lists_company_templates(): void
    {
        AgentTemplate::create([
            'company_id' => $this->company->id,
            'name' => 'Мой Шаблон', 'description' => 'x', 'icon' => '💬', 'type' => 'content_bg',
        ]);

        $this->get(route('companies.agent-templates.index', $this->company))
            ->assertOk()
            ->assertSee('Мой Шаблон');
    }

    public function test_index_does_not_show_other_company_templates(): void
    {
        $other = Company::create(['name' => 'Other', 'description' => '', 'industry' => 'x', 'language' => 'en']);
        AgentTemplate::create([
            'company_id' => $other->id,
            'name' => 'Чужд Шаблон', 'description' => 'x', 'icon' => '🔍', 'type' => 'analyzer',
        ]);

        $this->get(route('companies.agent-templates.index', $this->company))
            ->assertOk()
            ->assertDontSee('Чужд Шаблон');
    }

    public function test_store_creates_company_template(): void
    {
        $this->post(route('companies.agent-templates.store', $this->company), [
            'name' => 'Нов Шаблон', 'description' => 'Описание', 'icon' => '🆕',
            'type' => 'summarizer', 'sort_order' => 1,
        ])->assertRedirect(route('companies.agent-templates.index', $this->company));

        $this->assertDatabaseHas('agent_templates', [
            'name' => 'Нов Шаблон',
            'company_id' => $this->company->id,
        ]);
    }

    public function test_cannot_edit_other_company_template(): void
    {
        $other = Company::create(['name' => 'Other', 'description' => '', 'industry' => 'x', 'language' => 'en']);
        $template = AgentTemplate::create([
            'company_id' => $other->id,
            'name' => 'Чужд', 'description' => 'x', 'icon' => '🔍', 'type' => 'analyzer',
        ]);

        $this->get(route('companies.agent-templates.edit', [$this->company, $template]))
            ->assertForbidden();
    }

    public function test_destroy_deletes_own_template(): void
    {
        $template = AgentTemplate::create([
            'company_id' => $this->company->id,
            'name' => 'За Изтриване', 'description' => 'x', 'icon' => '🗑', 'type' => 'decision',
        ]);

        $this->delete(route('companies.agent-templates.destroy', [$this->company, $template]))
            ->assertRedirect(route('companies.agent-templates.index', $this->company));

        $this->assertDatabaseMissing('agent_templates', ['id' => $template->id]);
    }
}
```

- [ ] **Step 2: Run — expect failures (views missing)**

```bash
cd /Users/lub/Sites/localhost/ai-agent-claude && php artisan test tests/Feature/CompanyAgentTemplateTest.php
```

Expected: store/destroy pass, index/edit fail with view not found.

- [ ] **Step 3: Create company templates index view**

```blade
{{-- resources/views/companies/agent-templates/index.blade.php --}}
@extends('layouts.app')

@section('title', 'Агенти — ' . $company->name)

@section('content')
<div class="mb-2">
    <a href="{{ route('companies.show', $company) }}" class="text-indigo-600 hover:underline text-sm">← {{ $company->name }}</a>
</div>
<div class="flex items-start justify-between mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">🤖 Агенти на компанията</h1>
        <p class="text-sm text-gray-500 mt-1">Шаблони достъпни само за {{ $company->name }}</p>
    </div>
    <a href="{{ route('companies.agent-templates.create', $company) }}"
       class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-semibold transition">
        ＋ Нов агент шаблон
    </a>
</div>

@if(session('success'))
    <div class="mb-4 px-4 py-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm">✓ {{ session('success') }}</div>
@endif

@if($templates->isEmpty())
    <div class="bg-white border border-dashed border-gray-300 rounded-xl p-12 text-center text-gray-400">
        <p class="text-3xl mb-3">🤖</p>
        <p class="text-sm">Няма агент шаблони за тази компания.</p>
        <a href="{{ route('companies.agent-templates.create', $company) }}" class="text-indigo-600 underline text-sm mt-2 inline-block">Добави първия →</a>
    </div>
@else
    <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
        @foreach($templates as $template)
        <div class="flex items-center gap-4 px-5 py-4 border-b border-gray-100 last:border-0 hover:bg-gray-50">
            <span class="text-2xl w-8 text-center">{{ $template->icon }}</span>
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2">
                    <span class="font-semibold text-gray-900 text-sm">{{ $template->name }}</span>
                    <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded font-mono">{{ $template->type }}</span>
                </div>
                <p class="text-xs text-gray-500 truncate">{{ $template->description }}</p>
            </div>
            <div class="flex gap-2 shrink-0">
                <a href="{{ route('companies.agent-templates.edit', [$company, $template]) }}"
                   class="border border-gray-200 bg-white text-gray-600 hover:bg-gray-50 px-3 py-1.5 rounded-lg text-xs">
                    ✏ Редактирай
                </a>
                <form action="{{ route('companies.agent-templates.destroy', [$company, $template]) }}" method="POST"
                      onsubmit="return confirm('Изтрий шаблон {{ $template->name }}?')">
                    @csrf @method('DELETE')
                    <button class="border border-red-200 text-red-500 hover:bg-red-50 px-3 py-1.5 rounded-lg text-xs">✕ Изтрий</button>
                </form>
            </div>
        </div>
        @endforeach
    </div>
@endif
@endsection
```

- [ ] **Step 4: Create company template form partial**

```blade
{{-- resources/views/companies/agent-templates/_form.blade.php --}}
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
            <label class="block text-xs font-semibold text-gray-600 mb-1">Кратко описание</label>
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
            <label class="block text-xs font-semibold text-gray-600 mb-1">Подредба</label>
            <input type="number" name="sort_order" value="{{ old('sort_order', $agentTemplate->sort_order ?? 0) }}" min="0"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Модел по подразбиране</label>
            <select name="model" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <option value="">— авто —</option>
                @foreach($models as $m)
                    <option value="{{ $m->ollama_tag }}" {{ old('model', $agentTemplate->model ?? '') === $m->ollama_tag ? 'selected' : '' }}>
                        {{ $m->display_name }}
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
```

- [ ] **Step 5: Create company template `create.blade.php`**

```blade
{{-- resources/views/companies/agent-templates/create.blade.php --}}
@extends('layouts.app')

@section('title', 'Нов агент шаблон — ' . $company->name)

@section('content')
<div class="mb-2">
    <a href="{{ route('companies.agent-templates.index', $company) }}" class="text-indigo-600 hover:underline text-sm">← Агенти</a>
</div>
<h1 class="text-2xl font-bold text-gray-900 mb-6">Нов агент шаблон</h1>
<form action="{{ route('companies.agent-templates.store', $company) }}" method="POST">
    @csrf
    @php $cancelUrl = route('companies.agent-templates.index', $company); $agentTemplate = null; @endphp
    @include('companies.agent-templates._form')
</form>
@endsection
```

- [ ] **Step 6: Create company template `edit.blade.php`**

```blade
{{-- resources/views/companies/agent-templates/edit.blade.php --}}
@extends('layouts.app')

@section('title', 'Редактирай: ' . $agentTemplate->name)

@section('content')
<div class="mb-2">
    <a href="{{ route('companies.agent-templates.index', $company) }}" class="text-indigo-600 hover:underline text-sm">← Агенти</a>
</div>
<h1 class="text-2xl font-bold text-gray-900 mb-6">✏ {{ $agentTemplate->name }}</h1>
<form action="{{ route('companies.agent-templates.update', [$company, $agentTemplate]) }}" method="POST">
    @csrf @method('PUT')
    @php $cancelUrl = route('companies.agent-templates.index', $company); @endphp
    @include('companies.agent-templates._form')
</form>
@endsection
```

- [ ] **Step 7: Run all company tests — expect pass**

```bash
cd /Users/lub/Sites/localhost/ai-agent-claude && php artisan test tests/Feature/CompanyAgentTemplateTest.php
```

Expected: 5 tests pass.

- [ ] **Step 8: Commit**

```bash
git add resources/views/companies/agent-templates/
git commit -m "feat: add company agent templates CRUD views"
```

---

## Task 9: Navigation links

**Files:**
- Modify: `resources/views/companies/show.blade.php`
- Modify: `resources/views/layouts/app.blade.php`

- [ ] **Step 1: Add "Агенти" link to company show page**

In `resources/views/companies/show.blade.php`, find the header action buttons block (lines ~17-29) and add the Агенти link:

```blade
{{-- Add after the "Редактирай" link --}}
<a href="{{ route('companies.agent-templates.index', $company) }}"
   class="bg-white border border-gray-300 hover:border-gray-400 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium transition">
    🤖 Агенти
</a>
```

- [ ] **Step 2: Add Admin link to main nav**

In `resources/views/layouts/app.blade.php`, find the `$navItems` array (line ~26) and add Admin:

```php
$navItems = [
    'Фирми'      => ['route' => 'companies.index', 'match' => 'companies.*'],
    'LLM Модели' => ['route' => 'models.index',    'match' => 'models.*'],
    'Admin'      => ['route' => 'admin.login',      'match' => 'admin.*'],
];
```

- [ ] **Step 3: Verify visually**

```bash
cd /Users/lub/Sites/localhost/ai-agent-claude && php artisan serve
```

Open http://flowai.local/companies/1 — confirm "🤖 Агенти" button visible.
Open http://flowai.local/admin — confirm redirect to login page.

- [ ] **Step 4: Commit**

```bash
git add resources/views/companies/show.blade.php resources/views/layouts/app.blade.php
git commit -m "feat: add Агенти nav link on company page and Admin in main nav"
```

---

## Task 10: Picker modal in `create.blade.php`

This is the main feature — replaces the current `addAgent()` call with a popup modal.

**Files:**
- Modify: `resources/views/flows/create.blade.php`

- [ ] **Step 1: Replace the "Добави агент" button**

In `resources/views/flows/create.blade.php`, find lines 355-360:

```blade
            {{-- Add agent --}}
            <div class="px-6 py-3 border-t border-dashed border-gray-200 flex justify-center">
                <button type="button" @click="addAgent"
                        class="text-indigo-600 hover:text-indigo-800 text-sm font-semibold flex items-center gap-1.5 px-4 py-2 rounded-lg hover:bg-indigo-50 transition">
                    ＋ Добави агент
                </button>
            </div>
```

Replace with:

```blade
            {{-- Add agent --}}
            <div class="px-6 py-3 border-t border-dashed border-gray-200 flex justify-center">
                <button type="button" @click="openAgentPicker"
                        class="text-indigo-600 hover:text-indigo-800 text-sm font-semibold flex items-center gap-1.5 px-4 py-2 rounded-lg hover:bg-indigo-50 transition">
                    ＋ Добави агент
                </button>
            </div>

            {{-- Agent Picker Modal --}}
            <div x-show="showPicker" x-cloak
                 class="fixed inset-0 z-50 flex items-center justify-center p-4"
                 @keydown.escape.window="showPicker = false">
                <div class="absolute inset-0 bg-black/40" @click="showPicker = false"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-[820px] overflow-hidden"
                     @click.stop>
                    {{-- Header --}}
                    <div class="px-6 pt-5 pb-0 flex items-center justify-between">
                        <h3 class="text-lg font-bold text-gray-900">Добави агент</h3>
                        <button @click="showPicker = false" class="text-gray-400 hover:text-gray-600 text-xl leading-none">✕</button>
                    </div>

                    {{-- Tabs --}}
                    <div class="flex px-6 pt-3 pb-0 border-b border-gray-200 gap-1">
                        <template x-for="tab in pickerTabs" :key="tab.id">
                            <button type="button"
                                    @click="activePickerTab = tab.id"
                                    :class="activePickerTab === tab.id
                                        ? 'border-indigo-600 text-indigo-700 font-semibold bg-indigo-50'
                                        : 'border-transparent text-gray-500 hover:text-gray-700'"
                                    class="px-4 py-2 text-sm border-b-2 -mb-px rounded-t-lg transition whitespace-nowrap"
                                    x-text="tab.label">
                            </button>
                        </template>
                    </div>

                    {{-- Body --}}
                    <div class="p-6 max-h-[480px] overflow-y-auto">
                        {{-- Search --}}
                        <div class="mb-4">
                            <input type="text" x-model="pickerSearch"
                                   placeholder="🔍 Търси по ime или тип..."
                                   class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>

                        {{-- Loading --}}
                        <div x-show="pickerLoading" class="text-center py-8 text-gray-400 text-sm">
                            <span class="inline-block w-5 h-5 border-2 border-indigo-400 border-t-transparent rounded-full animate-spin mr-2"></span>
                            Зарежда шаблони...
                        </div>

                        {{-- "Всички" tab --}}
                        <div x-show="!pickerLoading && activePickerTab === 'all'">
                            {{-- Blank agent --}}
                            <div class="mb-4">
                                <div @click="selectTemplate(null)"
                                     class="flex items-center gap-4 p-4 border-2 border-dashed border-gray-300 rounded-xl cursor-pointer hover:border-indigo-500 hover:bg-indigo-50 transition">
                                    <span class="text-3xl">➕</span>
                                    <div class="flex-1">
                                        <div class="font-semibold text-sm text-gray-900">Нов празен агент</div>
                                        <div class="text-xs text-gray-500">Започни от нулата — всички полета са празни</div>
                                    </div>
                                    <span class="text-indigo-600 text-sm font-semibold">Избери →</span>
                                </div>
                            </div>

                            {{-- Company templates section --}}
                            <template x-if="filteredCompanyTemplates.length > 0">
                                <div class="mb-4">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-2">🏢 Моите агенти</p>
                                    <div class="grid grid-cols-4 gap-2">
                                        <template x-for="tpl in filteredCompanyTemplates" :key="tpl.id">
                                            <div @click="selectTemplate(tpl)"
                                                 class="border border-gray-200 rounded-xl p-3 cursor-pointer hover:border-indigo-500 hover:bg-indigo-50 transition">
                                                <span class="block text-2xl mb-1" x-text="tpl.icon"></span>
                                                <div class="text-xs font-semibold text-gray-900 mb-1 leading-tight" x-text="tpl.name"></div>
                                                <div class="text-[11px] text-gray-500 leading-tight mb-1.5" x-text="(tpl.description||'').substring(0,60)"></div>
                                                <span class="inline-block text-[10px] font-mono px-1.5 py-0.5 rounded bg-green-100 text-green-700" x-text="tpl.type"></span>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>

                            {{-- System templates section --}}
                            <template x-if="filteredSystemTemplates.length > 0">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-2">⚙ Системни агенти</p>
                                    <div class="grid grid-cols-4 gap-2">
                                        <template x-for="tpl in filteredSystemTemplates" :key="tpl.id">
                                            <div @click="selectTemplate(tpl)"
                                                 class="border border-gray-200 rounded-xl p-3 cursor-pointer hover:border-indigo-500 hover:bg-indigo-50 transition">
                                                <span class="block text-2xl mb-1" x-text="tpl.icon"></span>
                                                <div class="text-xs font-semibold text-gray-900 mb-1 leading-tight" x-text="tpl.name"></div>
                                                <div class="text-[11px] text-gray-500 leading-tight mb-1.5" x-text="(tpl.description||'').substring(0,60)"></div>
                                                <span class="inline-block text-[10px] font-mono px-1.5 py-0.5 rounded bg-violet-100 text-violet-700" x-text="tpl.type"></span>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>

                            <div x-show="filteredCompanyTemplates.length === 0 && filteredSystemTemplates.length === 0 && pickerSearch"
                                 class="text-center py-8 text-gray-400 text-sm">
                                Няма резултати за "<span x-text="pickerSearch"></span>"
                            </div>
                        </div>

                        {{-- "Моите агенти" tab --}}
                        <div x-show="!pickerLoading && activePickerTab === 'mine'">
                            <div x-show="filteredCompanyTemplates.length === 0" class="text-center py-8 text-gray-400 text-sm">
                                <p class="text-3xl mb-2">🏢</p>
                                Нямате запазени агент шаблони.
                                <a :href="'{{ route('companies.agent-templates.index', $company) }}'"
                                   class="text-indigo-600 underline block mt-1">Управлявай агентите на компанията →</a>
                            </div>
                            <div x-show="filteredCompanyTemplates.length > 0" class="grid grid-cols-4 gap-2">
                                <template x-for="tpl in filteredCompanyTemplates" :key="tpl.id">
                                    <div @click="selectTemplate(tpl)"
                                         class="border border-gray-200 rounded-xl p-3 cursor-pointer hover:border-indigo-500 hover:bg-indigo-50 transition">
                                        <span class="block text-2xl mb-1" x-text="tpl.icon"></span>
                                        <div class="text-xs font-semibold text-gray-900 mb-1 leading-tight" x-text="tpl.name"></div>
                                        <div class="text-[11px] text-gray-500 leading-tight mb-1.5" x-text="(tpl.description||'').substring(0,60)"></div>
                                        <span class="inline-block text-[10px] font-mono px-1.5 py-0.5 rounded bg-green-100 text-green-700" x-text="tpl.type"></span>
                                    </div>
                                </template>
                            </div>
                        </div>

                        {{-- "Системни агенти" tab --}}
                        <div x-show="!pickerLoading && activePickerTab === 'system'">
                            <div x-show="filteredSystemTemplates.length === 0" class="text-center py-8 text-gray-400 text-sm">
                                Няма системни агент шаблони.
                            </div>
                            <div x-show="filteredSystemTemplates.length > 0" class="grid grid-cols-4 gap-2">
                                <template x-for="tpl in filteredSystemTemplates" :key="tpl.id">
                                    <div @click="selectTemplate(tpl)"
                                         class="border border-gray-200 rounded-xl p-3 cursor-pointer hover:border-indigo-500 hover:bg-indigo-50 transition">
                                        <span class="block text-2xl mb-1" x-text="tpl.icon"></span>
                                        <div class="text-xs font-semibold text-gray-900 mb-1 leading-tight" x-text="tpl.name"></div>
                                        <div class="text-[11px] text-gray-500 leading-tight mb-1.5" x-text="(tpl.description||'').substring(0,60)"></div>
                                        <span class="inline-block text-[10px] font-mono px-1.5 py-0.5 rounded bg-violet-100 text-violet-700" x-text="tpl.type"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
```

- [ ] **Step 2: Add picker state and methods to the Alpine `flowCreator()` function**

In `resources/views/flows/create.blade.php`, inside the `flowCreator()` return object, add the following properties after `_sortable: null,`:

```js
        // ── Agent Picker ─────────────────────────────────────────
        showPicker: false,
        activePickerTab: 'all',
        pickerSearch: '',
        pickerLoading: false,
        pickerTemplates: { system: [], company: [] },
        pickerTabs: [
            { id: 'all',    label: 'Всички' },
            { id: 'mine',   label: '🏢 Моите агенти' },
            { id: 'system', label: '⚙ Системни агенти' },
        ],
```

Add these computed properties after `get cronValue()`:

```js
        get filteredSystemTemplates() {
            const q = this.pickerSearch.toLowerCase();
            return this.pickerTemplates.system.filter(t =>
                !q || t.name.toLowerCase().includes(q) || t.type.toLowerCase().includes(q)
            );
        },

        get filteredCompanyTemplates() {
            const q = this.pickerSearch.toLowerCase();
            return this.pickerTemplates.company.filter(t =>
                !q || t.name.toLowerCase().includes(q) || t.type.toLowerCase().includes(q)
            );
        },
```

Add these methods after the `addAgent()` method:

```js
        async openAgentPicker() {
            this.showPicker = true;
            this.activePickerTab = 'all';
            this.pickerSearch = '';

            if (this.pickerTemplates.system.length > 0 || this.pickerTemplates.company.length > 0) {
                return; // already loaded
            }

            this.pickerLoading = true;
            try {
                const resp = await fetch(`{{ route('agent-templates.picker') }}?company_id={{ $company->id }}`, {
                    headers: { 'Accept': 'application/json' },
                });
                this.pickerTemplates = await resp.json();
            } catch (e) {
                console.error('Failed to load templates', e);
            } finally {
                this.pickerLoading = false;
            }
        },

        selectTemplate(tpl) {
            const defaults = {
                _uid: (typeof crypto !== 'undefined' && crypto.randomUUID) ? crypto.randomUUID() : Date.now() + '-' + Math.random(),
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

            if (tpl) {
                Object.assign(defaults, {
                    name:               tpl.name              || defaults.name,
                    type:               tpl.type              || defaults.type,
                    role:               tpl.role              || '',
                    system_prompt:      tpl.system_prompt     || '',
                    prompt_template:    tpl.prompt_template   || '',
                    model:              this._resolveModel(tpl.model),
                    is_verifier:        !!tpl.is_verifier,
                    qa_threshold:       tpl.qa_threshold      || null,
                    capabilities:       tpl.capabilities      || [],
                    strengths:          tpl.strengths         || '',
                    limitations:        tpl.limitations       || '',
                    input_description:  tpl.input_description || '',
                    output_description: tpl.output_description|| '',
                    config:             tpl.config            || defaults.config,
                });
            }

            this.agents.push(defaults);
            this.renumberAgents();
            this.editingIndex = this.agents.length - 1;
            this.showPicker = false;
            this.$nextTick(() => this.initSortable());
        },

        _resolveModel(suggestedModel) {
            if (suggestedModel && ALL_MODEL_TAGS.includes(suggestedModel)) return suggestedModel;
            return AVAILABLE_MODELS[0] || ALL_MODEL_TAGS[0] || '';
        },
```

- [ ] **Step 3: Run full test suite**

```bash
cd /Users/lub/Sites/localhost/ai-agent-claude && php artisan test
```

Expected: All tests pass (no regressions).

- [ ] **Step 4: Smoke test in the browser**

```bash
php artisan serve
```

1. Go to http://flowai.local/companies/1/flows/create
2. Fill in a name and description, generate agents
3. Click "＋ Добави агент" — picker modal should open
4. Click "⚙ Системни агенти" tab — should show 8 templates
5. Click "Email Изпращач" — modal closes, new agent added with pre-filled fields
6. Click "Нов празен агент" — modal closes, blank agent opens for editing

- [ ] **Step 5: Commit**

```bash
git add resources/views/flows/create.blade.php
git commit -m "feat: replace addAgent with template picker modal in flow create"
```

---

## Task 11: Final — run all tests and seed

- [ ] **Step 1: Run all tests**

```bash
cd /Users/lub/Sites/localhost/ai-agent-claude && php artisan test
```

Expected: All tests pass.

- [ ] **Step 2: Ensure seeder runs fresh**

```bash
php artisan migrate:fresh --seed
```

Expected: All migrations run, 8 system templates seeded.

- [ ] **Step 3: Verify template count**

```bash
php artisan tinker --execute="echo App\Models\AgentTemplate::whereNull('company_id')->count() . ' system templates';"
```

Expected: `8 system templates`

- [ ] **Step 4: Final commit**

```bash
git add -A
git commit -m "feat: agent template picker — complete implementation"
```
