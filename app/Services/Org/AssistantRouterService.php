<?php

namespace App\Services\Org;

use App\Models\Company;
use App\Services\GeneratorService;
use App\Support\PromptData;
use Illuminate\Support\Facades\Log;

/**
 * „Управителят разпределя" (Фаза 3, авто-вход за нова задача): по свободен текст избира
 * НАЙ-подходящия активен асистент по роля/мандат/персона. LLM предлага, кодът гарантира —
 * невалиден/липсващ избор → детерминистичен fallback (първият асистент).
 */
class AssistantRouterService
{
    public function __construct(private GeneratorService $generator) {}

    /**
     * Активните асистенти на фирмата от текущата org версия (за рутиране и за ръчния избор).
     *
     * @return array<int, array{member_id:int,name:string,title:string,mandate:string,tone:string,director:string,director_member_id:?int}>
     */
    public function activeAssistants(Company $company): array
    {
        $version = $company->activeOrgVersion;
        if (! $version) {
            return [];
        }

        return $version->assistants()
            ->with(['orgMember.persona', 'director.orgMember'])
            ->get()
            ->filter(fn ($a) => $a->orgMember && $a->orgMember->status !== 'retired')
            ->map(fn ($a) => [
                'member_id' => $a->orgMember->id,
                'name' => $a->orgMember->persona->name ?? $a->orgMember->display_name,
                'title' => (string) $a->title,
                'mandate' => (string) $a->mandate,
                'tone' => (string) ($a->orgMember->persona->tone ?? ''),
                'director' => (string) ($a->director?->title ?? ''),
                'director_member_id' => $a->director?->orgMember?->id,
            ])
            ->values()
            ->all();
    }

    /**
     * Избира асистент за нова задача. Връща member_id + причина, или null ако няма асистенти.
     *
     * @return array{member_id:int, reason:string}|null
     */
    public function route(Company $company, string $taskText): ?array
    {
        $assistants = $this->activeAssistants($company);
        if ($assistants === []) {
            return null;
        }
        if (count($assistants) === 1) {
            return ['member_id' => $assistants[0]['member_id'], 'reason' => 'Единственият наличен асистент.'];
        }

        $ids = array_column($assistants, 'member_id');
        $chosen = null;
        $reason = '';

        if ($this->generator->isAvailable()) {
            try {
                $list = array_map(fn ($a) => [
                    'org_member_id' => $a['member_id'],
                    'name' => $a['name'],
                    'title' => $a['title'],
                    'mandate' => $a['mandate'],
                    'tone' => $a['tone'],
                    'director' => $a['director'],
                ], $assistants);

                $system = 'Ти си Управителят на организацията. Разпредели НОВАТА задача към НАЙ-подходящия '
                    .'асистент според неговата роля/мандат/персона. Върни org_member_id САМО от подадения '
                    .'списък и кратка причина (едно изречение на български).';
                $user = "НОВА ЗАДАЧА:\n\"{$taskText}\"\n\nАСИСТЕНТИ (избери org_member_id оттук):\n"
                    .json_encode(PromptData::humanize($list), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                $schema = [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['org_member_id', 'reason'],
                    'properties' => [
                        'org_member_id' => ['type' => 'integer'],
                        'reason' => ['type' => 'string'],
                    ],
                ];

                $raw = $this->generator->chatJson($system, $user, 'org_route', $schema, [
                    'temperature' => 0.2, 'num_predict' => 300,
                ]);

                $candidate = (int) ($raw['org_member_id'] ?? 0);
                if (in_array($candidate, $ids, true)) {
                    $chosen = $candidate;
                    $reason = trim((string) ($raw['reason'] ?? '')) ?: 'Избран от Управителя.';
                }
            } catch (\Throwable $e) {
                Log::info('[AssistantRouter] LLM routing failed: '.$e->getMessage());
            }
        }

        // Код-гарантиран fallback: невалиден/липсващ избор → първият асистент.
        if ($chosen === null) {
            $chosen = $ids[0];
            $reason = $reason ?: 'Назначен по подразбиране (Управителят не успя да избере).';
        }

        return ['member_id' => $chosen, 'reason' => $reason];
    }
}
