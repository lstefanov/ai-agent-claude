# Competitor Pricing Daily Report Flow

**Date:** 2026-05-30  
**Status:** Approved

## Context

The user wants a Flow that runs every morning, searches the internet for competitor service pricing in their business sector, and delivers a structured report. The report should reach them both inside the FlowAI app (as a FlowRun result) and via email. The email recipient address is stored dynamically inside the Flow description (e.g. "Изпрати репорта на boss@company.bg") and extracted at runtime using an LLM call — no hardcoded config.

The existing infrastructure already covers scheduling (cron on Flow model), web search (ResearcherAgent + BraveSearchTool), and output storage (AgentRun). The missing piece is email delivery, which we implement as a new `EmailAgent` — visible in the agent pipeline like any other agent, so the full process is auditable end-to-end.

## Architecture

The Flow uses a four-agent pipeline:

```
ResearcherAgent → AnalyzerAgent → ContentAgent → EmailAgent
```

| Agent | Type | Output Role | Purpose |
|---|---|---|---|
| ResearcherAgent | researcher | hidden | Brave Search: finds competitor prices in the company's industry |
| AnalyzerAgent | analyzer | hidden | Identifies trends, detects price changes vs. prior context |
| ContentAgent | content | body | Writes structured report: prices, trends, AI summary |
| EmailAgent | email | appendix | Extracts recipient from Flow description via LLM, sends email |

The Flow is created manually by the user for Company #1 via the existing UI. The AI agent generator can bootstrap the first three agents from a description like "Ежедневен мониторинг на конкурентни цени". EmailAgent is added manually afterward as the final step.

## EmailAgent Design

**File:** `app/Agents/EmailAgent.php`

`EmailAgent` extends `BaseAgent`. Its `execute($input, $context)` method:

1. Reads `$this->agent->flow->description`
2. Makes a minimal LLM call (cheapest/fastest model) with prompt:  
   `"Extract only the email address from the following text, return nothing else: {description}"`
3. Validates the extracted string is a valid email (regex check)
4. Uses `$input` (the accumulated output passed to every agent by `FlowExecutorService`) as the report body — previous agents' outputs are already concatenated there
5. Dispatches `FlowRunReport` Mailable to the extracted address
6. Returns a confirmation string: `"✓ Репортът е изпратен до {email}"` — this appears in the run view under the appendix section

If no email is found in the description, the agent returns a soft warning and does not fail the flow: `"⚠ Не е намерен имейл адрес в описанието на Flow-а."`

## New Files

### `app/Agents/EmailAgent.php`
Extends `BaseAgent`. Overrides `execute()` — does not call `parent::chat()` for the main output, only for the email extraction LLM call.

### `app/Mail/FlowRunReport.php`
Laravel Mailable. Receives: `string $content` (accumulated agent output), `string $flowName`, `string $recipientEmail`. Subject: `"[FlowAI] {flowName} — {date}"`.

### `resources/views/mail/flow-run-report.blade.php`
Clean HTML email template. Shows:
- Flow name and date in header
- Report content (markdown rendered to HTML)
- Link back to the run in FlowAI: `/runs/{flowRun->id}`

## Modified Files

### `app/Agents/AgentFactory.php`
Add `case 'email': return new EmailAgent(...)` in the factory switch/match.

### `app/Models/Agent.php`
Add `'email'` to the `$type` cast options / validation rules if an enum or const list exists.

### `resources/views/agents/edit.blade.php` (or equivalent)
Add `email` as a selectable agent type in the dropdown so users can add EmailAgent to any Flow.

## Email Infrastructure

Laravel Mail is already configured (`config/mail.php`). For local dev it logs to file (`MAIL_MAILER=log`). For production, set in `.env`:

```
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=noreply@flowai.local
MAIL_FROM_NAME="FlowAI"
```

## Flow Description Convention

The user places the recipient address anywhere in the Flow description using natural language:

> "Ежедневен мониторинг на конкурентни цени в IT сектора. Изпрати репорта на boss@company.bg."

The EmailAgent LLM extraction handles varied phrasing. Regex fallback validates the result.

## Verification

1. Create a test Flow with description containing a valid email (use `MAIL_MAILER=log` locally)
2. Add four agents in order: researcher → analyzer → content → email
3. Trigger manually via "Run now"
4. Confirm all four AgentRuns appear in the run view
5. EmailAgent appendix shows "✓ Репортът е изпратен до ..."
6. Check `storage/logs/laravel.log` for the logged email content (local dev)
7. Set `schedule_cron` to desired morning time via the existing schedule picker
8. Confirm `flows:run-scheduled` dispatches the job at that time
