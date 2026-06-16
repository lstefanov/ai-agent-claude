<?php

use App\Http\Controllers\Client;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Клиентски портал (clients.<domain> или /client fallback)
|--------------------------------------------------------------------------
|
| Регистрира се от bootstrap/app.php (withRouting `then:`). Бизнес изгледът:
| без агенти/графи/модели/цени — само Flows, „Изпълни", прогрес, Резултат и
| разговорният създател.
|
*/

// Публични (без клиентска сесия)
Route::get('login', [Client\AuthController::class, 'showLogin'])->name('client.login');
Route::post('login', [Client\AuthController::class, 'login'])->name('client.login.post');
Route::get('login/companies/{company}/users', [Client\AuthController::class, 'usersForCompany'])->name('client.login.users');
Route::post('logout', [Client\AuthController::class, 'logout'])->name('client.logout');

// Защитени (client_auth)
Route::middleware('client_auth')->group(function () {
    Route::get('/', [Client\DashboardController::class, 'index'])->name('client.dashboard');

    // Flows — „create" преди „{flow}", за да не се хване като id
    Route::get('flows', [Client\FlowController::class, 'index'])->name('client.flows.index');
    Route::get('flows/create', [Client\FlowWizardController::class, 'create'])->name('client.flows.create');
    Route::post('flows/create/new', [Client\FlowWizardController::class, 'startNew'])->name('client.wizard.new');
    Route::get('flows/{flow}', [Client\FlowController::class, 'show'])->name('client.flows.show');
    Route::put('flows/{flow}/description', [Client\FlowController::class, 'updateDescription'])->name('client.flows.update-description');

    // Изпълнение + прогрес + резултат
    Route::post('flows/{flow}/run', [Client\FlowRunController::class, 'run'])->name('client.flows.run');
    Route::get('runs/{run}/progress', [Client\FlowRunController::class, 'progress'])->name('client.runs.progress');
    Route::get('runs/{run}/result', [Client\FlowRunController::class, 'result'])->name('client.runs.result');

    // Разговорен създател (чат)
    Route::post('wizard/send', [Client\FlowWizardController::class, 'send'])->name('client.wizard.send');
    Route::get('wizard/status/{token}', [Client\FlowWizardController::class, 'status'])->name('client.wizard.status');
    Route::get('wizard/{draft}/history', [Client\FlowWizardController::class, 'history'])->name('client.wizard.history');
    Route::post('wizard/{draft}/build', [Client\FlowWizardController::class, 'build'])->name('client.wizard.build');
    Route::post('wizard/{draft}/revise', [Client\FlowWizardController::class, 'revise'])->name('client.wizard.revise');
    // „Подобри с AI" преди генерация — пренаписва описанието през assist модела.
    Route::post('wizard/improve-description', [Client\FlowWizardController::class, 'improveDescription'])->name('client.wizard.improve-description');
    // Same-origin поллинг на генерацията (чете същия глобален cache като админа).
    Route::get('wizard/generation-status/{token}', [Client\FlowWizardController::class, 'generationStatus'])->name('client.wizard.generation-status');
});
