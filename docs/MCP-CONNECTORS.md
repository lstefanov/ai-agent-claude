# MCP Конектори - Агенти, Които Действат

> Имплементационен план - готов за изпълнение.
>
> **КРИТИЧНО:** MCP конекторите позволяват на агентите да четат и пишат в
> реални системи. Грешка в auth архитектурата или в передаването на credentials
> към агентите може да провали целия FlowRun или да компрометира данни.
> Следвай плана стъпка по стъпка, без shortcuts.

---

## 1. Архитектурен overview

```
Company (ниво: auth)
    └── CompanyConnector (oauth tokens, scopes)
            └── Flow (ниво: routing + настройки)
                    └── FlowNode (type=mcp_action, config: connector_id, tool, params)
                            └── McpClientService (изпълнява tool call)
                                    └── Google услуга (Gmail, Sheets, Drive, Docs, Calendar)
```

**Принцип:** Auth живее на Company ниво (веднъж свързваш Gmail на фирмата).
Flow-specific настройки (коя папка в Drive, кой лист в Sheets) живеят в `FlowNode.config`.
Агентите НИКОГА не получават raw tokens - само `connector_id` + `tool` + `params`.
`McpClientService` е единственото място, което резолвира token → Google API call.

---

## 2. База данни

### 2.1 `company_connectors`

```sql
CREATE TABLE company_connectors (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id      INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    connector_type  VARCHAR(50) NOT NULL,  -- 'gmail','google_drive','google_sheets',
                                           --  'google_docs','google_calendar'
    display_name    VARCHAR(255),          -- "Фирмен Gmail" (може да имаш 2 Gmail акаунта)
    auth_type       VARCHAR(20) NOT NULL,  -- 'oauth2' only
    credentials     TEXT NOT NULL,         -- ENCRYPTED JSON (виж §2.3)
    scopes          JSON,                  -- ['gmail.readonly','gmail.send'] etc.
    status          VARCHAR(20) DEFAULT 'active',  -- active|expired|revoked|error
    last_tested_at  DATETIME,
    last_error      TEXT,
    settings        JSON,                  -- connector-specific defaults (default folder etc.)
    created_at      DATETIME,
    updated_at      DATETIME,
    UNIQUE (company_id, connector_type, display_name)
);
```

### 2.2 `connector_tool_logs`

```sql
CREATE TABLE connector_tool_logs (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id      INTEGER NOT NULL,
    connector_id    INTEGER REFERENCES company_connectors(id) ON DELETE SET NULL,
    flow_run_id     INTEGER REFERENCES flow_runs(id) ON DELETE SET NULL,
    node_run_id     INTEGER REFERENCES node_runs(id) ON DELETE SET NULL,
    tool            VARCHAR(100),          -- 'gmail.send', 'sheets.append_row' etc.
    params          JSON,                  -- входните параметри (без credentials)
    status          VARCHAR(20),           -- 'ok' | 'error' | 'skipped'
    result_summary  TEXT,                  -- кратко описание на изхода
    error           TEXT,
    duration_ms     INTEGER,
    created_at      DATETIME
);
CREATE INDEX idx_connector_logs_run ON connector_tool_logs(flow_run_id);
```

### 2.3 Encryption на credentials

**Никога plaintext.** Използваш Laravel's `encrypt()` / `decrypt()`:

```php
// В CompanyConnector модела:
public function setCredentialsAttribute(array $value): void
{
    $this->attributes['credentials'] = encrypt(json_encode($value));
}

public function getCredentialsAttribute(string $value): array
{
    return json_decode(decrypt($value), true);
}
```

`APP_KEY` в `.env` е ключа за криптиране - не го сменяй, докато имаш записани credentials.

### 2.4 Migration файлове

```
2026_XX_XX_100000_create_company_connectors_table.php
2026_XX_XX_100001_create_connector_tool_logs_table.php
```

---

## 3. Модели

### 3.1 `CompanyConnector` (app/Models/CompanyConnector.php)

```php
class CompanyConnector extends Model
{
    protected $fillable = [
        'company_id','connector_type','display_name','auth_type',
        'credentials','scopes','status','last_tested_at','last_error','settings',
    ];

    protected $casts = [
        'scopes'   => 'array',
        'settings' => 'array',
        'last_tested_at' => 'datetime',
    ];

    // credentials: custom getter/setter с encrypt() - виж §2.3

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function toolLogs(): HasMany
    {
        return $this->hasMany(ConnectorToolLog::class, 'connector_id');
    }

    /** Дали OAuth token трябва refresh */
    public function needsRefresh(): bool
    {
        $creds = $this->credentials;
        return isset($creds['expires_at']) && now()->timestamp > $creds['expires_at'] - 60;
    }
}
```

---

## 4. MCP Client Architecture

### 4.1 Абстракция: `McpConnectorInterface`

```php
// app/Services/Mcp/McpConnectorInterface.php
interface McpConnectorInterface
{
    /** Списък от налични tools с техните JSON schemas */
    public function listTools(): array;   // [{ name, description, parameters }]

    /** Изпълнява един tool call */
    public function callTool(string $tool, array $params): McpToolResult;

    /** Проверява дали credentials са валидни */
    public function testConnection(): bool;
}
```

### 4.2 `McpToolResult`

```php
// app/Services/Mcp/McpToolResult.php
readonly class McpToolResult
{
    public function __construct(
        public readonly bool   $success,
        public readonly string $text,       // човешко-четим резултат за агента
        public readonly array  $data = [],  // структурирани данни (опционално)
        public readonly ?string $error = null,
    ) {}
}
```

### 4.3 `McpClientService` - централен router

```php
// app/Services/McpClientService.php
class McpClientService
{
    private array $connectorCache = [];

    public function __construct(
        private GmailConnector        $gmail,
        private GoogleSheetsConnector $sheets,
        private GoogleDriveConnector  $drive,
        private GoogleDocsConnector   $docs,
        private GoogleCalendarConnector $calendar,
    ) {}

    /**
     * Взима connector instance за конкретен CompanyConnector запис.
     * Резолвира от config('mcp.registry') - единственото място където credentials влизат!
     */
    public function resolve(CompanyConnector $connector): McpConnectorInterface
    {
        $connectorClass = config("mcp.registry.{$connector->connector_type}");
        if (!$connectorClass) {
            throw new \InvalidArgumentException("Непознат конектор: {$connector->connector_type}");
        }

        $instance = app($connectorClass);
        return $instance->withCredentials($connector->credentials);
    }

    /**
     * Изпълнява tool call + логва в connector_tool_logs.
     * Агентите НЕ извикват resolve() директно - минават само през callTool().
     */
    public function callTool(
        CompanyConnector $connector,
        string           $tool,
        array            $params,
        ?int             $flowRunId  = null,
        ?int             $nodeRunId  = null,
    ): McpToolResult {
        $started = microtime(true);

        // OAuth refresh ако е нужен
        if ($connector->needsRefresh()) {
            $this->refreshOAuthToken($connector);
        }

        try {
            $result = $this->resolve($connector)->callTool($tool, $params);
            $status = $result->success ? 'ok' : 'error';
        } catch (\Throwable $e) {
            $result = new McpToolResult(false, '', [], $e->getMessage());
            $status = 'error';
        }

        ConnectorToolLog::create([
            'company_id'     => $connector->company_id,
            'connector_id'   => $connector->id,
            'flow_run_id'    => $flowRunId,
            'node_run_id'    => $nodeRunId,
            'tool'           => $tool,
            'params'         => $this->sanitizeParams($params),  // SSRF guard + no credentials in logs
            'status'         => $status,
            'result_summary' => mb_substr($result->text, 0, 500),
            'error'          => $result->error,
            'duration_ms'    => (int) ((microtime(true) - $started) * 1000),
        ]);

        return $result;
    }

    /** Маха sensitive данни от params преди логване */
    private function sanitizeParams(array $params): array
    {
        $sensitive = ['token','secret','password','key','authorization'];
        return array_filter($params, fn($k) => !in_array(strtolower($k), $sensitive), ARRAY_FILTER_USE_KEY);
    }
}
```

---

## 5. Петте Google конектора

| # | Connector | Auth | Клас | Инструменти |
|---|-----------|------|------|-----------|
| 1 | **Gmail** | OAuth2 | `GmailConnector` | list, get, send, reply, draft, label |
| 2 | **Google Sheets** | OAuth2 | `GoogleSheetsConnector` | read_range, append_row, update_range, create_sheet, get_values |
| 3 | **Google Drive** | OAuth2 | `GoogleDriveConnector` | list_files, get_file_content, upload_file, create_doc |
| 4 | **Google Docs** | OAuth2 | `GoogleDocsConnector` | list_documents, get_document, create_document, append_text |
| 5 | **Google Calendar** | OAuth2 | `GoogleCalendarConnector` | list_calendars, list_events, get_event, create_event |

### 5.1 Tools по конектор

#### Gmail (`GmailConnector`)
- Read: `gmail.list_emails`, `gmail.get_email`
- Write: `gmail.send_email`, `gmail.reply`, `gmail.create_draft`, `gmail.label_email`

#### Google Sheets (`GoogleSheetsConnector`)
- Read: `sheets.read_range`, `sheets.get_values`
- Write: `sheets.append_row`, `sheets.update_range`, `sheets.create_sheet`

#### Google Drive (`GoogleDriveConnector`)
- Read: `drive.list_files`, `drive.get_file_content` (auto-exports Google Docs to text)
- Write: `drive.upload_file`, `drive.create_doc`

#### Google Docs (`GoogleDocsConnector`)
- Read: `docs.list_documents`, `docs.get_document` (accepts doc id or full Docs URL)
- Write: `docs.create_document`, `docs.append_text`
- Scopes: documents, drive.readonly

#### Google Calendar (`GoogleCalendarConnector`)
- Read: `calendar.list_calendars`, `calendar.list_events` (defaults to next 30 days), `calendar.get_event`
- Write: `calendar.create_event`
- Scopes: calendar.readonly, calendar.events

---

## 6. Нов Node тип: `mcp_action`

### 6.1 В `config/agent_types.php`

```php
'mcp_action' => [
    'label'       => 'MCP Действие',
    'description' => 'Изпълнява конкретно действие в Google услуга (Gmail, Sheets, Drive, Docs, Calendar)',
    'output_role' => 'action',   // нов output_role - не е 'body', не е 'transform'
    'icon'        => '🔌',
    'has_prompt'  => false,      // не генерира текст, изпълнява action
],
```

### 6.2 Конфигурация на `mcp_action` node

```json
{
  "type": "mcp_action",
  "connector_id": 3,
  "tool": "gmail.send_email",
  "tool_params": {
    "to": "{{flow.input.recipient}}",
    "subject": "{{agent.email_subject_writer.output}}",
    "body": "{{agent.email_body_writer.output}}"
  },
  "requires_approval": true,
  "approval_message": "Готов съм да изпратя имейл до {{flow.input.recipient}}. Потвърди."
}
```

### 6.3 Variable interpolation в `tool_params`

Специален resolver (нов `McpParamResolver`) разпознава:
- `{{flow.input.X}}` - от входния prompt/variables на run-а
- `{{agent.NODE_KEY.output}}` - изход от предишен агент (от `context['nodes']`)
- `{{connector.setting.X}}` - от `CompanyConnector.settings` (напр. default folder)
- `{{flow.setting.X}}` - от `FlowNode.config` (flow-specific настройки)

**Важно:** Resolver работи СЛЕД fan-in (след predecessor агентите), така {{agent.X.output}} е достъпен.

### 6.4 `MpcActionAgent` (app/Agents/McpActionAgent.php)

```php
class McpActionAgent extends BaseAgent
{
    public function __construct(
        private McpClientService  $mcp,
        private McpParamResolver  $resolver,
    ) {}

    public function run(FlowNode $node, FlowRun $run, array $predecessorOutputs): string
    {
        $config      = $node->config;
        $connectorId = (int) ($config['connector_id'] ?? 0);
        $tool        = (string) ($config['tool'] ?? '');
        $rawParams   = (array) ($config['tool_params'] ?? []);

        $connector = CompanyConnector::where('company_id', $run->flow->company_id)
            ->findOrFail($connectorId);

        // Резолвираме variables в params
        $params = $this->resolver->resolve($rawParams, $run, $predecessorOutputs);

        $result = $this->mcp->callTool(
            connector:  $connector,
            tool:       $tool,
            params:     $params,
            flowRunId:  $run->id,
            nodeRunId:  $this->currentNodeRunId,
        );

        if (! $result->success) {
            throw new \RuntimeException("MCP tool '{$tool}' fail: {$result->error}");
        }

        // Изходът на node-а е текстовото описание на резултата
        return $result->text;
    }
}
```

### 6.5 `requires_approval` - КРИТИЧНО

Всеки `mcp_action` node с деструктивни или изходящи действия **трябва** да мине
през `human_approval` node преди него в DAG-а. Системата го налага на две нива:

**Ниво 1 - Планерът:** В `FlowPlannerService::designPipeline()` се добавя правило:

```php
private const MCP_WRITE_TOOLS = [
    'gmail.send_email', 'gmail.reply',
    'sheets.append_row', 'sheets.update_range', 'sheets.create_sheet',
    'drive.upload_file', 'drive.create_doc',
    'docs.create_document', 'docs.append_text',
    'calendar.create_event',
    // НЕ включва: list_, read_, get_ - те са read-only
];
```

Ако планерът постави `mcp_action` node с write tool, автоматично вмъква
`human_approval` node като негов predecessor в DAG-а.

**Ниво 2 - Executor:** `McpActionAgent::run()` проверява `requires_approval` flag.
Ако е `true` и нямаме `context['approvals'][node_key]['approved'] === true` →
хвърля `ApprovalRequiredException` → `GraphFlowExecutor` пауза run-а.

Само `gmail.create_draft`, `drive.list_files`, read-only tools → `requires_approval: false`.

---

## 7. Промени в `FlowPlannerService`

### 7.1 Динамичен capability registry

Планерът трябва да знае КОИ конектори са свързани за ТЕКУЩАТА Company:

```php
// В FlowPlannerService::plan()
private function buildMcpCapabilities(Flow $flow): array
{
    return CompanyConnector::where('company_id', $flow->company_id)
        ->where('status', 'active')
        ->get()
        ->flatMap(fn($c) => $this->mcpClient->resolve($c)->listTools()
            ->map(fn($t) => array_merge($t, [
                'connector_id' => $c->id,
                'connector_name' => $c->display_name,
            ]))
        )
        ->all();
}
```

### 7.2 В `AVAILABLE_TOOLS` (в системния промпт на планера)

```
НАЛИЧНИ MCP ДЕЙСТВИЯ (реални системи):
- gmail.send_email (connector_id:3, "Фирмен Gmail") - изпраща имейл; ИЗИСКВА human_approval преди него
- sheets.append_row (connector_id:7, "Продажби Sheet") - добавя ред; ИЗИСКВА human_approval
- gmail.list_emails (connector_id:3) - чете входяща поща; НЕ изисква approval
- sheets.read_range (connector_id:7) - чете данни; НЕ изисква approval

ПРАВИЛО: За всеки mcp_action node с write операция, постави human_approval node ПРЕДИ него в зависимостите.
```

### 7.3 Нов агент тип в schema-та на планера

```json
{
  "type": "mcp_action",
  "uid": "send_confirmation_email",
  "name": "Изпрати потвърждение",
  "connector_id": 3,
  "tool": "gmail.send_email",
  "tool_params": {
    "to": "{{flow.input.client_email}}",
    "subject": "{{agent.subject_writer.output}}",
    "body": "{{agent.email_writer.output}}"
  },
  "requires_approval": true,
  "depends_on": ["approval_gate", "subject_writer", "email_writer"]
}
```

---

## 8. Flow-level настройки за MCP

### 8.1 Структура на `flow_nodes.config` за MCP nodes

```json
{
  "connector_id": 3,
  "tool": "drive.upload_file",
  "tool_params": {
    "folder_id": "{{connector.setting.default_reports_folder}}",
    "filename": "Доклад_{{date:Y-m-d}}.pdf",
    "content": "{{agent.report_writer.output}}"
  },
  "flow_settings": {
    "target_folder": "1BXk…DriveID",
    "file_prefix": "Доклад_"
  },
  "requires_approval": false
}
```

### 8.2 В Builder-а - MCP Node конфигурационен панел

Когато потребителят кликне на `mcp_action` node:

```
┌─────────────────────────────────────────────────────┐
│  🔌 MCP Действие: Изпрати имейл                     │
├─────────────────────────────────────────────────────┤
│  Конектор: [Фирмен Gmail (igroup7@gmail.com)]  ▼    │
│  Действие: [gmail.send_email]                  ▼    │
├─────────────────────────────────────────────────────┤
│  Параметри:                                         │
│  До:      {{flow.input.client_email}}               │
│  Тема:    {{agent.subject_writer.output}}            │
│  Текст:   {{agent.email_writer.output}}              │
├─────────────────────────────────────────────────────┤
│  ☑ Изисква потвърждение преди изпращане             │
│  Съобщение: Готов съм да изпратя имейл до...        │
└─────────────────────────────────────────────────────┘
```

---

## 9. Управление на конекторите

### 9.1 Admin страница за конектори

**Route:** `companies/{company}/connectors`
**Controller:** `CompanyConnectorController`

```
┌─────────────────────────────────────────────────────────────┐
│  Свързани системи за "Спортен Център"    [+ Свържи нова]     │
├─────────────────────────────────────────────────────────────┤
│  📧 Gmail              igroup7@gmail.com   🟢 Активен  [⚙️] │
│  📊 Google Sheets      Продажби 2026       🟢 Активен  [⚙️] │
│  📄 Google Docs        Доклади/            🟢 Активен  [⚙️] │
│  📅 Google Calendar    Работен календар    🟡 Изтекъл  [↺]  │
└─────────────────────────────────────────────────────────────┘
```

**Статуси:**
- 🟢 Активен - credentials са валидни
- 🟡 Изтекъл - OAuth token трябва refresh (бутон „Обнови")
- 🔴 Грешка - connection тест е fail (бутон „Провери")

### 9.2 Client портал - `/org/integrations`

Клиентите имат пълна управа на конекторите във вътрешния портал:
- Свързване на нови конектори (Google OAuth)
- Отключване на съществуващи
- Тестване на връзката
- Преглед на historia на ползване

### 9.3 Единен Google OAuth callback

**Ключова характеристика:** Един глобален FlowAI Google OAuth app и един callback endpoint служи ВСИЧКИ surfaces (admin + client portal).

```
Admin OR Client portal → [+ Свържи Gmail]
    → Redirect към Google OAuth consent screen
    → Google callback: /oauth/google/callback?code=…&state={encrypted_origin}
    → Callback декриптира state → разбира origin (admin vs client)
    → Записва access_token + refresh_token в company_connectors
    → Redirect обратно към правилния surface с ✅
```

**Routes:**
```php
// Единствен callback - работи за admin и client
Route::get('oauth/google/callback', [OAuthController::class, 'googleCallback'])->name('oauth.google.callback');

// Admin CRUD
Route::get('companies/{company}/connectors', [CompanyConnectorController::class, 'index'])->name('connectors.index');
Route::post('companies/{company}/connectors', [CompanyConnectorController::class, 'store'])->name('connectors.store');
Route::put('companies/{company}/connectors/{c}', [CompanyConnectorController::class, 'update'])->name('connectors.update');
Route::delete('companies/{company}/connectors/{c}', [CompanyConnectorController::class, 'destroy'])->name('connectors.destroy');
Route::post('companies/{company}/connectors/{c}/test', [CompanyConnectorController::class, 'test'])->name('connectors.test');

// Client portal integrations (on client domain / /client/org)
Route::get('/org/integrations', [ClientIntegrationController::class, 'index'])->name('org.integrations.index');
Route::post('/org/integrations/{type}', [ClientIntegrationController::class, 'initiate'])->name('org.integrations.initiate');
Route::post('/org/integrations/{connector}/disconnect', [ClientIntegrationController::class, 'destroy'])->name('org.integrations.destroy');
Route::post('/org/integrations/{connector}/test', [ClientIntegrationController::class, 'test'])->name('org.integrations.test');
```

---

## 10. `AgentFactory` - нов тип агент

```php
// В app/Agents/AgentFactory.php - в match():
'mcp_action' => app(McpActionAgent::class),
```

`McpActionAgent` се регистрира в `AppServiceProvider` като singleton с инжектирани
зависимости `McpClientService` + `McpParamResolver`.

---

## 11. Промени в `NodeExecutorService`

В `executeNode()`, след взимането на FlowNode:

```php
// Специален path за mcp_action - не минава през AgentLoop или OllamaService
if ($flowNode->type === 'mcp_action') {
    $agent = AgentFactory::make('mcp_action');
    $output = $agent->run($flowNode, $flowRun, $this->predecessorOutputs($flowNode, $flowRun));
    // Записва в node_runs, но model_used = 'mcp:' . $config['connector_type']
    // cost_usd = 0 (MCP calls не се таксуват от нас)
    return;
}
```

---

## 12. SSRF Guard и сигурност

### 12.1 `SsrfGuard` за изходящи HTTP заявки

```php
// config/mcp.php
'ssrf' => [
    'allowed_schemes' => ['https'],
    'blocked_hosts'   => ['localhost', '127.0.0.1', '0.0.0.0', '::1'],
    'blocked_cidrs'   => ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'],
    'max_response_size_kb' => 512,
    'timeout_seconds' => 15,
],
```

**Употреба:** `SsrfGuard` блокира небезопасни локални мрежови цели при операции като Gmail attachment downloads.

### 12.2 Credential isolation

- Credentials НИКОГА не влизат в промптите на агентите.
- `McpParamResolver` блокира `{{credentials.*}}` и `{{connector.credentials.*}}` в tool_params.
- Логовете в `connector_tool_logs` пазят само `sanitizeParams()` - без tokens.

### 12.3 Scope enforcement

`GmailConnector` с scope `gmail.readonly` → хвърля `ScopeException` ако се опита да извика `gmail.send_email`.
Scope-овете се записват при OAuth и се проверяват при всеки `callTool()`.

---

## 13. config/mcp.php структура

```php
return [
    //単一真実の源 - всички конектори, които система поддържа
    'registry' => [
        'gmail'          => \App\Services\Mcp\GmailConnector::class,
        'google_sheets'  => \App\Services\Mcp\GoogleSheetsConnector::class,
        'google_drive'   => \App\Services\Mcp\GoogleDriveConnector::class,
        'google_docs'    => \App\Services\Mcp\GoogleDocsConnector::class,
        'google_calendar' => \App\Services\Mcp\GoogleCalendarConnector::class,
    ],

    'tool_namespaces' => ['gmail', 'sheets', 'drive', 'docs', 'calendar'],

    // Кои инструменти са деструктивни и изискват human_approval
    'write_tools' => [
        'gmail.send_email', 'gmail.reply',
        'sheets.append_row', 'sheets.update_range', 'sheets.create_sheet',
        'drive.upload_file', 'drive.create_doc',
        'docs.create_document', 'docs.append_text',
        'calendar.create_event',
    ],

    // Cada connectorის UI каталог (admin + client portal)
    'catalog' => [
        'gmail' => [
            'label' => 'Gmail',
            'icon' => '📧',
            'description' => 'Четене и писане в Gmail',
        ],
        'google_sheets' => [
            'label' => 'Google Sheets',
            'icon' => '📊',
            'description' => 'Работа със Sheets',
        ],
        'google_drive' => [
            'label' => 'Google Drive',
            'icon' => '📁',
            'description' => 'Качване и четене на Drive',
        ],
        'google_docs' => [
            'label' => 'Google Docs',
            'icon' => '📄',
            'description' => 'Работа с Docs',
        ],
        'google_calendar' => [
            'label' => 'Google Calendar',
            'icon' => '📅',
            'description' => 'Управление на събитията',
        ],
    ],

    // SSRF охрана за изходящ трафик (напр. Gmail attachment downloads)
    'ssrf' => [
        'allowed_schemes' => ['https'],
        'blocked_hosts'   => ['localhost', '127.0.0.1', '0.0.0.0', '::1'],
        'blocked_cidrs'   => ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'],
        'max_response_size_kb' => 512,
        'timeout_seconds' => 15,
    ],
];
```

**ENV промени:**
```env
GOOGLE_OAUTH_CLIENT_ID=...
GOOGLE_OAUTH_CLIENT_SECRET=...
GOOGLE_OAUTH_REDIRECT_URI=https://yourdomain.com/oauth/google/callback
```

---

## 14. Ключови дизайн решения

**Единствено Google OAuth.**
Всички петте конектора са Google OAuth.
Няма API-key connectors, няма HTTP API connector.
Една глобална Google OAuth app, един callback endpoint.

**Config-driven resolver.**
`McpClientService::resolve()` чете от `config('mcp.registry')`, не hardcoded constructor.
Лесно добавяне на нови конектори чрез конфиг, без промяна на сервиса.

**Client portal parity.**
Admin щета (`/companies/{company}/connectors`) и Client portal (`/org/integrations`) имат еднакви възможности.
Клиентите могат да свързват, отключват, тестват и преглеждат история на всички конектори.

**Credentials isolation.**
Credentials НИКОГА не влизат в агент промптите.
Само `McpClientService` има достъп до tokens.
`McpParamResolver` блокира `{{credentials.*}}` в tool parameters.

**No fallback connectors.**
Няма HTTP API connector като резервен вариант.
Agents проектирани за Google ecosystem само.
Falls са fail-fast чрез message к user.
