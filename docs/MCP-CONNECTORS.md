# MCP Конектори — Агенти, Които Действат

> Имплементационен план — готов за изпълнение.
>
> **КРИТИЧНО:** MCP конекторите позволяват на агентите да четат и пишат в
> реални системи. Грешка в auth архитектурата или в передаването на credentials
> към агентите може да провали целия FlowRun или да компрометира данни.
> Следвай плана стъпка по стъпка, без shortcuts.

---

## 1. Архитектурен overview

```
Company (ниво: auth)
    └── CompanyConnector (oauth tokens / api keys, scopes)
            └── Flow (ниво: routing + настройки)
                    └── FlowNode (type=mcp_action, config: connector_id, tool, params)
                            └── McpClientService (изпълнява tool call)
                                    └── Реална система (Gmail, Sheets, Slack…)
```

**Принцип:** Auth живее на Company ниво (веднъж свързваш Gmail на фирмата).
Flow-specific настройки (коя папка в Drive, кой Slack канал) живеят в `FlowNode.config`.
Агентите НИКОГА не получават raw tokens — само `connector_id` + `tool` + `params`.
`McpClientService` е единственото място, което резолвира token → HTTP call.

---

## 2. База данни

### 2.1 `company_connectors`

```sql
CREATE TABLE company_connectors (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id      INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    connector_type  VARCHAR(50) NOT NULL,  -- 'gmail','google_drive','google_sheets',
                                           --  'slack','notion','airtable','hubspot',
                                           --  'github','trello','mailchimp','http_api'
    display_name    VARCHAR(255),          -- "Фирмен Gmail" (може да имаш 2 Gmail акаунта)
    auth_type       VARCHAR(20) NOT NULL,  -- 'oauth2' | 'api_key' | 'bearer' | 'basic'
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

`APP_KEY` в `.env` е ключа за криптиране — не го сменяй, докато имаш записани credentials.

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

    // credentials: custom getter/setter с encrypt() — виж §2.3

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

### 4.3 `McpClientService` — централен router

```php
// app/Services/McpClientService.php
class McpClientService
{
    private array $connectorCache = [];

    public function __construct(
        private GmailConnector        $gmail,
        private GoogleSheetsConnector $sheets,
        private GoogleDriveConnector  $drive,
        private SlackConnector        $slack,
        private NotionConnector       $notion,
        private AirtableConnector     $airtable,
        private HubSpotConnector      $hubspot,
        private GitHubConnector       $github,
        private TrelloConnector       $trello,
        private MailchimpConnector    $mailchimp,
        private HttpApiConnector      $httpApi,
    ) {}

    /**
     * Взима connector instance за конкретен CompanyConnector запис.
     * Инжектира credentials — единственото място в кода!
     */
    public function resolve(CompanyConnector $connector): McpConnectorInterface
    {
        return match ($connector->connector_type) {
            'gmail'          => $this->gmail->withCredentials($connector->credentials),
            'google_sheets'  => $this->sheets->withCredentials($connector->credentials),
            'google_drive'   => $this->drive->withCredentials($connector->credentials),
            'slack'          => $this->slack->withCredentials($connector->credentials),
            'notion'         => $this->notion->withCredentials($connector->credentials),
            'airtable'       => $this->airtable->withCredentials($connector->credentials),
            'hubspot'        => $this->hubspot->withCredentials($connector->credentials),
            'github'         => $this->github->withCredentials($connector->credentials),
            'trello'         => $this->trello->withCredentials($connector->credentials),
            'mailchimp'      => $this->mailchimp->withCredentials($connector->credentials),
            'http_api'       => $this->httpApi->withCredentials($connector->credentials),
            default          => throw new \InvalidArgumentException("Непознат конектор: {$connector->connector_type}"),
        };
    }

    /**
     * Изпълнява tool call + логва в connector_tool_logs.
     * Агентите НЕ извикват resolve() директно — минават само през callTool().
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

## 5. Топ 11 Конектора — Имплементационни приоритети

| # | Connector | Auth | Клас | Приоритет |
|---|-----------|------|------|-----------|
| 1 | **Gmail** | OAuth2 | `GmailConnector` | Задължително първо |
| 2 | **Google Sheets** | OAuth2 | `GoogleSheetsConnector` | Задължително |
| 3 | **Google Drive** | OAuth2 | `GoogleDriveConnector` | Задължително |
| 4 | **Slack** | OAuth2 / Webhook | `SlackConnector` | Задължително |
| 5 | **Notion** | OAuth2 / API key | `NotionConnector` | Високо |
| 6 | **Airtable** | API key | `AirtableConnector` | Високо |
| 7 | **HubSpot** | OAuth2 | `HubSpotConnector` | Средно |
| 8 | **GitHub** | API key (PAT) | `GitHubConnector` | Средно |
| 9 | **Trello** | API key + token | `TrelloConnector` | Средно |
| 10 | **Mailchimp** | API key | `MailchimpConnector` | Средно |
| 11 | **HTTP API** | Bearer/Basic/API key | `HttpApiConnector` | Задължително (generic fallback) |

### 5.1 Tools по конектор

#### Gmail (`GmailConnector`)
- `gmail.list_emails` — последни N имейли с филтри (от, тема, дата)
- `gmail.get_email` — пълно съдържание по message_id
- `gmail.send_email` — изпраща имейл (to, subject, body, attachments)
- `gmail.create_draft` — създава чернова (за human approval преди изпращане)
- `gmail.reply` — отговаря на нишка
- `gmail.label_email` — маркира с label

#### Google Sheets (`GoogleSheetsConnector`)
- `sheets.read_range` — чете клетки (A1:D100)
- `sheets.append_row` — добавя ред в края
- `sheets.update_range` — обновява клетки
- `sheets.create_sheet` — нов sheet в spreadsheet
- `sheets.get_values` — всички данни от sheet

#### Google Drive (`GoogleDriveConnector`)
- `drive.list_files` — файлове в папка (с филтър по тип, дата)
- `drive.get_file_content` — съдържание на файл (text/pdf)
- `drive.upload_file` — качва файл в папка
- `drive.create_doc` — нов Google Doc с текст

#### Slack (`SlackConnector`)
- `slack.post_message` — публикува в канал
- `slack.list_messages` — последни N съобщения от канал
- `slack.create_thread` — отговор в нишка
- `slack.upload_file` — прикачва файл
- `slack.list_channels` — наличните канали

#### Notion (`NotionConnector`)
- `notion.query_database` — query на database с филтри
- `notion.create_page` — нова страница в database
- `notion.update_page` — обновява page properties
- `notion.get_page_content` — чете текстово съдържание на страница

#### Airtable (`AirtableConnector`)
- `airtable.list_records` — записи с филтри и сортиране
- `airtable.create_record` — нов запис
- `airtable.update_record` — обновява поле
- `airtable.find_records` — търси по стойност на поле

#### HTTP API (`HttpApiConnector`) — generic
- `http_api.get` — GET заявка към произволен endpoint
- `http_api.post` — POST заявка с JSON body
- `http_api.put` / `http_api.patch` — update заявки
- SSRF guard: само https://, whitelist по домейн (config)

---

## 6. Нов Node тип: `mcp_action`

### 6.1 В `config/agent_types.php`

```php
'mcp_action' => [
    'label'       => 'MCP Действие',
    'description' => 'Изпълнява конкретно действие в свързана система (Gmail, Sheets, Slack…)',
    'output_role' => 'action',   // нов output_role — не е 'body', не е 'transform'
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
- `{{flow.input.X}}` — от входния prompt/variables на run-а
- `{{agent.NODE_KEY.output}}` — изход от предишен агент (от `context['nodes']`)
- `{{connector.setting.X}}` — от `CompanyConnector.settings` (напр. default folder)
- `{{flow.setting.X}}` — от `FlowNode.config` (flow-specific настройки)

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

### 6.5 `requires_approval` — КРИТИЧНО

Всеки `mcp_action` node с деструктивни или изходящи действия **трябва** да мине
през `human_approval` node преди него в DAG-а. Системата го налага на две нива:

**Ниво 1 — Планерът:** В `FlowPlannerService::designPipeline()` се добавя правило:

```php
private const MCP_WRITE_TOOLS = [
    'gmail.send_email', 'gmail.reply', 'slack.post_message',
    'sheets.append_row', 'sheets.update_range', 'drive.upload_file',
    'drive.create_doc', 'notion.create_page', 'notion.update_page',
    'airtable.create_record', 'airtable.update_record',
    'http_api.post', 'http_api.put', 'http_api.patch',
    // НЕ включва: list_, read_, get_ — те са read-only
];
```

Ако планерът постави `mcp_action` node с write tool, автоматично вмъква
`human_approval` node като негов predecessor в DAG-а.

**Ниво 2 — Executor:** `McpActionAgent::run()` проверява `requires_approval` flag.
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
- gmail.send_email (connector_id:3, "Фирмен Gmail") — изпраща имейл; ИЗИСКВА human_approval преди него
- slack.post_message (connector_id:5, "Slack Workspace") — публикува в Slack канал; ИЗИСКВА human_approval
- sheets.append_row (connector_id:7, "Продажби Sheet") — добавя ред; ИЗИСКВА human_approval
- gmail.list_emails (connector_id:3) — чете входяща поща; НЕ изисква approval
- sheets.read_range (connector_id:7) — чете данни; НЕ изисква approval

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

### 8.2 В Builder-а — MCP Node конфигурационен панел

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

## 9. Company Connectors страница (UI)

**Route:** `companies/{company}/connectors`
**Controller:** `CompanyConnectorController`

### 9.1 Списък с конектори

```
┌─────────────────────────────────────────────────────────────┐
│  Свързани системи за "Спортен Център"    [+ Свържи нова]     │
├─────────────────────────────────────────────────────────────┤
│  📧 Gmail              igroup7@gmail.com   🟢 Активен  [⚙️] │
│  📊 Google Sheets      Продажби 2026       🟢 Активен  [⚙️] │
│  💬 Slack              #marketing          🟡 Изтекъл  [↺]  │
│  📁 Google Drive       Доклади/            🟢 Активен  [⚙️] │
└─────────────────────────────────────────────────────────────┘
```

**Статуси:**
- 🟢 Активен — credentials са валидни
- 🟡 Изтекъл — OAuth token трябва refresh (бутон „Обнови")
- 🔴 Грешка — connection тест е fail (бутон „Провери")

### 9.2 OAuth flow за Google (Gmail, Sheets, Drive)

```
Company Settings → [+ Свържи Gmail]
    → Redirect към Google OAuth consent screen
    → Google callback: /connectors/google/callback?code=…&state={company_id}
    → Записва access_token + refresh_token (encrypted) в company_connectors
    → Redirect обратно към Company Settings с ✅
```

**Route:**
```php
Route::get ('companies/{company}/connectors',          [CompanyConnectorController::class, 'index'])->name('connectors.index');
Route::post('companies/{company}/connectors',          [CompanyConnectorController::class, 'store'])->name('connectors.store');
Route::get ('companies/{company}/connectors/{c}/edit', [CompanyConnectorController::class, 'edit'])->name('connectors.edit');
Route::put ('companies/{company}/connectors/{c}',      [CompanyConnectorController::class, 'update'])->name('connectors.update');
Route::delete('companies/{company}/connectors/{c}',   [CompanyConnectorController::class, 'destroy'])->name('connectors.destroy');
Route::post('companies/{company}/connectors/{c}/test', [CompanyConnectorController::class, 'test'])->name('connectors.test');

// OAuth callbacks
Route::get('oauth/google/redirect',  [OAuthController::class, 'googleRedirect'])->name('oauth.google.redirect');
Route::get('oauth/google/callback',  [OAuthController::class, 'googleCallback'])->name('oauth.google.callback');
Route::get('oauth/slack/redirect',   [OAuthController::class, 'slackRedirect'])->name('oauth.slack.redirect');
Route::get('oauth/slack/callback',   [OAuthController::class, 'slackCallback'])->name('oauth.slack.callback');
```

---

## 10. `AgentFactory` — нов тип агент

```php
// В app/Agents/AgentFactory.php — в match():
'mcp_action' => app(McpActionAgent::class),
```

`McpActionAgent` се регистрира в `AppServiceProvider` като singleton с инжектирани
зависимости `McpClientService` + `McpParamResolver`.

---

## 11. Промени в `NodeExecutorService`

В `executeNode()`, след взимането на FlowNode:

```php
// Специален path за mcp_action — не минава през AgentLoop или OllamaService
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

### 12.1 `HttpApiConnector` whitelist

```php
// config/mcp.php
'http_api' => [
    'allowed_schemes' => ['https'],
    'blocked_hosts'   => ['localhost', '127.0.0.1', '0.0.0.0', '::1'],
    'blocked_cidrs'   => ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'],
    'allowed_domains' => [],  // [] = всички external домейни; или whitelist
    'max_response_size_kb' => 512,
    'timeout_seconds' => 15,
],
```

### 12.2 Credential isolation

- Credentials НИКОГА не влизат в промптите на агентите.
- `McpParamResolver` блокира `{{credentials.*}}` и `{{connector.credentials.*}}` в tool_params.
- Логовете в `connector_tool_logs` пазят само `sanitizeParams()` — без tokens.

### 12.3 Scope enforcement

`GmailConnector` с scope `gmail.readonly` → хвърля `ScopeException` ако се опита да извика `gmail.send_email`.
Scope-овете се записват при OAuth и се проверяват при всеки `callTool()`.

---

## 13. Ред на имплементация

| Стъпка | Действие | Файл | Приоритет |
|--------|----------|------|-----------|
| 1 | Migrations | `company_connectors`, `connector_tool_logs` | Задължително |
| 2 | Models | `CompanyConnector`, `ConnectorToolLog` | Задължително |
| 3 | Interface + Result | `McpConnectorInterface`, `McpToolResult` | Задължително |
| 4 | Implement Gmail connector | `GmailConnector` — само list + get (read-only) | Задължително |
| 5 | `McpClientService` — routing + logging | `McpClientService` | Задължително |
| 6 | `McpParamResolver` — variable interpolation | `McpParamResolver` | Задължително |
| 7 | `McpActionAgent` | `app/Agents/McpActionAgent.php` | Задължително |
| 8 | `AgentFactory` добавяне | `AgentFactory` | Задължително |
| 9 | `NodeExecutorService` промяна | добавяне на `mcp_action` path | Задължително |
| 10 | SSRF guard в `HttpApiConnector` | `HttpApiConnector` | Задължително |
| 11 | Company Connectors UI + routes | `CompanyConnectorController` + views | Задължително |
| 12 | OAuth flow за Google | `OAuthController` | Задължително |
| 13 | Gmail write tools (send, draft) | `GmailConnector` — write actions | Задължително |
| 14 | Planner integration — динамичен registry | `FlowPlannerService` | Задължително |
| 15 | Builder MCP node panel | `builder.blade.php` | Задължително |
| 16 | Google Sheets connector | `GoogleSheetsConnector` | Високо |
| 17 | Slack connector | `SlackConnector` | Високо |
| 18 | Google Drive connector | `GoogleDriveConnector` | Високо |
| 19 | Notion, Airtable | отделни connectors | Средно |
| 20 | HubSpot, GitHub, Trello, Mailchimp | отделни connectors | По-късно |
| 21 | HTTP API generic connector | `HttpApiConnector` | По-късно |
| 22 | Approval enforcement в executor | `requires_approval` gate | Задължително (стъпка 9 зависи) |
| 23 | OAuth Slack flow | `OAuthController` Slack | Средно |

**ENV промени:**
```env
GOOGLE_OAUTH_CLIENT_ID=...
GOOGLE_OAUTH_CLIENT_SECRET=...
GOOGLE_OAUTH_REDIRECT_URI=https://yourdomain.com/oauth/google/callback

SLACK_OAUTH_CLIENT_ID=...
SLACK_OAUTH_CLIENT_SECRET=...
SLACK_OAUTH_REDIRECT_URI=https://yourdomain.com/oauth/slack/callback

MCP_HTTP_API_TIMEOUT=15
```

---

## 14. Какво НЕ правим

- **Не пазим connector credentials в `flow_nodes.config`** — само `connector_id`.
  Flow nodes никога не знаят tokens — само `McpClientService` ги вижда.
- **Не позволяваме `mcp_action` nodes без predecessor** — всеки write action
  минава или през `human_approval` node, или изрично е маркиран `requires_approval: false`
  от потребителя (не от планера).
- **Не рестартираме целия flow при MCP грешка** — `mcp_action` nodes подлежат
  на `max_retries` като нормалните агенти (default 2); при 3 провала → node fail,
  `best_effort` policy продължава останалите.
- **Не логваме финалния изход на write operations** — `result_summary` е само
  „Изпратен имейл до X", без съдържанието на имейла.
