# Eval Suite + Авто-оптимизатор Цена/Качество

> Имплементационен план — готов за изпълнение.
>
> Позволява да задаваш **golden test cases** за всеки Flow, да пускаш автоматизирани
> оценки срещу различни model levels и Flow версии, и да получаваш обективна крива
> **цена ↔ качество** вместо да сменяш нива на сляпо.

---

## 1. Цел и контекст

Вече имаш:
- **Model levels** (low → god) с preview на цена при relevel.
- **Flow versions** — множество версии на един пайплайн.
- **`node_runs.qa_score`** — вграден QA gate след всеки агент.
- **`ModelRouterService`** — учи се от `qa_score` историята.
- **Plan A/B** — сравнение на planner провайдъри.

Липсва: обективна мярка за **качеството** на финалния изход и автоматизирано
сравнение level × version. Без него смяната low↔god е субективна; активирането
на нова версия е без регресионен тест.

---

## 2. Схема на базата данни

### 2.1 `flow_eval_cases`

```sql
CREATE TABLE flow_eval_cases (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    flow_id         INTEGER NOT NULL REFERENCES flows(id) ON DELETE CASCADE,
    name            VARCHAR(255) NOT NULL,
    description     TEXT,
    input_data      JSON NOT NULL,      -- { "prompt": "...", "variables": {...} }
    criteria        JSON NOT NULL,      -- списък от критерии (виж §3.2)
    weight          REAL DEFAULT 1.0,   -- тежест при агрегиране
    is_active       BOOLEAN DEFAULT 1,
    created_at      DATETIME,
    updated_at      DATETIME
);
```

### 2.2 `flow_eval_runs`

```sql
CREATE TABLE flow_eval_runs (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    flow_id         INTEGER NOT NULL REFERENCES flows(id) ON DELETE CASCADE,
    flow_version_id INTEGER REFERENCES flow_versions(id) ON DELETE SET NULL,
    flow_run_id     INTEGER REFERENCES flow_runs(id) ON DELETE SET NULL,
    eval_case_id    INTEGER NOT NULL REFERENCES flow_eval_cases(id) ON DELETE CASCADE,
    model_level     VARCHAR(20),        -- low|medium|high|ultra|god
    status          VARCHAR(20) DEFAULT 'pending',  -- pending|running|completed|failed
    score           REAL,               -- 0–100, среднопретеглен от criteria
    scores_detail   JSON,               -- { "criterion_key": { score, reason } }
    cost_usd        REAL DEFAULT 0,
    duration_ms     INTEGER,
    final_output    TEXT,               -- изход на Flow-а за тази eval
    judge_log       JSON,               -- пълен LLM-as-judge отговор
    error           TEXT,
    created_at      DATETIME,
    updated_at      DATETIME
);
CREATE INDEX idx_eval_runs_flow ON flow_eval_runs(flow_id, model_level, status);
```

### 2.3 Migration файлове

```
2026_XX_XX_100000_create_flow_eval_cases_table.php
2026_XX_XX_100001_create_flow_eval_runs_table.php
```

---

## 3. Модели на данните

### 3.1 `FlowEvalCase` (app/Models/FlowEvalCase.php)

```php
class FlowEvalCase extends Model
{
    protected $fillable = ['flow_id','name','description','input_data','criteria','weight','is_active'];
    protected $casts = ['input_data' => 'array', 'criteria' => 'array', 'weight' => 'float', 'is_active' => 'boolean'];
    public function flow(): BelongsTo { return $this->belongsTo(Flow::class); }
    public function evalRuns(): HasMany { return $this->hasMany(FlowEvalRun::class, 'eval_case_id'); }
}
```

### 3.2 Структура на `criteria` (JSON)

```json
[
  {
    "key": "accuracy",
    "label": "Точност на информацията",
    "description": "Всички цитирани факти и цени са верни спрямо ценоразписа.",
    "weight": 2.0,
    "type": "llm_judge"
  },
  {
    "key": "completeness",
    "label": "Пълнота",
    "description": "Изходът покрива всички изискани точки от входа.",
    "weight": 1.5,
    "type": "llm_judge"
  },
  {
    "key": "tone",
    "label": "Тон и стил",
    "description": "Текстът е на официален български, без граматически грешки.",
    "weight": 1.0,
    "type": "llm_judge"
  },
  {
    "key": "length",
    "label": "Дължина",
    "description": "Между 300 и 600 думи.",
    "weight": 0.5,
    "type": "rule",
    "rule": "word_count",
    "min": 300,
    "max": 600
  }
]
```

**Типове критерии:**
- `llm_judge` — LLM-as-judge дава score 0–100 + мотивация.
- `rule` — детерминистична проверка (`word_count`, `contains_keyword`, `valid_json`, `no_placeholder`).
- `regex` — регулярен израз срещу изхода.

### 3.3 `FlowEvalRun` (app/Models/FlowEvalRun.php)

```php
class FlowEvalRun extends Model
{
    protected $fillable = ['flow_id','flow_version_id','flow_run_id','eval_case_id','model_level',
                           'status','score','scores_detail','cost_usd','duration_ms','final_output','judge_log','error'];
    protected $casts = ['scores_detail' => 'array', 'judge_log' => 'array', 'cost_usd' => 'float'];
    public function evalCase(): BelongsTo { return $this->belongsTo(FlowEvalCase::class, 'eval_case_id'); }
    public function flowRun(): BelongsTo { return $this->belongsTo(FlowRun::class); }
    public function flowVersion(): BelongsTo { return $this->belongsTo(FlowVersion::class); }
}
```

---

## 4. Сервиз: `EvalRunnerService`

**Файл:** `app/Services/EvalRunnerService.php`

```
app/Services/EvalRunnerService.php
```

### 4.1 Интерфейс

```php
class EvalRunnerService
{
    public function __construct(
        private GraphFlowExecutor $executor,
        private GeneratorService  $generator,
    ) {}

    /**
     * Изпълнява един eval case за дадена версия + ниво.
     * Създава FlowRun с level override → изчаква завършване → judge → score.
     */
    public function runCase(
        FlowEvalCase $case,
        FlowVersion  $version,
        ModelLevel   $level,
    ): FlowEvalRun;

    /**
     * Матрица: за всяка комбинация version × level пуска всички active cases.
     * Връща collection от FlowEvalRun-и.
     *
     * @param  list<FlowVersion>  $versions
     * @param  list<ModelLevel>   $levels
     */
    public function runMatrix(
        Flow         $flow,
        array        $versions,
        array        $levels,
        ?callable    $onProgress = null,
    ): Collection;

    /**
     * LLM-as-judge: оценява финалния изход спрямо case input + criteria.
     * Използва GeneratorService::chatJson() — същия механизъм като critique фазата.
     */
    public function judge(FlowEvalCase $case, string $output): array; // { criterion_key => {score, reason} }

    /**
     * Агрегира score-овете от judge() в едно число 0–100.
     */
    public function aggregate(FlowEvalCase $case, array $detail): float;
}
```

### 4.2 Логика на `runCase()`

```
1. Взима graph_layout от $version.
2. Създава FlowRun с:
   - triggered_by = 'eval'
   - model_level  = $level->value
   - context['eval_case_id'] = $case->id
   - context['eval_input']   = $case->input_data
3. Изпълнява GraphFlowExecutor::execute($flowRun) СИНХРОННО
   (нов метод executeSync() — DispatchesJobs + polling loop в proc; или
    директен chain в eval job без queue batch за по-лесна отладка).
4. След завършване извлича $flowRun->final_output.
5. Оценява rule-based criteria детерминистично.
6. Вика $this->judge($case, $output) за llm_judge criteria.
7. Изчислява aggregate score.
8. Записва FlowEvalRun.
```

### 4.3 Prompt на LLM-as-judge

```
Ти си безпристрастен оценител на AI изходи. Получаваш:
- Вход: {input}
- Изход за оценка: {output}
- Критерий: {criterion.label} — {criterion.description}

Оцени изхода по критерия от 0 до 100.
- 90–100: изключително добре изпълнен критерий
- 70–89:  добре изпълнен с малки пропуски
- 50–69:  частично изпълнен
- 0–49:   не е изпълнен или е грубо нарушен

Върни JSON: { "score": <число 0-100>, "reason": "<кратко обяснение>" }
```

Използва **structured outputs** (същия `chatJson()` от `GeneratorService`).
Provider за judge се конфигурира с `EVAL_JUDGE_PROVIDER` (default: `gemini` —
евтин и бърз; може `openai`/`anthropic` за по-строг judge).

---

## 5. Job: `RunFlowEvalJob`

**Файл:** `app/Jobs/RunFlowEvalJob.php`

```php
class RunFlowEvalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int    $timeout = 600;
    public string $queue   = 'default';

    public function __construct(
        public readonly int    $evalCaseId,
        public readonly int    $versionId,
        public readonly string $level,      // ModelLevel value
        public readonly string $token,      // cache token за polling
    ) {}

    public function handle(EvalRunnerService $runner): void
    {
        $case    = FlowEvalCase::findOrFail($this->evalCaseId);
        $version = FlowVersion::findOrFail($this->versionId);
        $level   = ModelLevel::from($this->level);

        $evalRun = $runner->runCase($case, $version, $level);

        Cache::put($this->token, [
            'status' => 'done',
            'eval_run_id' => $evalRun->id,
            'score' => $evalRun->score,
        ], now()->addHour());
    }

    public function failed(\Throwable $e): void
    {
        Cache::put($this->token, ['status' => 'failed', 'error' => $e->getMessage()], now()->addHour());
    }
}
```

---

## 6. Controller: `FlowEvalController`

**Файл:** `app/Http/Controllers/FlowEvalController.php`
**Routes** (в `routes/web.php`, в auth блока):

```php
// Eval cases CRUD
Route::get   ('flows/{flow}/eval',                  [FlowEvalController::class, 'index']  )->name('flows.eval.index');
Route::get   ('flows/{flow}/eval/create',           [FlowEvalController::class, 'create'] )->name('flows.eval.create');
Route::post  ('flows/{flow}/eval',                  [FlowEvalController::class, 'store']  )->name('flows.eval.store');
Route::get   ('flows/{flow}/eval/{case}/edit',      [FlowEvalController::class, 'edit']   )->name('flows.eval.edit');
Route::put   ('flows/{flow}/eval/{case}',           [FlowEvalController::class, 'update'] )->name('flows.eval.update');
Route::delete('flows/{flow}/eval/{case}',           [FlowEvalController::class, 'destroy'])->name('flows.eval.destroy');

// Стартиране на eval runs
Route::post  ('flows/{flow}/eval/run',              [FlowEvalController::class, 'run']    )->name('flows.eval.run');
Route::get   ('eval-run-status/{token}',            [FlowEvalController::class, 'status'] )->name('flows.eval.status');

// Резултати — матрица и детайл
Route::get   ('flows/{flow}/eval/results',          [FlowEvalController::class, 'results'])->name('flows.eval.results');
Route::get   ('flows/{flow}/eval/runs/{evalRun}',   [FlowEvalController::class, 'runDetail'])->name('flows.eval.run-detail');
```

### 6.1 Метод `run()` — стартиране на matrix eval

```php
public function run(Request $request, Flow $flow): JsonResponse
{
    $request->validate([
        'version_ids' => 'required|array|min:1',
        'version_ids.*' => 'integer',
        'levels' => 'required|array|min:1',
        'levels.*' => Rule::enum(ModelLevel::class),
        'case_ids' => 'nullable|array',  // null = всички активни
    ]);

    $token = Str::uuid()->toString();
    $jobs  = [];

    foreach ($request->input('version_ids') as $versionId) {
        foreach ($request->input('levels') as $level) {
            $caseIds = $request->input('case_ids') 
                ?? $flow->evalCases()->where('is_active', true)->pluck('id')->all();
            foreach ($caseIds as $caseId) {
                $jobs[] = new RunFlowEvalJob($caseId, $versionId, $level, $token.'_'.$caseId.'_'.$versionId.'_'.$level);
            }
        }
    }

    Bus::batch($jobs)->name("eval-{$flow->id}")->dispatch();

    Cache::put($token, ['status' => 'running', 'total' => count($jobs), 'done' => 0], now()->addHours(2));

    return response()->json(['token' => $token, 'total' => count($jobs)]);
}
```

---

## 7. UI — Страница „Eval"

### 7.1 Навигация

Добавяш таб **„Eval"** в навигацията на Flow страницата (до „Памет", „A/B").

### 7.2 Страница с eval cases (`flows/{flow}/eval`)

```
┌─────────────────────────────────────────────────────────────┐
│  Eval Cases за "Имейл кампания"           [+ Нов тест]      │
├─────────────────────────────────────────────────────────────┤
│  ☑ Стандартен имейл (тежест 1.0)          3 критерия  [✏️]  │
│  ☑ Промоция за лято (тежест 1.5)          4 критерия  [✏️]  │
│  ☐ Edge case: празен вход (тежест 0.5)    2 критерия  [✏️]  │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│  Стартирай Eval                                             │
│  Версии: ☑ v3 (активна)  ☑ v2  ☐ v1                       │
│  Нива:   ☑ low  ☑ medium  ☑ high  ☐ ultra  ☐ god           │
│  Cases:  ○ Всички активни  ● Избери ____________            │
│                                     [▶ Стартирай]          │
└─────────────────────────────────────────────────────────────┘
```

### 7.3 Страница с резултати (`flows/{flow}/eval/results`)

**Матрица версия × ниво:**

```
              │ low    │ medium │ high   │ ultra  │ god
──────────────┼────────┼────────┼────────┼────────┼────────
v3 (активна)  │ 71 / 0.003$ │ 84 / 0.018$ │ 91 / 0.047$ │ — │ 95 / 0.21$
v2            │ 68 / 0.003$ │ 79 / 0.017$ │ 87 / 0.046$ │ — │  —
```

Цветово кодиране: зелено ≥85, жълто 65–84, червено <65.

**График цена vs качество** (Chart.js scatter):
- X ос: средна цена на run (USD)
- Y ос: среден eval score (0–100)
- Всяка точка = версия × ниво
- Идеалната точка е горе-вляво

**Препоръка на оптимизатора** (автоматична):

```
💡 Препоръка: Версия v3 на ниво MEDIUM
   Score: 84/100 | Цена: $0.018 | Съотношение: 4666 точки/$
   vs GOD: −11 точки, −92% цена
```

### 7.4 Детайлна страница за един eval run

```
Case: "Стандартен имейл"  |  Версия: v3  |  Ниво: medium  |  Score: 84/100

Критерии:
  ✅ Точност на информацията    92/100  (тежест 2.0)
     "Всички цени са правилно цитирани от ценоразписа."
  ✅ Пълнота                    88/100  (тежест 1.5)
     "Покрити са всички изискани промоции, липсва само CTA."
  ⚠️ Тон и стил                 76/100  (тежест 1.0)
     "Един абзац е на разговорен вместо официален тон."
  ✅ Дължина                   100/100  (тежест 0.5)  [rule: 342 думи ✓]

Финален изход:
  [Текстът на агента...]

Node runs:
  researcher    score: 88  cost: $0.004  model: gemini-2.0-flash
  writer        score: 81  cost: $0.009  model: gemini-2.0-flash
  qa_verifier   score: 79  cost: $0.005  model: gemini-2.0-flash
```

---

## 8. Интеграция с `ModelRouterService`

`ModelRouterService` вече чете `node_runs.qa_score` за routing решения.
След добавяне на Eval Suite — добавяш и `flow_eval_runs.score` като втори сигнал:

```php
// В ModelRouterService::buildHistory()
$evalScores = FlowEvalRun::where('flow_id', $flowId)
    ->where('status', 'completed')
    ->where('model_level', $level->value)
    ->orderByDesc('created_at')
    ->limit(5)
    ->avg('score');
```

По-висок eval score за ниво = по-голяма вероятност да го препоръча при re-routing.

---

## 9. Интеграция с Flow Versions Panel

В `resources/views/flows/builder.blade.php`, в панела с версии — бутон **„Eval"**
до всяка версия, който отваря `flows/{flow}/eval/results?version={id}`.

При **активиране на версия** (ако има ≥1 eval case) — модал:

```
⚠️ Версия v4 няма eval резултати.
Препоръчваме да пуснеш eval преди активиране.
[Пусни eval]  [Активирай без eval]
```

---

## 10. Console Command за nightly eval

**Файл:** `app/Console/Commands/RunScheduledEvalsCommand.php`
**Signature:** `flows:run-evals`

```php
// В app/Console/Kernel.php или routes/console.php:
Schedule::command('flows:run-evals')->dailyAt('03:00');
```

Пуска eval на всички активни версии с поне 1 active eval case,
само за нивото, на което версията е конфигурирана.
Резултатите се записват → `ModelRouterService` ги вижда следващия ден.

---

## 11. Ред на имплементация

| Стъпка | Файл / Действие | Приоритет |
|--------|-----------------|-----------|
| 1 | Migrations: `flow_eval_cases` + `flow_eval_runs` | Задължително |
| 2 | Models: `FlowEvalCase`, `FlowEvalRun` | Задължително |
| 3 | `EvalRunnerService::judge()` + `aggregate()` — само judge логиката | Задължително |
| 4 | `EvalRunnerService::runCase()` — пуска FlowRun, чака, judge-ва | Задължително |
| 5 | `RunFlowEvalJob` | Задължително |
| 6 | `FlowEvalController` + routes | Задължително |
| 7 | Blade views: index + create/edit form + results matrix | Задължително |
| 8 | Chart.js scatter plot в results | Препоръчително |
| 9 | Авто-препоръка (оптимизатор) в results view | Препоръчително |
| 10 | Интеграция с `ModelRouterService` | По-късно |
| 11 | `RunScheduledEvalsCommand` + Schedule | По-късно |
| 12 | Модал при активиране на версия без eval | По-късно |

**Env промени:**
```env
EVAL_JUDGE_PROVIDER=gemini       # или openai, anthropic
EVAL_JUDGE_MODEL=gemini-2.0-flash-lite
```

---

## 12. Какво НЕ правим

- **Не мокираме FlowRun** — истински run с истинско изпълнение на агентите.
  Само така eval мери реалното поведение на системата.
- **Не пазим eval runs в queue `flows`** — използваме `default` queue, за да
  не блокират продукционните runs.
- **Не показваме eval резултати в admin Costs страницата** — eval runs имат
  `triggered_by = 'eval'`; Costs страницата вече филтрира по `triggered_by`.
