<?php

namespace App\Services\Org;

use App\Models\BusinessProfile;
use App\Services\GeneratorService;
use App\Support\LlmUsage;

/**
 * Интервюто на Управителя (§1.1) — по модела на ClientFlowWizardService (същият
 * parse/validate/JSON-схема патърн). Задава по един въпрос с готови опции, стъпвайки на
 * ситуационния анализ + болките; спира при „ясна представа" или при max_questions (forceReady).
 */
class OrgInterviewService
{
    public function __construct(private GeneratorService $generator) {}

    /**
     * Един ход на интервюто.
     *
     * @return array{phase: string, reply: string, question: ?array, recap: array, cost_usd: ?float}
     */
    public function turn(BusinessProfile $profile, string $userInput, ?callable $onStage = null): array
    {
        $onStage && $onStage('Обмислям следващия въпрос…');

        $answers = (array) $profile->interview_answers;
        $maxQuestions = (int) config('organization.manager.max_questions', 8);
        $forceReady = count($answers) >= $maxQuestions;

        [$system, $user] = $this->prompt($profile, $answers, $userInput, $forceReady);

        $raw = $this->generator->chatJson($system, $user, 'org_interview', $this->schema(), [
            'temperature' => 0.4,
            'num_predict' => 1500,
        ]);

        $cost = LlmUsage::take()['cost_usd'] ?? null;
        $valid = $this->normalize($raw, $forceReady, $profile);

        return $valid + ['cost_usd' => $cost];
    }

    /** Системен + потребителски промпт, стъпващи на анализа/болките/събраните отговори. */
    private function prompt(BusinessProfile $profile, array $answers, string $userInput, bool $forceReady): array
    {
        $pains = implode("\n", array_map(fn ($p) => "- {$p}", (array) $profile->pain_points));

        $system = <<<'TXT'
Ти си опитен бизнес консултант, който интервюира собственика, за да си състави ЯСНА представа
преди да проектира екип от AI асистенти. Говори на български, топло и по същество.

ПРАВИЛА:
- Задавай ТОЧНО ЕДИН въпрос наведнъж, с 2–5 готови опции (radio за избор на едно, checkbox за няколко).
- Стъпвай на ситуационния анализ и болките — питай кое от тях боли най-много, какви приоритети има.
- Когато намерението, болките и приоритетите са ясни → phase="ready" (без нов въпрос).
- Връщай САМО валиден JSON по схемата, без друг текст.
TXT;
        if ($forceReady) {
            $system .= "\nДостигнат е лимитът на въпросите — върни phase=\"ready\" с кратко обобщение, без нов въпрос.";
        }

        $user = "Ситуационен анализ:\n".((string) $profile->situational_analysis ?: '(няма)')
            ."\n\nБолки:\n".($pains ?: '(няма)')
            ."\n\nСъбрани отговори досега:\n".(json_encode($answers, JSON_UNESCAPED_UNICODE) ?: '{}')
            ."\n\nПоследно от собственика: ".($userInput !== '' ? $userInput : '(начало на интервюто)');

        return [$system, $user];
    }

    /** Минимална JSON схема за хода. */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'phase' => ['type' => 'string', 'enum' => ['interview', 'ready']],
                'reply' => ['type' => 'string'],
                'question' => [
                    'type' => ['object', 'null'],
                    'properties' => [
                        'key' => ['type' => 'string'],
                        'text' => ['type' => 'string'],
                        'input_type' => ['type' => 'string', 'enum' => ['radio', 'checkbox']],
                        'options' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'value' => ['type' => 'string'],
                                    'label' => ['type' => 'string'],
                                ],
                            ],
                        ],
                        'allow_other' => ['type' => 'boolean'],
                    ],
                ],
                'recap' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['phase', 'reply'],
        ];
    }

    /** Нормализира + валидира изхода; прилага forceReady (кодът ГАРАНТИРА). */
    private function normalize(array $data, bool $forceReady, BusinessProfile $profile): array
    {
        $phase = ($data['phase'] ?? 'interview') === 'ready' ? 'ready' : 'interview';
        $reply = trim((string) ($data['reply'] ?? ''));
        $question = $this->normalizeQuestion($data['question'] ?? null);
        $recap = array_values(array_filter(array_map('strval', (array) ($data['recap'] ?? []))));

        // Достигнат лимит → принудително ready, без въпрос.
        if ($forceReady) {
            $phase = 'ready';
            $question = null;
        }
        // ready няма въпрос; interview без валиден въпрос → пак ready (нямаме какво да питаме).
        if ($phase === 'ready') {
            $question = null;
            if ($reply === '') {
                $reply = 'Мисля, че имам ясна представа. Готови сме да проектираме екипа.';
            }
        } elseif ($question === null) {
            $phase = 'ready';
            $reply = $reply !== '' ? $reply : 'Имам достатъчно информация — да продължим към екипа.';
        }

        return ['phase' => $phase, 'reply' => $reply, 'question' => $question, 'recap' => $recap];
    }

    private function normalizeQuestion($q): ?array
    {
        if (! is_array($q) || empty($q['text']) || empty($q['key'])) {
            return null;
        }

        $options = [];
        foreach ((array) ($q['options'] ?? []) as $opt) {
            if (is_array($opt) && filled($opt['value'] ?? null) && filled($opt['label'] ?? null)) {
                $options[] = ['value' => (string) $opt['value'], 'label' => (string) $opt['label']];
            }
        }
        if ($options === []) {
            return null;
        }

        return [
            'key' => (string) $q['key'],
            'text' => (string) $q['text'],
            'input_type' => ($q['input_type'] ?? 'radio') === 'checkbox' ? 'checkbox' : 'radio',
            'options' => $options,
            'allow_other' => (bool) ($q['allow_other'] ?? true),
            'other_label' => 'Друго (опиши)',
        ];
    }
}
