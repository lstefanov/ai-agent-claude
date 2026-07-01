<?php

namespace App\Services;

use App\Services\Org\Billing\BillableOperationService;
use Illuminate\Support\Str;

/**
 * "Подобри с AI" — пренаписва свободното описание на flow в по-конкретен,
 * по-ясен за планиращия модел текст. Единствен източник на промпта; ползва се
 * и от админ builder-а (FlowController), и от клиентския wizard
 * (Client\FlowWizardController) преди генерация.
 */
class FlowDescriptionImprover
{
    public function __construct(
        private GeneratorService $llm,
        private BillableOperationService $billable,
    ) {}

    /**
     * Връща подобреното описание (без кавички/въведение). Хвърля при липсващ
     * provider/ключ — извикващият решава как да го покаже.
     *
     * @param  int|null  $companyId  При наличие таксува кредити към фирмата (best-effort).
     *                               При null (admin/system) — assist без атрибуция.
     */
    public function improve(string $name, string $description, ?int $companyId = null): string
    {
        $systemPrompt = 'Ти си експерт по бизнес автоматизация и дигитален маркетинг. Подобряваш описания на автоматизирани workflows. Отговаряй САМО с подобреното описание — без въведение, без обяснения, без кавички.';

        $userMessage = <<<MSG
Подобри следното описание на flow "{$name}".

Оригинално описание:
{$description}

Изисквания:
- Напиши 3-5 изречения на български
- Бъди конкретен за: какво прави flow-ът, за коя аудитория е, на какъв език е изходът, каква е структурата на pipeline-а
- Запази оригиналния смисъл, но го направи по-детайлен и по-ясен за AI агентите
- Върни САМО подобреното описание, без допълнителен текст
MSG;

        $doAssist = fn () => $this->llm->assist(
            systemPrompt: $systemPrompt,
            userMessage: $userMessage,
            options: ['temperature' => 0.4, 'num_predict' => 600],
        );

        // При наличен companyId — таксуваме кредити (best-effort); иначе admin/system path.
        if ($companyId !== null) {
            return trim($this->billable->run(
                companyId: $companyId,
                contextType: 'text_assist',
                subject: null,
                work: $doAssist,
                opKey: (string) Str::uuid(),
            ));
        }

        return trim($doAssist());
    }
}
