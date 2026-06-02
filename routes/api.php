<?php

use App\Http\Controllers\FlowWebhookController;
use Illuminate\Support\Facades\Route;

// Webhook trigger — used by n8n, Zapier, Make, etc.
Route::post('webhook/flows/{flow}/run', [FlowWebhookController::class, 'trigger'])
    ->name('flows.webhook.trigger');
