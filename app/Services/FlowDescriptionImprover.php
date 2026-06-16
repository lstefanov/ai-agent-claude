<?php

namespace App\Services;

/**
 * "Подобри с AI" — пренаписва свободното описание на flow в по-конкретен,
 * по-ясен за планиращия модел текст. Единствен източник на промпта; ползва се
 * и от админ builder-а (FlowController), и от клиентския wizard
 * (Client\FlowWizardController) преди генерация.
 */
class FlowDescriptionImprover
{
    public function __construct(private GeneratorService $llm) {}

    /**
     * Връща подобреното описание (без кавички/въведение). Хвърля при липсващ
     * provider/ключ — извикващият решава как да го покаже.
     */
    public function improve(string $name, string $description): string
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

        return trim($this->llm->assist(
            systemPrompt: $systemPrompt,
            userMessage: $userMessage,
            options: ['temperature' => 0.4, 'num_predict' => 600],
        ));
    }
}
