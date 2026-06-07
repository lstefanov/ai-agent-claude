<?php

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AgentTemplateController as AdminAgentTemplateController;
use App\Http\Controllers\Admin\CostController as AdminCostController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\AgentTemplateController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\FlowBuilderController;
use App\Http\Controllers\FlowController;
use App\Http\Controllers\FlowGraphController;
use App\Http\Controllers\FlowRunController;
use App\Http\Controllers\LlmModelController;
use App\Http\Controllers\PlanAbController;
use Illuminate\Support\Facades\Route;

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

// Фаза 4: A/B сравнение на planner провайдърите (OpenAI vs Anthropic)
Route::get('flows/{flow}/plan-ab', [PlanAbController::class, 'show'])->name('flows.plan-ab');
Route::post('flows/{flow}/plan-ab/start', [PlanAbController::class, 'start'])->name('flows.plan-ab.start');
Route::get('plan-ab-status/{token}', [PlanAbController::class, 'status'])->name('flows.plan-ab.status');
Route::post('flows/{flow}/plan-ab/apply', [PlanAbController::class, 'apply'])->name('flows.plan-ab.apply');

// Flow runs
Route::post('flows/{flow}/run', [FlowRunController::class, 'store'])->name('flow-runs.store');
Route::get('runs/{flowRun}', [FlowRunController::class, 'show'])->name('flow-runs.show');
Route::get('runs/{flowRun}/poll', [FlowRunController::class, 'poll'])->name('flow-runs.poll');
Route::get('runs/{flowRun}/log', [FlowRunController::class, 'log'])->name('flow-runs.log');
// Фаза 3: persist a succeeded mid-run revision into the flow (user-confirmed).
Route::post('runs/{flowRun}/apply-revision', [FlowRunController::class, 'applyRevision'])->name('flow-runs.apply-revision');

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
    Route::get('costs/detail', [AdminCostController::class, 'show'])->name('costs.show');
    Route::get('costs/group-detail', [AdminCostController::class, 'groupDetail'])->name('costs.group-detail');
});

// Home
Route::get('/', [CompanyController::class, 'index'])->name('home');
