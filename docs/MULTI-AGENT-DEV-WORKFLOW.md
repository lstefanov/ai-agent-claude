# Multi-agent dev workflow за FlowAI

Как да разработваш FlowAI с екип от AI агенти, в който **Claude Code е диригентът**, а
Codex / Cursor / Antigravity / локален Ollama влизат като cross-vendor проверки. Това
ръководство покрива четирите въпроса: как се конфигурира, как се стартира задача, как се
следи изпълнението и как се следи разходът по всички модели.

> Важно разграничение: тук става дума за **разработка НА** FlowAI (агенти, които пишат
> код по проекта). Това е различно от агентите, които FlowAI изпълнява по време на работа
> (`app/Agents/`). Двете не се бъркат — dev-агентите живеят в `.claude/`.

---

## 1. Какво е настроено (вече създадено в проекта)

```
.claude/
  agents/            # 8-те субагента (екипът)
    planner.md            (opus)    проучва кода -> PLAN.md
    plan-reviewer.md      (sonnet)  критикува плана
    implementer.md        (sonnet)  пише кода по PLAN.md
    code-reviewer.md      (sonnet)  ревю на diff-а
    security-reviewer.md  (opus)    security одит
    external-reviewer.md  (sonnet)  второ мнение от друг vendor
    qa-checker.md         (sonnet)  верификация БЕЗ тестове
    doc-writer.md         (haiku)   обновява docs
  commands/
    feature.md         # /feature — целият конвейер с една команда
    review-diff.md     # /review-diff — бързо ревю на текущия diff
  settings.json        # hooks (Pint, блок на commit/push и тестове)
scripts/
  ai-review.sh         # cross-vendor wrapper (codex/cursor/agy/ollama)
  claude-local.sh      # пуска Claude Code върху локален Ollama (изолиран env)
  claude-pint.sh       # hook: авто-Pint на редактирани .php
  claude-guard.sh      # hook: блокира commit/push и тестове
.worktreeinclude       # копира .env в новите worktrees
```

`settings.local.json` (impeccable UI hook) **не е пипан** — моите hooks са в отделния
`settings.json` и двата се сливат от Claude Code.

---

## 2. Еднократна подготовка (инсталация)

Диригентът:

```bash
curl -fsSL https://claude.ai/install.sh | bash    # native installer
claude --version && claude doctor
cd ~/Sites/localhost/ai-agent-claude
claude            # първото пускане -> OAuth login с Max акаунта
```

Вътре в сесия: `/status` трябва да показва `CLAUDE_CODE_OAUTH_TOKEN` (върви на абонамента,
не на API). Ако в средата има `ANTHROPIC_API_KEY`, той има приоритет и billing-ът минава на
API — махни го: `unset ANTHROPIC_API_KEY` и провери пак.

Cross-vendor reviewer-и (инсталирай тези, които ще ползваш — wrapper-ът ги открива сам):

```bash
# Codex (ChatGPT абонамент)         -> codex exec
# Cursor CLI                        -> cursor-agent -p
# Antigravity CLI (Google)          -> agy -p
curl -fsSL https://antigravity.google/cli/install.sh | bash

# Локален Ollama review (безплатно)
brew install llm && llm install llm-ollama
export OLLAMA_HOST=http://192.168.0.19:11434     # твоят Ollama хост (от .env)
ollama pull qwen3-coder:30b                       # по-добър за код от планерния qwen3:14b
```

### Критичният капан (прочети това)
Задаването на `ANTHROPIC_BASE_URL` / `ANTHROPIC_AUTH_TOKEN` **чупи Max OAuth** — Claude Code
тихо излиза от абонамента и хвърля 401. Затова:

- **Никога** не слагай тези променливи в `~/.zshrc`.
- За локален Ollama като главен модел ползвай `scripts/claude-local.sh` (пуска го в изолиран
  env, в собствен терминал) — не глобално.
- `scripts/ai-review.sh` ползва `codex` / `cursor-agent` / `agy` / `llm`, които **не докосват**
  `ANTHROPIC_*`, така че главната ти Claude Code сесия остава на Max без риск.

---

## 3. Как се конфигурират агентите

Всеки агент е Markdown файл с YAML frontmatter: `name`, `description` (това е тригерът —
богат на ключови думи), `tools` (allowlist) и `model`. Главната сесия делегира автоматично
по `description`, или явно: `> Use the planner subagent on "..."`.

- Виж/редактирай интерактивно: `/agents`
- Или редактирай файла в `.claude/agents/` директно.
- Глобален таван за разход: `export CLAUDE_CODE_SUBAGENT_MODEL=sonnet` форсира всички
  субагенти на по-евтин модел.

Разпределението модел↔роля (скъпото разсъждение само където трябва):

| Агент | Модел | Защо |
|---|---|---|
| planner | **opus** | дълбоко разсъждение, най-висок залог |
| security-reviewer | **opus** | пропуск тук е скъп |
| plan-reviewer, implementer, code-reviewer, qa-checker, external-reviewer | **sonnet** | обемната работа |
| doc-writer | **haiku** | евтина черна работа |
| cross-vendor ревю | Codex / Cursor / Antigravity / **Ollama (безплатно)** | различен vendor лови други грешки |

Адаптации спрямо правилата на FlowAI (вградени в агентите):
- **Без тестове** — `qa-checker` верифицира с Pint + `php -l` + artisan проверки + ръчен
  чеклист, никога `php artisan test`. `claude-guard.sh` блокира всяка тестова команда.
- **Без legacy/back-compat**, **LLM само през services**, **планерът предлага—кодът гарантира**,
  **GraphNormalizer** за Drawflow промени, **дизайн-токени** — всичко това е в промптовете.

---

## 4. Как се стартира задача

В Laravel проекта, в Claude Code сесия:

```
/feature добави endpoint за управление на Task модел с валидация и документация
```

Това пуска целия конвейер: planner → plan-reviewer → (cross-vendor критика) → implementer →
code-reviewer ∥ security-reviewer ∥ external-reviewer → qa-checker → doc-writer → показва
`git diff --stat` и **спира преди commit**.

Бързо ревю на нещо, което вече си написал:

```
/review-diff
```

Паралелна работа без агентите да си пречат — **git worktree** (всеки агент в собствена
директория/branch, споделят един `.git`):

```bash
claude -w task-api          # създава .claude/worktrees/task-api на нов branch
# във втори терминал, паралелно:
claude -w bugfix-auth
```

Тъй като стекът е **MySQL** (`flowai`), за паралелни worktrees дай на всеки **отделна база**
(напр. `flowai_task_api`) и оправи `DB_DATABASE` в неговия `.env`, за да не се застъпват.
`.worktreeinclude` копира `.env` автоматично; после в worktree-а: `composer install`.

Локален модел за евтина черна работа (в собствен терминал):

```bash
scripts/claude-local.sh        # Claude Code върху qwen3-coder:30b през Ollama
```

---

## 5. Как се следи изпълнението

- **На живо в сесията:** Claude Code показва всеки субагент кога стартира и връща резюме.
  Файловете-handoff се появяват в репото: `PLAN.md`, после реалните промени.
- **`/agents`** — кои агенти има и кой работи.
- **`claude --verbose`** или флагът за повече детайл — показва пълните tool-calls на субагентите.
- **`git diff --stat` / `git status`** — какво е пипнато (конвейерът завършва точно с това и спира).
- **Hooks log:** Pint форматира всеки `.php` веднага щом бъде записан; опит за `git commit`/
  `push` или тестова команда се блокира видимо със съобщение.
- **Runtime на самото приложение** (различно от dev-конвейера): когато тестваш flow в FlowAI,
  изпълнението се гледа в **Horizon** (`/horizon`) и per-run лога `storage/logs/run-{id}.log`.
  За това трябва `composer dev` (server + Horizon + vite) да върви.

---

## 6. Как се следи разходът по всички модели

Подходът тук е **вградените команди** на всеки инструмент (без външна обсървабилност):

- **Claude Code (Max):** `/cost` показва разхода за текущата сесия; `/usage` и `/status` —
  колко от 5-часовия прозорец и седмичните лимити са изхабени. Usage-ът е **споделен** между
  Claude chat, Claude Code и Cowork — един pool.
- **От 15 юни 2026:** non-interactive употреба (`claude -p`, Agent SDK) черпи от **отделен**
  месечен кредит, не от абонаментния pool. Затова дръж оркестрацията **интерактивна**
  (`/feature` в сесия), не през `claude -p`.
- **Codex:** при ChatGPT-plan auth `codex exec` черпи от същия прозорец като интерактивния
  Codex; `codex` показва статус/usage. Внимавай: с API key минава на usage-based billing.
- **Cursor:** разходът се вижда в Cursor dashboard-а (Settings → Usage).
- **Antigravity:** в неговото табло/акаунт.
- **Ollama:** локален, **нулев** marginal разход — затова бутни паралелните ревюта и черновите
  натам (`scripts/ai-review.sh` с `REVIEWER=ollama`).

Практически таван на разхода:
- Opus само за `planner` и `security-reviewer`; Sonnet за обема; Haiku за docs; Ollama за
  паралелна черна работа.
- При тежки паралелни runs: `export CLAUDE_CODE_SUBAGENT_MODEL=sonnet` сваля всички субагенти.
- Удариш ли седмичния лимит — изнеси повече ревюта на Ollama/Codex/Cursor.

---

## 7. Какво пазят hooks (детерминистично, не „по преценка")

- **PostToolUse** (`claude-pint.sh`): авто-Pint на всеки записан `.php`.
- **PreToolUse** (`claude-guard.sh`): блокира `git commit`/`git push` (работата остава локална
  до твоето одобрение) и всяка тестова команда (`php artisan test`, `phpunit`, `pest`,
  `composer test`) — заради правилото „без тестове".

Тестване на guard-а:
```bash
echo '{"tool_input":{"command":"git push"}}'        | bash scripts/claude-guard.sh; echo "exit=$?"  # 2 = блокирано
echo '{"tool_input":{"command":"php artisan test"}}' | bash scripts/claude-guard.sh; echo "exit=$?"  # 2 = блокирано
echo '{"tool_input":{"command":"php artisan migrate"}}' | bash scripts/claude-guard.sh; echo "exit=$?"  # 0 = ОК
```

---

## 8. Cheat-sheet

```
claude                       # старт на диригента (Max OAuth)
/feature <описание>          # целият конвейер за нова промяна
/fix <бъг>                   # по-лек конвейер за поправка на бъг
/review-diff                 # бързо multi-perspective ревю на diff-а
/agents                      # управление на субагентите
/model opusplan              # Opus за план, после Sonnet за писане
/cost  /usage  /status       # разход и лимити
claude -w <name>             # изолиран worktree за паралелна работа
scripts/ai-review.sh review  # cross-vendor ревю на текущия diff
scripts/ai-review.sh --all review   # всички налични vendor-и
REVIEWER=ollama scripts/ai-review.sh review   # само локален (безплатно)
scripts/claude-local.sh      # Claude Code върху локален Ollama (отделен терминал)
```

Препоръчан ред за първи опит: `claude` → `/feature малка промяна` → гледай конвейера →
прегледай `git diff` → commit-ваш сам.
