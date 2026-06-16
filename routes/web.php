<?php

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AgentTemplateController as AdminAgentTemplateController;
use App\Http\Controllers\Admin\CostController as AdminCostController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\AgentTemplateController;
use App\Http\Controllers\CompanyConnectorController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanyKnowledgeController;
use App\Http\Controllers\FlowAssistantController;
use App\Http\Controllers\FlowBuilderController;
use App\Http\Controllers\FlowController;
use App\Http\Controllers\FlowEvalController;
use App\Http\Controllers\FlowGraphController;
use App\Http\Controllers\FlowKnowledgeController;
use App\Http\Controllers\FlowMemoryController;
use App\Http\Controllers\FlowRunController;
use App\Http\Controllers\FlowVersionController;
use App\Http\Controllers\KnowledgeChatController;
use App\Http\Controllers\LlmModelController;
use App\Http\Controllers\OAuthController;
use App\Http\Controllers\PlanAbController;
use Illuminate\Support\Facades\Route;

// Цялото админ приложение се ограничава до основния домейн, КОГАТО е зададен
// APP_DOMAIN (иначе config('app.domain') е null → Route::domain(null) е no-op и
// маршрутите остават глобални — днешното поведение). Изолацията е нужна само в
// поддомейн режим, за да не „засенчват" тези routes клиентския портал на
// clients.<domain> (напр. `/` да не отваря админ home-а на клиентския поддомейн).
Route::domain(config('app.domain'))->group(function () {

// Companies
Route::resource('companies', CompanyController::class);

// Flows — nested under company for create, shallow for the rest
Route::resource('companies.flows', FlowController::class)->shallow();

// Archive / unarchive flows
Route::post('flows/{flow}/archive', [FlowController::class, 'archive'])->name('flows.archive');
Route::post('flows/{flow}/unarchive', [FlowController::class, 'unarchive'])->name('flows.unarchive');

// Flow-level settings (result delivery)
Route::post('flows/{flow}/settings', [FlowController::class, 'updateSettings'])->name('flows.settings.update');

// Webhook secret management
Route::post('flows/{flow}/webhook/generate', [FlowController::class, 'generateWebhookSecret'])->name('flows.webhook.generate');
Route::post('flows/{flow}/webhook/revoke', [FlowController::class, 'revokeWebhookSecret'])->name('flows.webhook.revoke');

// AJAX: generate agents — starts background job, returns token
Route::post('flows/generate-agents', [FlowController::class, 'generateAgents'])->name('flows.generate-agents');
// AJAX: improve flow description with AI
Route::post('flows/improve-description', [FlowController::class, 'improveDescription'])->name('flows.improve-description');
// AJAX: poll generation status by token
Route::get('flows/generation-status/{token}', [FlowController::class, 'generationStatus'])->name('flows.generation-status');
// AJAX: full agent-generation logs for a flow's company
Route::get('flows/{flow}/generation-logs', [FlowController::class, 'generationLogs'])->name('flows.generation-logs');

// Graph builder (Drawflow)
Route::get('flows/{flow}/builder', [FlowBuilderController::class, 'show'])->name('flows.builder');
Route::post('flows/{flow}/graph', [FlowGraphController::class, 'store'])->name('flows.graph.store');
Route::post('flows/{flow}/graph/validate', [FlowGraphController::class, 'validateGraph'])->name('flows.graph.validate');
Route::post('flows/{flow}/graph/relevel', [FlowGraphController::class, 'relevel'])->name('flows.graph.relevel');

// Builder Copilot — чат асистентът в builder-а (фонов процес + token polling)
Route::post('flows/{flow}/assistant', [FlowAssistantController::class, 'send'])->name('flows.assistant.send');
Route::get('flows/assistant-status/{token}', [FlowAssistantController::class, 'status'])->name('flows.assistant.status');
Route::get('flows/{flow}/assistant/history', [FlowAssistantController::class, 'history'])->name('flows.assistant.history');
Route::get('flows/{flow}/assistant/notes', [FlowAssistantController::class, 'notes'])->name('flows.assistant.notes');
Route::delete('assistant-notes/{note}', [FlowAssistantController::class, 'destroyNote'])->name('flows.assistant.notes.destroy');

// Graph versions ("шаблони") на flow — builder save-диалог, A/B "Запази" и
// dashboard секцията "Шаблони".
Route::scopeBindings()->group(function () {
    Route::post('flows/{flow}/versions', [FlowVersionController::class, 'store'])->name('flows.versions.store');
    Route::post('flows/{flow}/versions/from-plan', [FlowVersionController::class, 'storeFromPlan'])->name('flows.versions.from-plan');
    Route::put('flows/{flow}/versions/{version}', [FlowVersionController::class, 'update'])->name('flows.versions.update');
    Route::post('flows/{flow}/versions/{version}/activate', [FlowVersionController::class, 'activate'])->name('flows.versions.activate');
    Route::post('flows/{flow}/versions/{version}/duplicate', [FlowVersionController::class, 'duplicate'])->name('flows.versions.duplicate');
    Route::delete('flows/{flow}/versions/{version}', [FlowVersionController::class, 'destroy'])->name('flows.versions.destroy');
});

// Фаза 4: A/B сравнение на planner провайдърите (вкл. хибридни per-phase комбинации)
Route::get('flows/{flow}/plan-ab', [PlanAbController::class, 'show'])->name('flows.plan-ab');
Route::post('flows/{flow}/plan-ab/start', [PlanAbController::class, 'start'])->name('flows.plan-ab.start');
Route::get('plan-ab-status/{token}', [PlanAbController::class, 'status'])->name('flows.plan-ab.status');

// Памет на flow-а — builder панел (преглед/toggle/изчистване)
Route::get('flows/{flow}/memory', [FlowMemoryController::class, 'show'])->name('flows.memory.show');
Route::post('flows/{flow}/memory/toggle', [FlowMemoryController::class, 'toggle'])->name('flows.memory.toggle');
Route::delete('flows/{flow}/memory', [FlowMemoryController::class, 'clear'])->name('flows.memory.clear');

// Eval Suite — golden test cases + матрица цена↔качество per flow
Route::get('eval-run-status/{token}', [FlowEvalController::class, 'status'])->name('flows.eval.status');
Route::get('flows/{flow}/eval', [FlowEvalController::class, 'index'])->name('flows.eval.index');
Route::get('flows/{flow}/eval/create', [FlowEvalController::class, 'create'])->name('flows.eval.create');
Route::post('flows/{flow}/eval', [FlowEvalController::class, 'store'])->name('flows.eval.store');
Route::post('flows/{flow}/eval/run', [FlowEvalController::class, 'run'])->name('flows.eval.run');
Route::get('flows/{flow}/eval/results', [FlowEvalController::class, 'results'])->name('flows.eval.results');
Route::get('flows/{flow}/eval/runs/{evalRun}', [FlowEvalController::class, 'runDetail'])->name('flows.eval.run-detail');
Route::get('flows/{flow}/eval/{case}/edit', [FlowEvalController::class, 'edit'])->name('flows.eval.edit');
Route::put('flows/{flow}/eval/{case}', [FlowEvalController::class, 'update'])->name('flows.eval.update');
Route::delete('flows/{flow}/eval/{case}', [FlowEvalController::class, 'destroy'])->name('flows.eval.destroy');

// База знания на фирмата v2 — страница + JSON endpoints (Alpine polling)
Route::prefix('companies/{company}/knowledge')->name('companies.knowledge.')->group(function () {
    Route::get('/', [CompanyKnowledgeController::class, 'index'])->name('index');
    Route::get('data', [CompanyKnowledgeController::class, 'data'])->name('data');
    Route::post('toggle', [CompanyKnowledgeController::class, 'toggle'])->name('toggle');
    Route::post('folders', [CompanyKnowledgeController::class, 'storeFolder'])->name('folders.store');
    Route::patch('folders/{folder}', [CompanyKnowledgeController::class, 'renameFolder'])->name('folders.rename');
    Route::delete('folders/{folder}', [CompanyKnowledgeController::class, 'destroyFolder'])->name('folders.destroy');
    // Пагинирани списъци (server-side search/sort/page)
    Route::get('resources', [CompanyKnowledgeController::class, 'listResources'])->name('resources.list');
    Route::get('facts', [CompanyKnowledgeController::class, 'listFacts'])->name('facts.list');
    Route::get('events', [CompanyKnowledgeController::class, 'listEvents'])->name('events.list');
    Route::get('gaps', [CompanyKnowledgeController::class, 'listGaps'])->name('gaps.list');
    // Ресурси: файлове/снимки, бележки, URL-и
    Route::post('uploads', [CompanyKnowledgeController::class, 'upload'])->name('uploads.store');
    Route::post('notes', [CompanyKnowledgeController::class, 'storeNote'])->name('notes.store');
    Route::patch('notes/{resource}', [CompanyKnowledgeController::class, 'updateNote'])->name('notes.update');
    Route::post('urls', [CompanyKnowledgeController::class, 'storeUrl'])->name('urls.store');
    Route::delete('resources/{resource}', [CompanyKnowledgeController::class, 'destroyResource'])->name('resources.destroy');
    Route::post('resources/{resource}/reingest', [CompanyKnowledgeController::class, 'reingest'])->name('resources.reingest');
    Route::get('resources/{resource}/download', [CompanyKnowledgeController::class, 'download'])->name('resources.download');
    Route::get('resources/{resource}/digest', [CompanyKnowledgeController::class, 'digest'])->name('resources.digest');
    Route::get('resources/{resource}/pages', [CompanyKnowledgeController::class, 'pages'])->name('resources.pages');
    Route::get('pages/{page}/digest', [CompanyKnowledgeController::class, 'pageDigest'])->name('pages.digest');
    Route::delete('pages/{page}', [CompanyKnowledgeController::class, 'destroyPage'])->name('pages.destroy');
    Route::delete('facts/{fact}', [CompanyKnowledgeController::class, 'destroyFact'])->name('facts.destroy');
    Route::delete('gaps', [CompanyKnowledgeController::class, 'clearGaps'])->name('gaps.clear');

    Route::get('conflicts', [CompanyKnowledgeController::class, 'listConflicts'])->name('conflicts.list');
    Route::post('conflicts/scan', [CompanyKnowledgeController::class, 'scanConflicts'])->name('conflicts.scan');
    Route::post('conflicts/{conflict}/resolve', [CompanyKnowledgeController::class, 'resolveConflict'])->name('conflicts.resolve');
    Route::post('conflicts/{conflict}/ignore', [CompanyKnowledgeController::class, 'ignoreConflict'])->name('conflicts.ignore');
    // Чат "Тествай знанията" (queue + token poll, като Builder Copilot)
    Route::post('chat', [KnowledgeChatController::class, 'send'])->name('chat.send');
    Route::get('chat/history', [KnowledgeChatController::class, 'history'])->name('chat.history');
    Route::get('chat/sessions', [KnowledgeChatController::class, 'sessions'])->name('chat.sessions');
    Route::post('chat/{message}/feedback', [KnowledgeChatController::class, 'feedback'])->name('chat.feedback');
    Route::get('chat/{message}/detail', [KnowledgeChatController::class, 'messageDetail'])->name('chat.detail');
});
Route::get('knowledge-chat-status/{token}', [KnowledgeChatController::class, 'status'])->name('companies.knowledge.chat.status');

// Знание на ниво flow — toggle от builder-а (огледало на flows.memory.toggle)
Route::post('flows/{flow}/knowledge/toggle', [FlowKnowledgeController::class, 'toggle'])->name('flows.knowledge.toggle');

// MCP Конектори — „Свързани системи" на ниво фирма (страница + JSON endpoints)
Route::prefix('companies/{company}/connectors')->name('companies.connectors.')->group(function () {
    Route::get('/', [CompanyConnectorController::class, 'index'])->name('index');
    Route::get('data', [CompanyConnectorController::class, 'data'])->name('data');
    Route::get('available', [CompanyConnectorController::class, 'available'])->name('available');
    Route::post('/', [CompanyConnectorController::class, 'store'])->name('store');
    Route::put('{connector}', [CompanyConnectorController::class, 'update'])->name('update');
    Route::delete('{connector}', [CompanyConnectorController::class, 'destroy'])->name('destroy');
    Route::post('{connector}/test', [CompanyConnectorController::class, 'test'])->name('test');
    Route::get('{connector}/logs', [CompanyConnectorController::class, 'logs'])->name('logs');
    Route::get('{connector}/options', [CompanyConnectorController::class, 'options'])->name('options');
});

// OAuth (Socialite/Http) — Google (Gmail/Sheets/Drive) + Slack. Callback-ите са
// без {company} (URI-то трябва точно да съвпада с регистрирания redirect).
Route::get('companies/{company}/oauth/google/redirect', [OAuthController::class, 'googleRedirect'])->name('oauth.google.redirect');
Route::get('oauth/google/callback', [OAuthController::class, 'googleCallback'])->name('oauth.google.callback');
Route::get('companies/{company}/oauth/slack/redirect', [OAuthController::class, 'slackRedirect'])->name('oauth.slack.redirect');
Route::get('oauth/slack/callback', [OAuthController::class, 'slackCallback'])->name('oauth.slack.callback');

// Flow runs
Route::get('flows/{flow}/runs-history', [FlowController::class, 'runsHistory'])->name('flows.runs-history');
Route::post('flows/{flow}/run', [FlowRunController::class, 'store'])->name('flow-runs.store');
Route::get('runs/{flowRun}', [FlowRunController::class, 'show'])->name('flow-runs.show');
Route::get('runs/{flowRun}/poll', [FlowRunController::class, 'poll'])->name('flow-runs.poll');
// Full input/output/raw_output for ONE node — fetched on demand (the poll ships metadata only).
Route::get('runs/{flowRun}/nodes/{nodeKey}', [FlowRunController::class, 'nodeDetail'])->name('flow-runs.node-detail');
// Тест на агент: transient single-node experiments (background process + cache token)
// and applying a winning attempt onto the flow's current node.
Route::post('runs/{flowRun}/nodes/{nodeKey}/test', [FlowRunController::class, 'nodeTest'])->name('flow-runs.node-test');
Route::get('node-test-status/{token}', [FlowRunController::class, 'nodeTestStatus'])->name('flow-runs.node-test.status');
Route::post('runs/{flowRun}/nodes/{nodeKey}/apply-test', [FlowRunController::class, 'applyTest'])->name('flow-runs.apply-test');
Route::get('runs/{flowRun}/log', [FlowRunController::class, 'log'])->name('flow-runs.log');
// Фаза 3: persist a succeeded mid-run revision into the flow (user-confirmed).
Route::post('runs/{flowRun}/apply-revision', [FlowRunController::class, 'applyRevision'])->name('flow-runs.apply-revision');
// Resume a failed run (optionally after patching a node's model/prompts).
Route::post('runs/{flowRun}/resume', [FlowRunController::class, 'resume'])->name('flow-runs.resume');
// Human-in-the-loop: approve/reject a paused human_approval node (decision param).
Route::post('runs/{flowRun}/nodes/{nodeKey}/approval', [FlowRunController::class, 'approval'])->name('flow-runs.approval');

// AJAX: generate AI text for a single agent field
Route::post('ai/generate-agent-field', [AgentController::class, 'generateAgentField'])->name('agents.generate-field');

// Agent template picker (AJAX for popup)
Route::get('agent-templates/picker', [AgentTemplateController::class, 'picker'])->name('agent-templates.picker');

// Company agent templates (CRUD)
Route::get('companies/{company}/agent-templates', [AgentTemplateController::class, 'index'])->name('companies.agent-templates.index');
Route::get('companies/{company}/agent-templates/create', [AgentTemplateController::class, 'create'])->name('companies.agent-templates.create');
Route::post('companies/{company}/agent-templates', [AgentTemplateController::class, 'store'])->name('companies.agent-templates.store');
Route::get('companies/{company}/agent-templates/{agentTemplate}/edit', [AgentTemplateController::class, 'edit'])->name('companies.agent-templates.edit');
Route::put('companies/{company}/agent-templates/{agentTemplate}', [AgentTemplateController::class, 'update'])->name('companies.agent-templates.update');
Route::delete('companies/{company}/agent-templates/{agentTemplate}', [AgentTemplateController::class, 'destroy'])->name('companies.agent-templates.destroy');

// LLM Models
Route::get('models', [LlmModelController::class, 'index'])->name('models.index');
Route::post('models', [LlmModelController::class, 'store'])->name('models.store');
Route::post('models/sync', [LlmModelController::class, 'sync'])->name('models.sync');
Route::post('models/{model}/pull', [LlmModelController::class, 'pull'])->name('models.pull');
Route::get('models/{model}/pull/status', [LlmModelController::class, 'pullStatus'])->name('models.pull.status');
Route::post('models/{model}/test', [LlmModelController::class, 'test'])->name('models.test');
Route::get('models/{model}/test/status', [LlmModelController::class, 'testStatus'])->name('models.test.status');
Route::post('models/{model}/toggle', [LlmModelController::class, 'toggle'])->name('models.toggle');

// Admin auth
Route::redirect('admin', 'admin/costs');
Route::get('admin/login', [AdminAuthController::class, 'showLogin'])->name('admin.login');
Route::post('admin/login', [AdminAuthController::class, 'login'])->name('admin.login.post');
Route::post('admin/logout', [AdminAuthController::class, 'logout'])->name('admin.logout');

// Admin agent templates (protected)
Route::middleware('is_admin')->prefix('admin')->name('admin.')->group(function () {
    Route::get('agent-templates', [AdminAgentTemplateController::class, 'index'])->name('agent-templates.index');
    Route::get('agent-templates/create', [AdminAgentTemplateController::class, 'create'])->name('agent-templates.create');
    Route::post('agent-templates', [AdminAgentTemplateController::class, 'store'])->name('agent-templates.store');
    Route::patch('agent-templates/{agentTemplate}/toggle-active', [AdminAgentTemplateController::class, 'toggleActive'])->name('agent-templates.toggle-active');
    Route::get('agent-templates/{agentTemplate}/edit', [AdminAgentTemplateController::class, 'edit'])->name('agent-templates.edit');
    Route::put('agent-templates/{agentTemplate}', [AdminAgentTemplateController::class, 'update'])->name('agent-templates.update');
    Route::delete('agent-templates/{agentTemplate}', [AdminAgentTemplateController::class, 'destroy'])->name('agent-templates.destroy');

    // Разходи — LLM usage + paid cost tracking (default admin landing page)
    Route::get('costs', [AdminCostController::class, 'index'])->name('costs.index');
    // AJAX data endpoints — one per tab/section, lazy-loaded with server-side pagination
    Route::get('costs/data/overview', [AdminCostController::class, 'overview'])->name('costs.data.overview');
    Route::get('costs/data/grid', [AdminCostController::class, 'grid'])->name('costs.data.grid');
    Route::get('costs/data/chat', [AdminCostController::class, 'chat'])->name('costs.data.chat');
    Route::get('costs/data/client-wizard', [AdminCostController::class, 'clientWizard'])->name('costs.data.client-wizard');
    Route::get('costs/data/external', [AdminCostController::class, 'external'])->name('costs.data.external');
    Route::get('costs/data/knowledge', [AdminCostController::class, 'knowledge'])->name('costs.data.knowledge');
    Route::get('costs/data/other', [AdminCostController::class, 'other'])->name('costs.data.other');
    Route::get('costs/data/ocr', [AdminCostController::class, 'ocr'])->name('costs.data.ocr');
    // Drill-down popups
    Route::get('costs/detail', [AdminCostController::class, 'show'])->name('costs.show');
    Route::get('costs/group-detail', [AdminCostController::class, 'groupDetail'])->name('costs.group-detail');
    Route::get('costs/chat-detail', [AdminCostController::class, 'chatDetail'])->name('costs.chat-detail');
    Route::get('costs/client-wizard-detail', [AdminCostController::class, 'clientWizardDetail'])->name('costs.client-wizard-detail');
    Route::get('costs/ocr-detail', [AdminCostController::class, 'ocrDetail'])->name('costs.ocr-detail');
});

// Home
Route::get('/', [CompanyController::class, 'index'])->name('home');

}); // край на основния домейн (APP_DOMAIN) group
