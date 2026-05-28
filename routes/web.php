<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\FlowController;
use App\Http\Controllers\FlowRunController;
use App\Http\Controllers\LlmModelController;
use Illuminate\Support\Facades\Route;

// Companies
Route::resource('companies', CompanyController::class);

// Flows — nested under company for create, shallow for the rest
Route::resource('companies.flows', FlowController::class)->shallow();

// AJAX: generate agents — starts background job, returns token
Route::post('flows/generate-agents', [FlowController::class, 'generateAgents'])->name('flows.generate-agents');
// AJAX: poll generation status by token
Route::get('flows/generation-status/{token}', [FlowController::class, 'generationStatus'])->name('flows.generation-status');

// Flow runs
Route::post('flows/{flow}/run', [FlowRunController::class, 'store'])->name('flow-runs.store');
Route::get('runs/{flowRun}', [FlowRunController::class, 'show'])->name('flow-runs.show');

// Agent edit
Route::get('flows/{flow}/agents/{agent}/edit', [AgentController::class, 'edit'])->name('agents.edit');
Route::put('flows/{flow}/agents/{agent}', [AgentController::class, 'update'])->name('agents.update');

// LLM Models
Route::get('models', [LlmModelController::class, 'index'])->name('models.index');
Route::post('models', [LlmModelController::class, 'store'])->name('models.store');
Route::post('models/sync', [LlmModelController::class, 'sync'])->name('models.sync');
Route::post('models/{model}/pull', [LlmModelController::class, 'pull'])->name('models.pull');
Route::get('models/{model}/pull/status', [LlmModelController::class, 'pullStatus'])->name('models.pull.status');
Route::post('models/{model}/test', [LlmModelController::class, 'test'])->name('models.test');
Route::post('models/{model}/toggle', [LlmModelController::class, 'toggle'])->name('models.toggle');

// Home
Route::get('/', [CompanyController::class, 'index'])->name('home');
