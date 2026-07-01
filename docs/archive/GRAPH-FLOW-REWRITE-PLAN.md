# План: Преминаване към Graph-Based Flow (n8n-подобен редактор)

> Документ за имплементация на голяма промяна в FlowAI — от линейно (sequential)
> изпълнение на агенти към визуален **DAG** (directed acyclic graph) редактор с
> **паралелно** изпълнение и **fan-in** към агент „Автор на доклад".

**Решения, взети преди писане на плана:**

| Решение | Избор |
|---|---|
| Графична библиотека | **Drawflow** (MIT, vanilla JS — пасва на Blade + Alpine) |
| Модел на изпълнение | **Реално паралелно** чрез Laravel `Bus::batch` |
| Стратегия | **Чист rewrite** на flow engine-а (без обратна съвместимост) |

---

## 1. Проблемът сега

Текущата логика в `app/Services/FlowExecutorService.php` изпълнява агентите
**последователно** по колоната `agents.order`:

```php
$agents = $flow->agents()->where('is_active', true)->orderBy('order')->get();
foreach ($agents as $agent) { ... }
```

Контекстът е **плосък масив**, който се мутира на всяка стъпка:

```php
private function mergeAgentOutputIntoContext(array $context, Agent $agent, string $output): array
{
    $context[$agent->name] = $output;
    $context['input'] = $output;   // ← презаписва се на всяка стъпка
    $context['topic'] = $output;   // ← презаписва се на всяка стъпка
    return $context;
}
```

Оттук идват двата основни проблема:

1. **Загуба на информация.** `{{input}}` и `{{topic}}` винаги сочат към изхода
   на **последния** агент. Агентът „Автор на доклад" получава предимно последното
   нещо, а не пълния, паралелно събран контекст. Цялото съдържание се събира
   накрая чак във `FinalComposerService`, което е твърде късно и извън контрола
   на самия flow.
2. **Няма паралелизъм и няма топология.** Не може един агент да „захранва"
   няколко независими агента, които после да се събират (fan-out → fan-in).
   Полетата `agents.depends_on` и `agents.config` съществуват, но **не се ползват**
   за реален DAG.

**Целта:** потребителят сглобява flow визуално (drag & drop), като в n8n —
с възли (агенти) и връзки (edges). Един възел може да захранва N независими
възела, които се изпълняват едновременно, а изходите им се събират в общ
възел „Автор на доклад".

### Целеви пример (от заданието)

> Описание на flow: „Направи ми репорт за този бизнес: https://primelaser.bg/"

```
                    ┌─────────────────────────┐
                    │  [1] Base Info Extractor │  (DeepResearcher — име, контакти, мин. инфо)
                    └────────────┬─────────────┘
          ┌──────────────┬───────┼────────┬──────────────┐
          ▼              ▼        ▼        ▼              ▼
   ┌────────────┐ ┌───────────┐ ┌──────┐ ┌──────────┐ ┌──────────┐
   │[2] Site     │ │[3] Reviews│ │[4]   │ │[5]       │ │[6] ...   │
   │  Crawler    │ │  Finder   │ │Prices│ │Services  │ │          │
   │ (под-агенти │ │ (Google,  │ │      │ │          │ │          │
   │  за всяка   │ │ Tripadvis,│ │      │ │          │ │          │
   │  страница)  │ │ Yelp...)  │ │      │ │          │ │          │
   └─────┬──────┘ └─────┬─────┘ └──┬───┘ └────┬─────┘ └────┬─────┘
         └──────────────┴──────────┴──────────┴────────────┘
                                   ▼
                    ┌─────────────────────────┐
                    │ [7] Автор на доклад      │  ← получава ВСИЧКИ изходи от 2–6
                    └─────────────────────────┘
```

Възли 2–6 нямат зависимост помежду си → изпълняват се **паралелно**. Възел 7
има edges от всичките и стартира едва когато всички приключат (fan-in).

---

## 2. Графична библиотека — защо Drawflow

**Drawflow** (`jerosoler/Drawflow`, MIT лиценз, ~6k★, vanilla JS, без framework
зависимости) е най-добрият избор за текущия стек (Laravel + Blade + Alpine.js +
Tailwind), защото:

- Vanilla JS — интегрира се директно в Blade страница, без да въвеждаш Vue/React.
- n8n-подобен node редактор: drag & drop възли, връзки между конкретни портове,
  zoom/pan, експорт/импорт на целия граф като JSON (`editor.export()` /
  `editor.import()`).
- Поддържа множество входни/изходни портове на възел — точно каквото трябва за
  fan-out/fan-in.
- Лек, лесен за стилизиране с Tailwind, persistence е тривиален (export → JSON → DB).

**Разгледани алтернативи (всички безплатни):**

| Библиотека | Лиценз | Защо НЕ е избрана сега |
|---|---|---|
| **Rete.js** | MIT | По-мощен (типизирани портове, plugins), но по-стръмна крива и по-тежък. Резерва, ако Drawflow стане ограничаващ. |
| **React Flow / Vue Flow** (`@xyflow`) | MIT | Най-добрият UX (n8n ползва Vue Flow), но изисква да въведеш Vue/React island в проекта. Повече setup. |
| **JointJS (community)** | MPL/комерсиален | По-близко до GoJS като общо diagramming, но по-тежко за конкретния node-flow use case. |
| **LiteGraph.js** | MIT | Canvas-базиран, добър за node graphs, но по-малко активен и по-„игрови" UX. |

> **Препоръка за в бъдеще:** ако flow-овете станат много сложни (условни клонове,
> цикли, типизирани портове), мигрирай към **Rete.js** или **Vue Flow**. Архитектурата
> на данните (раздел 4) е библиотечно-агностична — graph JSON-ът се пази в наш формат,
> а Drawflow е само presentation слой, така че смяната по-късно е изолирана.

**Източник:** [Drawflow (GitHub)](https://github.com/jerosoler/Drawflow) ·
[Drawflow (npm)](https://www.npmjs.com/package/drawflow) ·
[Сравнение drawflow vs gojs](https://npmtrends.com/drawflow-vs-gojs-vs-jointjs-vs-jsplumb)

Инсталация:

```bash
npm install drawflow
```

```js
// resources/js/flow-builder.js
import Drawflow from 'drawflow';
import 'drawflow/dist/drawflow.min.css';
```

---

## 3. Архитектура на новия модел (общ преглед)

```
┌──────────────────────────────────────────────────────────────────┐
│  FRONTEND (Blade + Alpine + Drawflow)                              │
│  - Платно за drag & drop на агенти-възли                          │
│  - Палитра с типове агенти (от config/agent_types.php)            │
│  - Свързване на изходен порт → входен порт                        │
│  - Запис на graph JSON към backend                               │
└───────────────────────────┬──────────────────────────────────────┘
                            │ POST /flows/{flow}/graph  (graph JSON)
                            ▼
┌──────────────────────────────────────────────────────────────────┐
│  PERSISTENCE                                                       │
│  flow_nodes (възли)   +   flow_edges (връзки)                     │
│  + flows.graph_layout (JSON: позиции, zoom — за Drawflow render)  │
└───────────────────────────┬──────────────────────────────────────┘
                            │ run
                            ▼
┌──────────────────────────────────────────────────────────────────┐
│  GraphFlowExecutor (нов)                                           │
│  1. Зарежда DAG, валидира (без цикли)                            │
│  2. Топологична подредба → "вълни" (levels) от готови възли      │
│  3. За всяка вълна: Bus::batch([ExecuteNodeJob, ...])  ← ПАРАЛЕЛНО│
│  4. След batch завършва → следваща вълна                         │
│  5. Всеки node-изход се пази namespaced в node_runs              │
│  6. fan-in възел получава изходите на ВСИЧКИ свои предшественици  │
└──────────────────────────────────────────────────────────────────┘
```

Ключови разлики спрямо стария engine:

- Изпълнението е управлявано от **топология на графа**, не от `order`.
- Изходите се пазят **namespaced по възел** (`node_runs.output`), никога не се
  презаписват → **няма загуба на информация**.
- Входът на всеки възел се сглобява от изходите на **директните му предшественици**
  в графа, не от плосък мутиращ масив.

---

## 4. Модел на данните (DB schema — чист rewrite)

Изоставяме `agents.order` / `agents.depends_on` като механизъм за изпълнение.
Въвеждаме явни таблици за възли и връзки.

### 4.1 Нови таблици

**`flow_nodes`** — възел в графа (един „агент" на платното)

```php
Schema::create('flow_nodes', function (Blueprint $table) {
    $table->id();
    $table->foreignId('flow_id')->constrained()->cascadeOnDelete();
    $table->string('node_key');              // стабилен UUID/ключ от Drawflow (за edges)
    $table->string('name');
    $table->string('type', 50);              // от config/agent_types.php
    $table->string('icon')->nullable();
    $table->longText('prompt_template')->nullable();
    $table->text('system_prompt')->nullable();
    $table->string('model', 100)->nullable();
    $table->json('config')->nullable();      // temperature, qa, под-агент опции и т.н.
    // output preferences (както при стария Agent)
    $table->string('output_language')->nullable();
    $table->string('output_tone')->nullable();
    $table->string('output_style')->nullable();
    $table->string('output_format')->nullable();
    $table->string('output_role')->nullable();
    // позиция за рендер (дублирана в flows.graph_layout за бързина)
    $table->integer('pos_x')->default(0);
    $table->integer('pos_y')->default(0);
    $table->boolean('is_active')->default(true);
    $table->timestamps();

    $table->unique(['flow_id', 'node_key']);
});
```

**`flow_edges`** — насочена връзка „изход на A → вход на B"

```php
Schema::create('flow_edges', function (Blueprint $table) {
    $table->id();
    $table->foreignId('flow_id')->constrained()->cascadeOnDelete();
    $table->string('from_node_key');         // референция към flow_nodes.node_key
    $table->string('to_node_key');
    $table->string('from_port')->default('output_1');  // за multi-port възли
    $table->string('to_port')->default('input_1');
    $table->string('label')->nullable();     // по избор: етикет на връзката
    $table->timestamps();

    $table->index(['flow_id', 'from_node_key']);
    $table->index(['flow_id', 'to_node_key']);
});
```

**`flows`** — добавяме layout snapshot за Drawflow:

```php
Schema::table('flows', function (Blueprint $table) {
    $table->json('graph_layout')->nullable();   // суров Drawflow export (позиции/zoom)
});
```

**`node_runs`** — изпълнение на отделен възел (замества `agent_runs`)

```php
Schema::create('node_runs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('flow_run_id')->constrained()->cascadeOnDelete();
    $table->foreignId('flow_node_id')->constrained()->cascadeOnDelete();
    $table->string('node_key');
    $table->string('status');                // pending|running|completed|failed|skipped
    $table->longText('input')->nullable();
    $table->longText('output')->nullable();  // namespaced — НИКОГА не се презаписва
    $table->longText('raw_output')->nullable();
    $table->json('quality_metrics')->nullable();
    $table->string('model_used')->nullable();
    $table->unsignedInteger('tokens_used')->nullable();
    $table->unsignedBigInteger('duration_ms')->nullable();
    $table->text('error')->nullable();
    $table->timestamp('started_at')->nullable();
    $table->timestamp('completed_at')->nullable();
    $table->timestamps();

    $table->index(['flow_run_id', 'node_key']);
});
```

`flow_runs` остава почти същата (статус, context, final_output). Колоната `context`
вече ще пази по-малко „живи" данни — основно входните параметри на flow-а
(`url`, `topic`) и snapshot-и (QA политики). Реалните изходи живеят в `node_runs`.

### 4.2 Какво се изхвърля / мигрира

- `agents` таблица → **изхвърля се** (преименуваме концептуално на `flow_nodes`).
  Понеже е чист rewrite, пишем data-migration команда (раздел 9), която
  превръща съществуващите линейни `agents` в `flow_nodes` + верига от `flow_edges`
  (всеки агент → следващия по `order`).
- `agent_runs` → историческите данни може да се архивират; новите runs пишат в `node_runs`.
- `agents.order`, `agents.depends_on` → отпадат като execution механизъм.

### 4.3 Eloquent модели

```php
// app/Models/FlowNode.php
class FlowNode extends Model {
    protected $fillable = [/* както по-горе */];
    protected $casts = ['config' => 'array', 'is_active' => 'boolean'];
    public function flow(): BelongsTo { return $this->belongsTo(Flow::class); }
    public function nodeRuns(): HasMany { return $this->hasMany(NodeRun::class); }
}

// app/Models/FlowEdge.php
class FlowEdge extends Model {
    protected $fillable = ['flow_id','from_node_key','to_node_key','from_port','to_port','label'];
    public function flow(): BelongsTo { return $this->belongsTo(Flow::class); }
}
```

`Flow` получава: `nodes()`, `edges()`, `graph_layout` cast към array.

---

## 5. Backend: DAG executor с паралелно изпълнение

Нов сервиз `app/Services/GraphFlowExecutor.php` заменя `FlowExecutorService`.

### 5.1 Алгоритъм (топологични „вълни")

```
1. Зареди nodes + edges за flow.
2. Изгради adjacency map и in-degree на всеки възел.
3. ВАЛИДАЦИЯ:
   - Графът трябва да е DAG (без цикли) → ако има цикъл, flow run = failed.
   - Точно един „входен" възел (in-degree 0) ИЛИ позволи няколко (seed-ват се с входа).
   - Поне един „изходен" / fan-in възел (out-degree 0) — обикновено „Автор на доклад".
4. Изчисли вълни (levels) по алгоритъм на Kahn:
   - level 0 = всички възли с in-degree 0
   - премахни ги, намали in-degree на наследниците, level 1 = новите 0-degree, ...
5. ЗА всяка вълна:
   a. Създай NodeRun (status=pending) за всеки възел във вълната.
   b. Bus::batch([ ExecuteNodeJob(node, flowRun) за всеки възел ])
        ->then(  → маркирай вълната завършена, пусни следваща вълна )
        ->catch( → flow run = failed )
        ->dispatch();
   c. Изчакай batch-а (chain между вълните, виж 5.3).
6. Когато последната вълна (fan-in) приключи → final_output = изхода на
   терминалния възел (или FinalComposerService върху node_runs).
```

> Възлите в **една вълна** са независими → стартират едновременно през няколко
> queue worker-а. Възел стартира само когато **всичките му предшественици** са
> `completed` (гарантирано, защото е в по-късна вълна).

### 5.2 ExecuteNodeJob (паралелна единица)

```php
// app/Jobs/ExecuteNodeJob.php
class ExecuteNodeJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(
        public int $flowRunId,
        public int $flowNodeId,
    ) {}

    public function handle(NodeExecutorService $exec): void
    {
        if ($this->batch()?->cancelled()) return;
        $exec->executeNode($this->flowRunId, $this->flowNodeId);
    }
}
```

`NodeExecutorService::executeNode()` capсулира логиката от стария `executeAgent()`
(retry до 3 пъти, AgentFactory, ReasoningStripper, quality metrics), но:

- **Входът** се сглобява от изходите на **директните предшественици** (раздел 6).
- **Изходът** се пише в `node_runs.output` (namespaced) — никога не мутира общ масив.

### 5.3 Координация на вълните (Bus::batch chaining)

Понеже bat-ове са асинхронни, веригата от вълни се прави в `then()` callback:

```php
// GraphFlowExecutor::run()
public function run(Flow $flow, string $triggeredBy = 'manual'): FlowRun
{
    $flowRun = FlowRun::create([...'status' => 'running']);
    $waves = $this->topologicalWaves($flow);          // [[nodeIds], [nodeIds], ...]
    $flowRun->update(['context' => array_merge($flowRun->context ?? [], [
        'waves' => $waves, 'wave_index' => 0,
    ])]);
    $this->dispatchWave($flowRun, $waves, 0);
    return $flowRun;
}

private function dispatchWave(FlowRun $flowRun, array $waves, int $i): void
{
    if ($i >= count($waves)) { $this->finalize($flowRun); return; }

    $jobs = collect($waves[$i])->map(fn ($nodeId) =>
        new ExecuteNodeJob($flowRun->id, $nodeId)
    )->all();

    Bus::batch($jobs)
        ->name("flow-run-{$flowRun->id}-wave-{$i}")
        ->then(function () use ($flowRun, $waves, $i) {
            app(GraphFlowExecutor::class)->dispatchWave($flowRun->fresh(), $waves, $i + 1);
        })
        ->catch(function () use ($flowRun) {
            $flowRun->update(['status' => 'failed', 'completed_at' => now()]);
        })
        ->onQueue('flows')
        ->dispatch();
}
```

> **Изисквания за паралелизъм:**
> - `QUEUE_CONNECTION=database` (или Redis) — `database` е достатъчно за старт.
> - Таблица за batches: `php artisan queue:batches-table && php artisan migrate`.
> - **Няколко worker-а едновременно**, иначе „паралелните" задачи се изпълняват
>   едно по едно. В `composer.json` скриптът `dev` стартирай queue с няколко процеса,
>   напр.: `php artisan queue:work --queue=flows --tries=1` × N процеса
>   (или Horizon при Redis). Препоръка: 3–5 worker-а за старта.
> - Внимавай Ollama да понесе паралелните заявки (виж раздел 10, Рискове).

### 5.3.1 Алтернатива без множество worker-и (опростена)

Ако в момента има само 1 worker, fan-out възлите ще се наредят последователно
в опашката (все пак коректно — fan-in пак получава всичко). Реалната паралелна
печалба идва при ≥2 worker-а. Архитектурата не се променя — само броят процеси.

---

## 6. Контекст модел — как се решава загубата на информация

Това е сърцето на промяната. Заменяме плоския мутиращ масив с **namespaced**
изходи и **сглобяване от предшественици**.

### 6.1 Вход на възел = изходите на директните му предшественици

```php
// NodeExecutorService::buildNodeInput()
private function buildNodeInput(FlowRun $flowRun, FlowNode $node): array
{
    // Предшественици по edges (to_node_key == този възел)
    $predecessorKeys = FlowEdge::where('flow_id', $node->flow_id)
        ->where('to_node_key', $node->node_key)
        ->pluck('from_node_key');

    // Техните завършени изходи
    $upstream = NodeRun::where('flow_run_id', $flowRun->id)
        ->whereIn('node_key', $predecessorKeys)
        ->where('status', 'completed')
        ->get()
        ->mapWithKeys(fn ($r) => [$r->node_key => $r->output]);

    // Глобален seed контекст (входните параметри на flow-а — НЕ се мутират)
    $seed = $flowRun->context['seed'] ?? [];   // url, topic, company_*

    return ['seed' => $seed, 'upstream' => $upstream->all()];
}
```

### 6.2 Сглобяване на промпта

`{{url}}`, `{{topic}}` и т.н. идват от `seed` (непроменливи за целия run).
Изходите от предшествениците се подават **именувани и пълни**:

```php
private function renderPrompt(FlowNode $node, array $ctx): string
{
    $prompt = $node->prompt_template ?? '';

    // 1) seed placeholders ({{url}}, {{topic}}, {{company_name}}, ...)
    foreach ($ctx['seed'] as $k => $v) {
        if (is_string($v)) $prompt = str_replace(['{{'.$k.'}}','{'.$k.'}'], $v, $prompt);
    }

    // 2) изрични референции към конкретен предшественик: {{node:Reviews Finder}}
    foreach ($ctx['upstream'] as $nodeKey => $output) {
        $prompt = str_replace('{{node:'.$nodeKey.'}}', $output, $prompt);
    }

    // 3) Винаги добавяй пълен, именуван блок с ВСИЧКИ upstream изходи,
    //    които не са вече инлайнати — така fan-in възелът вижда всичко.
    $blocks = [];
    foreach ($ctx['upstream'] as $nodeKey => $output) {
        if ($output === null || $output === '') continue;
        if (str_contains($prompt, $output)) continue;  // вече инлайнат
        $blocks[] = "### Изход от: {$nodeKey}\n".$output;
    }
    if ($blocks) {
        $prompt .= "\n\n--- Контекст от предходните агенти ---\n".implode("\n\n", $blocks);
    }
    return $prompt;
}
```

**Ключова разлика:** „Автор на доклад" има edges от 5 възела → `$ctx['upstream']`
съдържа **5 пълни, именувани изхода**, всеки в отделна секция. Нищо не се
презаписва, нищо не се губи. (При нужда — truncation per-block с лимит, както
сегашния `handoffText()`, но per-предшественик, не глобално.)

### 6.3 Под-агенти (вече имплементирано) — без промяна

`DeepResearcherAgent` + `SiteCrawlerTool` (map-reduce: 1 под-агент на страница)
остават както са. От гледна точка на графа това е **един възел**, чийто вътрешен
fan-out е скрит. Външният граф fan-out (раздел 5) е на ниво възли. Двата слоя
паралелизъм са независими и съвместими.

---

## 7. Frontend: Drawflow редактор

### 7.1 Структура

Нов изглед `resources/views/flows/builder.blade.php` (заменя текстовия списък в
`flows/edit.blade.php` за управление на агентите). Layout:

```
┌────────────┬────────────────────────────────────────┬───────────────┐
│ Палитра    │  Платно (Drawflow #drawflow)           │ Инспектор     │
│ (типове    │  - drag възли тук                      │ (избран възел:│
│  агенти от │  - свързване порт→порт                 │  име, промпт, │
│  config)   │  - zoom/pan                            │  модел, config│
│            │                                        │  output prefs)│
└────────────┴────────────────────────────────────────┴───────────────┘
   [Запис] [Стартирай] [Валидирай граф]
```

### 7.2 Инициализация (Alpine + Drawflow)

```js
// resources/js/flow-builder.js
import Drawflow from 'drawflow';
import 'drawflow/dist/drawflow.min.css';

export function flowBuilder(config) {
  return {
    editor: null,
    selectedNode: null,
    init() {
      const el = document.getElementById('drawflow');
      this.editor = new Drawflow(el);
      this.editor.reroute = true;
      this.editor.start();

      // Зареди съществуващ граф
      if (config.graphLayout) this.editor.import(config.graphLayout);

      this.editor.on('nodeSelected', id => this.openInspector(id));
      this.editor.on('connectionCreated', e => this.onConnect(e));
    },
    addNode(type) {
      const meta = config.agentTypes[type];
      const html = `<div class="df-node"><b>${meta.label}</b></div>`;
      // 1 вход, 1 изход по подразбиране; fan-out се прави с няколко connection-а
      this.editor.addNode(type, 1, 1, 150, 100, type, { type, name: meta.label, config:{} }, html);
    },
    save() {
      const graph = this.editor.export();
      fetch(config.saveUrl, {
        method:'POST',
        headers:{'Content-Type':'application/json','X-CSRF-TOKEN':config.csrf},
        body: JSON.stringify({ graph })
      });
    },
  };
}
```

> Drawflow позволява няколко connection-а от един изходен порт → естествен fan-out.
> Fan-in = няколко connection-а към входния порт на „Автор на доклад".

### 7.3 Палитра на агентите

Палитрата се пълни от `config/agent_types.php` (вече съдържа `label` + `description`
за всеки тип) — групирани по `output_role` (research / processing / writing /
appendix), точно както са подредени в конфига.

### 7.4 Запис: graph JSON → нормализиран модел

Endpoint `POST /flows/{flow}/graph` приема Drawflow export-а и:

1. Пази суровия export в `flows.graph_layout` (за повторно зареждане 1:1).
2. **Нормализира** го към `flow_nodes` + `flow_edges`:
   - всеки Drawflow node → `flow_nodes` ред (node_key = Drawflow id).
   - всеки `connection` → `flow_edges` ред (from/to node_key + портове).
3. В транзакция: трие старите nodes/edges за flow-а и записва новите (или upsert).

```php
// FlowGraphController::store()
public function store(Request $r, Flow $flow) {
    $graph = $r->input('graph');                  // Drawflow export
    DB::transaction(function () use ($graph, $flow) {
        $flow->update(['graph_layout' => $graph]);
        app(GraphNormalizer::class)->sync($flow, $graph);   // → flow_nodes + flow_edges
    });
    return response()->json(['ok' => true]);
}
```

`GraphNormalizer` е единственото място, което „знае" Drawflow формата → ако
сменим библиотеката после, променяме само него.

---

## 8. API / Routes (нови и променени)

```php
// routes/web.php
Route::get('flows/{flow}/builder', [FlowBuilderController::class, 'show'])->name('flows.builder');
Route::post('flows/{flow}/graph',  [FlowGraphController::class, 'store'])->name('flows.graph.store');
Route::post('flows/{flow}/graph/validate', [FlowGraphController::class,'validateGraph'])->name('flows.graph.validate');

// Run (преизползва съществуващия pattern)
Route::post('flows/{flow}/run', [FlowRunController::class, 'store'])->name('flow-runs.store');
Route::get('runs/{flowRun}/poll', [FlowRunController::class, 'poll'])->name('flow-runs.poll');
```

`flows.graph.validate` връща резултата от DAG валидацията (цикли, висящи възли,
липсващ терминален възел) преди стартиране — добър UX в редактора.

`runs/{flowRun}/poll` връща per-node статуси, за да може builder-ът да оцвети
възлите на живо (pending/running/completed/failed) по време на run.

---

## 9. Миграционна стратегия (чист rewrite)

Понеже избрахме чист rewrite, но не искаме да трошим продукционни данни:

**Фаза 0 — data migration команда** `php artisan flows:migrate-to-graph`:

```
За всеки съществуващ Flow:
  1. Вземи agents подредени по `order`.
  2. Създай по 1 flow_node за всеки (пренеси prompt_template, model, config,
     output_* полета, type, name, icon).
  3. Създай flow_edges верига: agent[i].node_key → agent[i+1].node_key
     (линейният flow става линеен граф — поведението е идентично).
  4. Запиши graph_layout (авто-разположение: вертикална/хоризонтална верига),
     за да се отвори веднага в Drawflow.
```

Така всички стари flow-ове продължават да работят на новия engine **без ръчна
намеса**, но вече са редактируеми визуално и могат да се разклоняват.

**Премахване на стария код (след валидиране):**

- `FlowExecutorService` → изтрива се (логиката се преразпределя в
  `GraphFlowExecutor` + `NodeExecutorService`).
- `ExecuteFlowJob` → пренасочва към `GraphFlowExecutor::run()`.
- `Agent` / `AgentRun` модели и таблици → депрекирани; четене за история, запис спира.
- `agents.order` / `depends_on` логика → премахната.

> `AgentFactory`, `BaseAgent`, всички конкретни агенти (`DeepResearcherAgent`,
> `ReviewAnalyzerAgent`, …), `Tools/*` и `FinalComposerService` се **запазват** —
> те работят на ниво единичен агент и са независими от orchestration-а. Само
> сигнатурата на входа се адаптира (раздел 6).

---

## 10. Рискове и мерки

| Риск | Мярка |
|---|---|
| **Ollama bottleneck** при паралелни заявки (локален модел, ограничен VRAM) | Ограничи паралелизма: max N едновременни node jobs (`Bus::batch` + ограничен брой worker-и, или семафор/`Redis::funnel`). Старт с 2–3. |
| **Цикъл в графа** | Задължителна DAG валидация преди run (Kahn — ако остане възел с in-degree>0, има цикъл) → flow run = failed с ясно съобщение. |
| **Висящ fan-in** (възел чака предшественик, който е failed) | Политика per-flow: „fail-fast" (целият run пада) или „best-effort" (продължи с наличните изходи, маркирай липсващите като `skipped`). Конфигурируемо в `flows`. |
| **Раздут вход на „Автор на доклад"** (5+ дълги изхода → token limit) | Per-предшественик truncation + опционален „Summarizer" възел пред автора (map-reduce на ниво граф). |
| **Загуба на координация на вълните** при срив на worker | `Bus::batch` с `->allowFailures(false)` + idempotent `ExecuteNodeJob` (проверка дали NodeRun вече е completed). |
| **Race при запис на graph_layout** | Запис в транзакция, optimistic lock по `flows.updated_at` (по избор). |

---

## 11. Фази на имплементация (последователност на работа)

> Подредени така, че всяка фаза е тестируема самостоятелно.

**Фаза 1 — Данни и модели**
- Миграции: `flow_nodes`, `flow_edges`, `node_runs`, `flows.graph_layout`.
- Модели: `FlowNode`, `FlowEdge`, `NodeRun` + релации в `Flow`, `FlowRun`.
- `queue:batches-table` + миграция, `QUEUE_CONNECTION` setup.

**Фаза 2 — Нормализация на графа**
- `GraphNormalizer` (Drawflow JSON ↔ flow_nodes/flow_edges).
- `FlowGraphController@store` + route.
- Unit тестове: import → DB → export round-trip.

**Фаза 3 — DAG executor (паралелно)**
- `GraphFlowExecutor` (топологични вълни, Kahn, валидация).
- `NodeExecutorService` (вход от предшественици, namespaced изход, retry).
- `ExecuteNodeJob` (Batchable).
- Wiring на `ExecuteFlowJob` → `GraphFlowExecutor`.
- Тестове: fan-out/fan-in граф (примерът от раздел 1) → провери, че авторът
  получава всичките 5 изхода.

**Фаза 4 — Frontend builder**
- `npm i drawflow`, `flow-builder.js`, `builder.blade.php`.
- Палитра от `config/agent_types.php`, инспектор-панел.
- Запис/зареждане на граф, валидация бутон.
- Live статуси на възлите чрез `poll`.

**Фаза 5 — Миграция и почистване**
- `flows:migrate-to-graph` команда.
- Изпълни на staging, валидирай, че старите flow-ове дават същия резултат.
- Премахни `FlowExecutorService` и legacy execution код.

**Фаза 6 — Hardening**
- Ограничаване на паралелизъм спрямо Ollama.
- Политика за частични грешки (fail-fast / best-effort).
- Опционален „граф-Summarizer" пред автора при голям контекст.

---

## 12. Verification checklist (преди „готово")

- [ ] DAG валидацията хваща цикъл и липсващ терминален възел.
- [ ] Fan-out: 5 възела от един предшественик стартират паралелно (виж timestamps
      в `node_runs.started_at` — застъпват се при ≥2 worker-а).
- [ ] Fan-in: „Автор на доклад" получава **всичките** upstream изходи (assert, че
      входът съдържа маркерите на всеки предшественик).
- [ ] Няма презаписване: всеки `node_runs.output` е уникален и пълен.
- [ ] `flows:migrate-to-graph` дава идентичен изход на линеен flow спрямо стария engine.
- [ ] Round-trip: Drawflow export → DB → import рендерира същия граф.
- [ ] Live poll оцветява възлите коректно по време на run.

---

### Източници
- [Drawflow — GitHub (jerosoler/Drawflow, MIT)](https://github.com/jerosoler/Drawflow)
- [Drawflow — npm](https://www.npmjs.com/package/drawflow)
- [Сравнение: drawflow vs gojs vs jointjs vs jsplumb](https://npmtrends.com/drawflow-vs-gojs-vs-jointjs-vs-jsplumb)
- [awesome-node-based-uis (xyflow) — каталог на алтернативи](https://github.com/xyflow/awesome-node-based-uis)
- [Open-Source алтернативи на GoJS](https://portalzine.de/visualize-this-open-source-diagram-tools-to-replace-gojs/)
- Laravel Job Batching — `Bus::batch` (официална документация на Laravel)
