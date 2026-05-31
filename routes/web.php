<?php

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AgentTemplateController as AdminAgentTemplateController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\AgentTemplateController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\FlowController;
use App\Http\Controllers\FlowRunController;
use App\Http\Controllers\LlmModelController;
use Illuminate\Support\Facades\Route;

// Companies
Route::resource('companies', CompanyController::class);

// Flows — nested under company for create, shallow for the rest
Route::resource('companies.flows', FlowController::class)->shallow();

// Archive / unarchive flows
Route::post('flows/{flow}/archive', [FlowController::class, 'archive'])->name('flows.archive');
Route::post('flows/{flow}/unarchive', [FlowController::class, 'unarchive'])->name('flows.unarchive');

// AJAX: generate agents — starts background job, returns token
Route::post('flows/generate-agents', [FlowController::class, 'generateAgents'])->name('flows.generate-agents');
// AJAX: improve flow description with AI
Route::post('flows/improve-description', [FlowController::class, 'improveDescription'])->name('flows.improve-description');
// AJAX: poll generation status by token
Route::get('flows/generation-status/{token}', [FlowController::class, 'generationStatus'])->name('flows.generation-status');

// Flow runs
Route::post('flows/{flow}/run', [FlowRunController::class, 'store'])->name('flow-runs.store');
Route::get('runs/{flowRun}', [FlowRunController::class, 'show'])->name('flow-runs.show');
Route::get('runs/{flowRun}/poll', [FlowRunController::class, 'poll'])->name('flow-runs.poll');
Route::get('runs/{flowRun}/log', [FlowRunController::class, 'log'])->name('flow-runs.log');
Route::patch('runs/{flowRun}/qa-thresholds', [FlowRunController::class, 'updateQaThresholds'])->name('flow-runs.qa-thresholds');

// Agent management
Route::post('flows/{flow}/agents', [AgentController::class, 'store'])->name('agents.store');
Route::delete('flows/{flow}/agents/{agent}', [AgentController::class, 'destroy'])->name('agents.destroy');
Route::post('flows/{flow}/agents/reorder', [AgentController::class, 'reorder'])->name('agents.reorder');
Route::get('flows/{flow}/agents/{agent}/edit', [AgentController::class, 'edit'])->name('agents.edit');
Route::put('flows/{flow}/agents/{agent}', [AgentController::class, 'update'])->name('agents.update');

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

// Home
Route::get('/', [CompanyController::class, 'index'])->name('home');
