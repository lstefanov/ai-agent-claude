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
    // „Подобри с AI" преди генерация — пренаписва описанието през assist модела.
    Route::post('wizard/improve-description', [Client\FlowWizardController::class, 'improveDescription'])->name('client.wizard.improve-description');
    // Same-origin поллинг на генерацията (чете същия глобален cache като админа).
    Route::get('wizard/generation-status/{token}', [Client\FlowWizardController::class, 'generationStatus'])->name('client.wizard.generation-status');

    // AI Организация (org слой) — casting на Управителя → проучване → интервю (Фаза 1).
    Route::prefix('org')->group(function () {
        Route::get('start', [Client\Org\OnboardingController::class, 'start'])->name('client.org.start');
        Route::get('casting', [Client\Org\OnboardingController::class, 'casting'])->name('client.org.casting');
        Route::post('casting', [Client\Org\OnboardingController::class, 'hireManager'])->name('client.org.casting.hire');
        Route::get('research', [Client\Org\OnboardingController::class, 'research'])->name('client.org.research');
        Route::post('research/start', [Client\Org\OnboardingController::class, 'startResearch'])->name('client.org.research.start');
        Route::get('research/status/{token}', [Client\Org\OnboardingController::class, 'researchStatus'])->name('client.org.research.status');

        Route::get('interview', [Client\Org\InterviewController::class, 'show'])->name('client.org.interview');
        Route::post('interview/send', [Client\Org\InterviewController::class, 'send'])->name('client.org.interview.send');
        Route::get('interview/status/{token}', [Client\Org\InterviewController::class, 'status'])->name('client.org.interview.status');

        // Дизайн на екипа (Фаза 2): предложи → ревю → одобри (материализация).
        Route::post('design/propose', [Client\Org\DesignController::class, 'propose'])->name('client.org.design.propose');
        Route::get('design/status/{token}', [Client\Org\DesignController::class, 'status'])->name('client.org.design.status');
        Route::get('design/review', [Client\Org\DesignController::class, 'review'])->name('client.org.design.review');
        Route::post('design/approve', [Client\Org\DesignController::class, 'approve'])->name('client.org.design.approve');

        // Персони (доуточняване/редакция).
        Route::post('personas/{persona}/refine', [Client\Org\PersonaController::class, 'refine'])->name('client.org.personas.refine');
        Route::put('personas/{persona}', [Client\Org\PersonaController::class, 'update'])->name('client.org.personas.update');

        // Изгледи на графа (Roster / Skill Tree / Карта на героя) + контроли.
        Route::get('roster', [Client\Org\OrgGraphController::class, 'roster'])->name('client.org.roster');
        Route::get('skill-tree', [Client\Org\OrgGraphController::class, 'skillTree'])->name('client.org.skill-tree');
        Route::get('graph.json', [Client\Org\OrgGraphController::class, 'graph'])->name('client.org.graph');
        Route::get('members/{member}', [Client\Org\MemberController::class, 'show'])->name('client.org.member');
        Route::post('members/{member}/avatar/regenerate', [Client\Org\MemberController::class, 'regenerateAvatar'])->name('client.org.member.avatar');
        Route::post('members/{member}/tier', [Client\Org\MemberController::class, 'setTier'])->name('client.org.member.tier');
        Route::post('members/{member}/promote-department', [Client\Org\MemberController::class, 'promoteDepartment'])->name('client.org.member.promote-dept');
        Route::post('tasks/{task}/tier', [Client\Org\AssistantTaskController::class, 'setTier'])->name('client.org.tasks.tier');

        // Задачи = flows (Фаза 3): генерация per асистент + ръчно пускане + Текущ поток.
        Route::post('tasks/{task}/generate', [Client\Org\AssistantTaskController::class, 'generate'])->name('client.org.tasks.generate');
        Route::get('tasks/{task}/gen-status/{token}', [Client\Org\AssistantTaskController::class, 'genStatus'])->name('client.org.tasks.gen-status');
        Route::post('tasks/{task}/run', [Client\Org\AssistantTaskController::class, 'run'])->name('client.org.tasks.run');
        Route::get('quests', [Client\Org\QuestController::class, 'index'])->name('client.org.quests');
        Route::get('live', [Client\Org\OrgGraphController::class, 'live'])->name('client.org.live');

        // Фаза 4: Кутия за решения + чат с членове + ръчен директорски tick.
        Route::get('decisions', [Client\Org\DecisionController::class, 'index'])->name('client.org.decisions');
        Route::post('decisions/approve', [Client\Org\DecisionController::class, 'approve'])->name('client.org.decisions.approve');
        Route::post('decisions/reject', [Client\Org\DecisionController::class, 'reject'])->name('client.org.decisions.reject');
        Route::post('members/{member}/tick', [Client\Org\MemberController::class, 'tick'])->name('client.org.member.tick-now');

        Route::get('members/{member}/chat', [Client\Org\ChatController::class, 'show'])->name('client.org.chat');
        Route::post('chat/send', [Client\Org\ChatController::class, 'send'])->name('client.org.chat.send');
        Route::get('chat/status/{token}', [Client\Org\ChatController::class, 'status'])->name('client.org.chat.status');
    });
});
