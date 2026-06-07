<?php

namespace App\Services;

use App\Models\Flow;
use App\Support\PaidModel;
use Illuminate\Support\Facades\Log;

/**
 * Turns a Flow description into a ready-to-build agent DAG.
 *
 * The creative work happens in FlowPlannerService (three-phase LLM planning
 * via structured outputs). This service owns the DETERMINISTIC guarantees on
 * top of whatever the planner proposed:
 *
 *  - model selection per agent (planner-pinned "openai/<model>" respected,
 *    everything else resolved by ModelSelectorService against installed tags);
 *  - num_predict guard-rails per type (unlimited types stay unlimited);
 *  - exactly one bg_text_corrector (second to last) + one qa_verifier (last);
 *  - de-duplication and unknown-type remapping;
 *  - a validated, cycle-free uid dependency graph (Kahn).
 *
 * Principle: the LLM proposes, this code guarantees.
 */
class AgentGeneratorService
{
    private const BG_TEXT_CORRECTOR_TYPE = 'bg_text_corrector';

    private const QA_VERIFIER_TYPE = 'qa_verifier';

    private const DEFAULT_QA_THRESHOLD = 60;

    public function __construct(
        private ModelSelectorService $modelSelector,
        private FlowPlannerService $planner,
        private OllamaService $ollama,
    ) {}

    /**
     * Plan + harden the agent pipeline for a flow. Returns [] when planning
     * produced nothing usable (the caller surfaces the error to the UI).
     *
     * @return array<int, array<string, mixed>>
     */
    public function generate(Flow $flow, ?callable $onProgress = null, ?string $logToken = null): array
    {
        $planned = $this->planner->plan($flow, $onProgress, $logToken);

        if (count($planned) < 3) {
            Log::warning('[AgentGenerator] Planner returned '.count($planned).' agents — nothing to build.');

            return [];
        }

        // Pair the flow with its intent: when the user saves (= approves) the
        // graph, the plan library snapshots intent + graph together (Фаза 2).
        if ($flow->exists && is_array($this->planner->lastIntent())) {
            $flow->update(['plan_intent' => $this->planner->lastIntent()]);
        }

        if ($onProgress) {
            $onProgress('Финализиране на pipeline-а');
        }

        return $this->finalizePlannedAgents($planned);
    }

    /**
     * Normalize + harden a FlowPlannerService plan.
     *
     * @param  array<int, array<string, mixed>>  $planned
     * @return array<int, array<string, mixed>>
     */
    public function finalizePlannedAgents(array $planned): array
    {
        $agents = array_values(array_filter(array_map(
            fn ($a, $i) => $this->normalizeAgent($a, $i + 1),
            $planned,
            array_keys($planned),
        )));

        $agents = $this->ensureQaVerifierLast($agents);
        $agents = $this->ensureBgTextCorrectorBeforeQa($agents);
        $agents = $this->dedupeAgents($agents);
        $agents = $this->finalizeDependencyGraph($agents);
        // The model's depends_on is semantically unreliable (correctors/writers
        // emitted as roots, research→report inverted) — rebuild the DAG from role
        // tiers so the execution order is always correct.
        $agents = $this->applyRoleBasedDependencies($agents);

        // uids are now assigned + stable → wire the final step-QA gate.
        $agents = $this->enableFinalQaGate($agents);

        // Localise English copy to Bulgarian (structure already final, ids
        // untouched). No-op when the planner already wrote Bulgarian.
        return $this->translateAgentsToBulgarian($agents);
    }

    /**
     * Wire the final step-QA gate so the QA-retry + adaptive-revision loop in
     * NodeExecutorService actually fires for planner-generated flows.
     *
     * The bg_text_corrector (guaranteed present and second-to-last after
     * ensureBgTextCorrectorBeforeQa) gets an enabled gate. The verifier is
     * synthesized at run time from the gate's own criteria + threshold, so no
     * separate verifier node has to be laid out or referenced.
     */
    private function enableFinalQaGate(array $agents): array
    {
        foreach ($agents as &$agent) {
            if (($agent['type'] ?? '') !== self::BG_TEXT_CORRECTOR_TYPE) {
                continue;
            }

            $config = is_array($agent['config'] ?? null) ? $agent['config'] : [];
            $existingQa = is_array($config['qa'] ?? null) ? $config['qa'] : [];

            $config['qa'] = array_merge([
                'threshold' => self::DEFAULT_QA_THRESHOLD,
                'max_retries' => 3,
            ], $existingQa, [
                'enabled' => true,
                // The gate reviews the corrector's output = the final deliverable,
                // so the criteria are about final quality, not spelling. Override
                // any per-agent (corrector-specific) prompt the planner emitted.
                'custom_prompt' => 'Оцени дали ФИНАЛНИЯТ текст изпълнява целта на заданието: пълнота, фактическа вярност спрямо подадените данни, правилен език и подходящ формат. Не пенализирай съдържание на български или друг език, ако то е правилният избор за аудиторията.',
            ]);

            $agent['config'] = $config;
            break; // gate only the final corrector
        }
        unset($agent);

        return $agents;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Bulgarian translation (post-planning localisation)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Localise the human-readable agent copy to Bulgarian after planning. The
     * structure (uid/type/depends_on) is already final and is never touched —
     * only display/prompt fields are translated, via the concurrent
     * OllamaService::chatBatch() using the ModelSelector "translate" profile
     * (aya-expanse). Fields already in Cyrillic are skipped, so this is a no-op
     * for a Bulgarian planner and only kicks in for a strong English one.
     *
     * @param  array<int, array<string, mixed>>  $agents
     * @return array<int, array<string, mixed>>
     */
    private function translateAgentsToBulgarian(array $agents): array
    {
        $fields = ['name', 'role', 'system_prompt', 'prompt_template', 'output_description'];
        // Use the best installed NATIVE-Bulgarian writer (BgGPT via the bg_writer
        // profile) — it produces far more fluent Bulgarian than the generic
        // multilingual translate profile (aya-expanse), which renders broken,
        // Russian-flavoured text.
        $translateTag = $this->modelSelector->resolveRunnable('content_bg');

        $requests = [];
        $map = [];
        foreach ($agents as $i => $agent) {
            foreach ($fields as $f) {
                if ($this->needsBgTranslation((string) ($agent[$f] ?? ''))) {
                    $requests["a{$i}_{$f}"] = $this->bgTranslateRequest($translateTag, (string) $agent[$f]);
                    $map["a{$i}_{$f}"] = [$i, $f];
                }
            }
            $qa = $agent['config']['qa']['custom_prompt'] ?? null;
            if (is_string($qa) && $this->needsBgTranslation($qa)) {
                $requests["a{$i}_qa"] = $this->bgTranslateRequest($translateTag, $qa);
                $map["a{$i}_qa"] = [$i, '__qa__'];
            }
        }

        if ($requests === []) {
            return $agents;
        }

        $results = $this->ollama->chatBatch($requests, 6, 120);

        foreach ($results as $key => $translated) {
            $translated = trim((string) $translated);
            if ($translated === '' || ! isset($map[$key])) {
                continue; // keep original on failure
            }
            [$i, $field] = $map[$key];
            $original = $field === '__qa__'
                ? (string) ($agents[$i]['config']['qa']['custom_prompt'] ?? '')
                : (string) ($agents[$i][$field] ?? '');

            // Reject a translation that dropped a {{placeholder}} the original
            // carried — that would break runtime substitution.
            preg_match_all('/\{\{[^}]*\}\}/', $original, $o);
            preg_match_all('/\{\{[^}]*\}\}/', $translated, $n);
            if (array_diff($o[0], $n[0]) !== []) {
                continue;
            }

            if ($field === '__qa__') {
                $agents[$i]['config']['qa']['custom_prompt'] = $translated;
            } else {
                $agents[$i][$field] = $translated;
            }
        }

        return $agents;
    }

    /** A field needs translating only when it has non-Cyrillic prose. */
    private function needsBgTranslation(string $text): bool
    {
        $text = trim($text);

        return $text !== '' && ! preg_match('/\p{Cyrillic}/u', $text);
    }

    /** @return array{model: string, system: string, user: string, options: array<string, mixed>} */
    private function bgTranslateRequest(string $model, string $text): array
    {
        return [
            'model' => $model,
            'system' => 'You are a professional translator. Translate the user message into Bulgarian. '
                .'Keep every {{...}} placeholder, every URL and every proper name EXACTLY as in the original. '
                .'Do not add notes or quotes. Output ONLY the Bulgarian translation.',
            'user' => $text,
            'options' => ['temperature' => 0.2],
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Per-agent normalization
    // ──────────────────────────────────────────────────────────────────────

    private function normalizeAgent(mixed $agent, int $fallbackOrder): ?array
    {
        if (! is_array($agent) || empty($agent['name'])) {
            return null;
        }

        $type = $this->slugifyId((string) ($agent['type'] ?? '')) ?: 'content_bg';

        // BgGPT often labels every agent "content_bg" while still naming the uid
        // after the real type (uid "bg_text_corrector" / type "content_bg"), or
        // uses the canonical verifier uid "qa_main". Recover the type from the uid
        // so the real corrector/verifier are recognised and the pipeline
        // guarantees (ensureBgTextCorrectorBeforeQa / ensureQaVerifierLast) don't
        // synthesise duplicates.
        $uidType = $this->slugifyId((string) ($agent['uid'] ?? ''));
        if ($uidType === 'qa_main') {
            $type = self::QA_VERIFIER_TYPE;
        } elseif ($uidType !== '' && in_array($uidType, array_keys(config('agent_types', [])), true)) {
            // The uid is the model's clearest signal of intent; its `type` field is
            // unreliable (random known-but-wrong values). Trust the uid when it
            // names a real type.
            $type = $uidType;
        }

        // The model is chosen by code, not by the planning LLM — with one
        // exception: a step pinned to a paid provider ("openai/<model>" or
        // "anthropic/<model>") is hybrid execution by design and is preserved.
        $modelHint = trim(($agent['name'] ?? '').' '.($agent['role'] ?? '').' '.($agent['output_description'] ?? ''));
        $plannedModel = (string) ($agent['model'] ?? '');
        $model = PaidModel::isPaid($plannedModel)
            ? $plannedModel
            : $this->modelSelector->selectModel($type, $modelHint);

        // A partial plan must never produce a node with empty prompts — the
        // executor would send empty messages to the model.
        $role = trim((string) ($agent['role'] ?? $agent['name']));
        $promptTemplate = trim((string) ($agent['prompt_template'] ?? ''));
        if ($promptTemplate === '') {
            $promptTemplate = $role !== ''
                ? $role
                : ('Извърши задачата на агент "'.$agent['name'].'" и върни резултата.');
        }
        $systemPrompt = trim((string) ($agent['system_prompt'] ?? ''));
        if ($systemPrompt === '') {
            $systemPrompt = 'Ти си агент "'.$agent['name'].'". '
                .($role !== '' ? $role.' ' : '')
                .'Отговаряй на български език.';
        }

        return [
            'name' => $agent['name'],
            'type' => $type,
            'role' => $role !== '' ? $role : $agent['name'],
            'capabilities' => (array) ($agent['capabilities'] ?? []),
            'strengths' => $agent['strengths'] ?? null,
            'limitations' => $agent['limitations'] ?? null,
            'input_description' => $agent['input_description'] ?? null,
            'output_description' => $agent['output_description'] ?? null,
            'prompt_template' => $promptTemplate,
            'system_prompt' => $systemPrompt,
            'model' => $model,
            'model_reason' => trim((string) ($agent['model_reason'] ?? ''))
                ?: 'Автоматично избран според типа на агента ('.$type.') и наличните модели.',
            'order' => (int) ($agent['order'] ?? $fallbackOrder),
            // qa_verifier is always a verifier even if the planner forgot the flag.
            'is_verifier' => ($agent['type'] ?? '') === self::QA_VERIFIER_TYPE
                ? true
                : (bool) ($agent['is_verifier'] ?? false),
            'qa_threshold' => ($agent['type'] ?? '') === self::QA_VERIFIER_TYPE
                ? $this->qaThresholdOrDefault($agent['qa_threshold'] ?? null)
                : (isset($agent['qa_threshold']) ? (int) $agent['qa_threshold'] : null),
            'config' => $this->normalizeAgentConfig($agent['config'] ?? null, $type),
            'uid' => $agent['uid'] ?? null,
            // Branching DAG: uids of agents whose output this agent consumes.
            'depends_on' => $this->normalizeDependsOn($agent['depends_on'] ?? null),
            'output_language' => $agent['output_language'] ?? null,
        ];
    }

    /**
     * Normalize the dependency list into a clean array of uid strings.
     */
    private function normalizeDependsOn(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($v) => is_string($v) ? trim($v) : (is_scalar($v) ? (string) $v : null),
            $raw,
        ), fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Normalize a model-emitted identifier (uid / type / dependency) to clean
     * snake_case so uids and their depends_on references reconcile even when the
     * LLM inconsistently uses spaces or mixed case (e.g. "report writer" vs
     * "report_writer"). Without this, mismatched edges are silently dropped and
     * the agent floats to the top of the graph.
     */
    private function slugifyId(string $s): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower(trim($s))), '_');
    }

    /**
     * num_predict policy: the planner's explicit output-size intent
     * (planner_num_predict) wins for bounded types; unlimited types (-1) stay
     * unlimited so research dumps and full-text corrections are never truncated.
     */
    private function normalizeAgentConfig(mixed $raw, string $type): array
    {
        $config = is_array($raw) ? $raw : ['temperature' => 0.7];

        $typeDefault = $this->numPredictForType($type);
        $plannerPredict = $config['planner_num_predict'] ?? null;
        unset($config['planner_num_predict']);

        $config['num_predict'] = ($typeDefault === -1)
            ? -1
            : (is_numeric($plannerPredict) ? (int) $plannerPredict : $typeDefault);

        return $config;
    }

    /**
     * Max output tokens per agent type. -1 = unlimited (the model stops on its own).
     */
    private function numPredictForType(string $type): int
    {
        // Unlimited: research agents must output the full gathered data; correctors reproduce full text.
        $unlimited = [
            'deep_researcher', 'researcher', 'multi_researcher',
            'competitor_profiler', 'review_analyzer', 'keyword_extractor',
            'bg_text_corrector',
        ];

        // Long: reports, analyses, summaries that regularly exceed 2 000 tokens.
        $long = [
            'report_writer', 'report_composer', 'analyzer', 'swot_builder',
            'sentiment_analyzer', 'summarizer', 'data_extractor', 'email_sequence_writer',
            'press_release_writer', 'calendar_planner', 'ab_test_generator',
            'survey_builder', 'persona_builder', 'chatbot_responder',
            'podcast_outline', 'video_script_writer', 'story_writer',
            'crm_note_writer', 'offer_builder', 'product_describer',
        ];

        // Medium: posts, emails, captions — substantial but bounded.
        $medium = [
            'content_bg', 'content_en', 'writer', 'caption_writer', 'hook_writer',
            'ad_copywriter', 'seo_writer', 'email_composer', 'newsletter_writer',
            'review_responder', 'whatsapp_message_writer', 'translator',
            'telegram_bot_responder', 'publisher',
        ];

        // Tiny: QA only needs a score + short justification.
        $tiny = ['qa_verifier', 'verifier'];

        if (in_array($type, $unlimited, true)) {
            return -1;
        }
        if (in_array($type, $long, true)) {
            return 6000;
        }
        if (in_array($type, $medium, true)) {
            return 3000;
        }
        if (in_array($type, $tiny, true)) {
            return 500;
        }

        // Default for custom agents, hashtag generators, image_prompt, utility types.
        return 1000;
    }

    private function qaThresholdOrDefault(mixed $threshold): int
    {
        if ($threshold === null || $threshold === '' || (int) $threshold === 0) {
            return self::DEFAULT_QA_THRESHOLD;
        }

        return min(100, max(1, (int) $threshold));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Pipeline-level guarantees
    // ──────────────────────────────────────────────────────────────────────

    private function ensureQaVerifierLast(array $agents): array
    {
        [$agents, $qaAgent] = $this->pullFirstAgentByType($agents, self::QA_VERIFIER_TYPE);
        $agents[] = $qaAgent ?? $this->defaultQaVerifierAgent();

        return $this->renumberAgents($agents);
    }

    private function ensureBgTextCorrectorBeforeQa(array $agents): array
    {
        $agents = $this->ensureQaVerifierLast($agents);
        $qaAgent = array_pop($agents);

        [$agents, $corrector] = $this->pullFirstAgentByType($agents, self::BG_TEXT_CORRECTOR_TYPE);

        $agents[] = $corrector ?? $this->defaultBgTextCorrectorAgent();
        $agents[] = $qaAgent;

        return $this->renumberAgents($agents);
    }

    private function pullFirstAgentByType(array $agents, string $type): array
    {
        $pulled = null;

        foreach ($agents as $index => $agent) {
            if (($agent['type'] ?? '') === $type) {
                if ($pulled === null) {
                    $pulled = $agent;
                }

                unset($agents[$index]);
            }
        }

        return [array_values($agents), $pulled];
    }

    private function renumberAgents(array $agents): array
    {
        foreach ($agents as $i => &$agent) {
            $agent['order'] = $i + 1;
        }
        unset($agent);

        return array_values($agents);
    }

    /**
     * Unknown types are remapped to a real body type and exact duplicates
     * (same type + same name) are collapsed.
     */
    private function dedupeAgents(array $agents): array
    {
        $knownTypes = array_keys(config('agent_types', []));
        $seen = [];
        $seenAppendix = [];
        $out = [];

        foreach ($agents as $agent) {
            $type = $agent['type'] ?? 'content_bg';

            if (! in_array($type, $knownTypes, true)) {
                Log::warning('[AgentGenerator] Unknown agent type "'.$type.'" remapped to content_bg');
                $type = 'content_bg';
                $agent['type'] = $type;
            }

            // Single-purpose (appendix) types must be unique — drop a second
            // hashtag/faq/meta generator even when its name differs.
            if (config("agent_types.{$type}.output_role") === 'appendix') {
                if (isset($seenAppendix[$type])) {
                    Log::warning('[AgentGenerator] Dropping duplicate single-purpose agent "'.($agent['name'] ?? '').'" ('.$type.')');

                    continue;
                }
                $seenAppendix[$type] = true;
            }

            $sig = $type.'|'.mb_strtolower(trim((string) ($agent['name'] ?? '')));
            if (isset($seen[$sig])) {
                Log::warning('[AgentGenerator] Dropping duplicate agent "'.($agent['name'] ?? '').'" ('.$type.')');

                continue;
            }
            $seen[$sig] = true;
            $out[] = $agent;
        }

        return $this->renumberAgents($out);
    }

    /**
     * Guarantee a clean, acyclic dependency graph the builder can lay out:
     *  - every agent gets a unique, stable uid;
     *  - depends_on references are validated against existing uids (self-refs dropped);
     *  - agents without deps are chained to the previous non-verifier agent;
     *  - any cycle collapses the whole graph to a sequential chain by order.
     */
    private function finalizeDependencyGraph(array $agents): array
    {
        $usedUids = [];
        foreach ($agents as $i => &$agent) {
            $uid = $this->slugifyId((string) ($agent['uid'] ?? ''));
            if ($uid === '' || isset($usedUids[$uid])) {
                $uid = ($this->slugifyId((string) ($agent['type'] ?? 'agent')) ?: 'agent').'_'.($i + 1);
            }
            $usedUids[$uid] = true;
            $agent['uid'] = $uid;
            // Slugify deps with the SAME rule as uids so references reconcile.
            $agent['depends_on'] = array_map(
                fn ($d) => $this->slugifyId((string) $d),
                $this->normalizeDependsOn($agent['depends_on'] ?? null),
            );
        }
        unset($agent);

        $validUids = array_fill_keys(array_column($agents, 'uid'), true);
        $prevNonVerifierUid = null;

        foreach ($agents as &$agent) {
            // Keep only references to existing agents; never depend on self.
            $agent['depends_on'] = array_values(array_filter(
                $agent['depends_on'],
                fn ($u) => isset($validUids[$u]) && $u !== $agent['uid'],
            ));

            // Dependency-less agents (except the very first) chain to the previous step.
            if (empty($agent['depends_on']) && $prevNonVerifierUid && ! ($agent['is_verifier'] ?? false)) {
                $isFirstCollector = (int) ($agent['order'] ?? 0) <= 1;
                if (! $isFirstCollector) {
                    $agent['depends_on'] = [$prevNonVerifierUid];
                }
            }

            if (! ($agent['is_verifier'] ?? false)) {
                $prevNonVerifierUid = $agent['uid'];
            }
        }
        unset($agent);

        if ($this->hasDependencyCycle($agents)) {
            Log::warning('[AgentGenerator] depends_on cycle detected — falling back to sequential chain');
            $prev = null;
            foreach ($agents as &$agent) {
                $agent['depends_on'] = ($prev && ! ($agent['is_verifier'] ?? false)) ? [$prev] : [];
                if (! ($agent['is_verifier'] ?? false)) {
                    $prev = $agent['uid'];
                }
            }
            unset($agent);
        }

        return $agents;
    }

    /**
     * Pipeline tier of an agent, by its type's role:
     *   0 site_context (seed) · 1 researchers/analyzers (hidden) ·
     *   2 body/appendix writers · 3 bg_text_corrector · 4 qa_verifier.
     */
    private function roleTier(array $agent): int
    {
        $type = (string) ($agent['type'] ?? '');
        // Tier strictly by TYPE — never the is_verifier flag: BgGPT randomly sets
        // is_verifier=true on non-QA agents (researchers/analyzers), which would
        // wrongly push them to the QA tier. The real verifier reliably has type
        // qa_verifier (uid "qa_main" is recovered to that type in normalizeAgent).
        if ($type === self::BG_TEXT_CORRECTOR_TYPE) {
            return 3;
        }
        if ($type === self::QA_VERIFIER_TYPE) {
            return 4;
        }
        $role = (string) config("agent_types.{$type}.output_role", 'hidden');
        if ($role === 'body' || $role === 'appendix') {
            return 2;
        }

        return $type === 'site_context' ? 0 : 1;
    }

    /**
     * Rebuild the dependency graph deterministically from role tiers. The
     * planning model's own depends_on is unreliable (it emits correctors/writers
     * as roots and inverts research→report), so we wire a canonical fan-in
     * pipeline: site_context → researchers/analyzers → writers → corrector → QA.
     * Each tier depends on every node of the nearest non-empty lower tier, and
     * agents are reordered by tier for a sensible builder layout.
     *
     * @param  array<int, array<string, mixed>>  $agents
     * @return array<int, array<string, mixed>>
     */
    private function applyRoleBasedDependencies(array $agents): array
    {
        $byTier = [0 => [], 1 => [], 2 => [], 3 => [], 4 => []];
        foreach ($agents as $a) {
            $byTier[$this->roleTier($a)][] = $a['uid'] ?? '';
        }

        $lowerDeps = function (int $tier) use ($byTier): array {
            for ($t = $tier - 1; $t >= 0; $t--) {
                if (! empty($byTier[$t])) {
                    return $byTier[$t];
                }
            }

            return [];
        };

        foreach ($agents as &$a) {
            $deps = $lowerDeps($this->roleTier($a));
            $a['depends_on'] = array_values(array_filter($deps, fn ($u) => $u !== ($a['uid'] ?? '')));
        }
        unset($a);

        usort($agents, fn ($x, $y) => $this->roleTier($x) <=> $this->roleTier($y));

        return $this->renumberAgents($agents);
    }

    /** Kahn-style cycle detection over the uid dependency graph. */
    private function hasDependencyCycle(array $agents): bool
    {
        $inDegree = [];
        $adj = [];
        foreach ($agents as $a) {
            $inDegree[$a['uid']] = $inDegree[$a['uid']] ?? 0;
            foreach ($a['depends_on'] as $dep) {
                $adj[$dep][] = $a['uid'];
                $inDegree[$a['uid']]++;
            }
        }

        $queue = array_keys(array_filter($inDegree, fn ($d) => $d === 0));
        $resolved = 0;
        while ($queue) {
            $u = array_shift($queue);
            $resolved++;
            foreach ($adj[$u] ?? [] as $v) {
                if (--$inDegree[$v] === 0) {
                    $queue[] = $v;
                }
            }
        }

        return $resolved < count($inDegree);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Safety-net defaults (added only when the planner omitted them)
    // ──────────────────────────────────────────────────────────────────────

    private function defaultBgTextCorrectorAgent(): array
    {
        return [
            'name' => 'Български коректор',
            'type' => self::BG_TEXT_CORRECTOR_TYPE,
            'role' => 'Преглежда финалния български текст непосредствено преди QA. Коригира правопис, лексика, граматика и естественост на изказа, без да променя смисъла, фактите или формата.',
            'capabilities' => ['правописна корекция', 'лексикална корекция', 'граматична редакция', 'стилова гладкост'],
            'strengths' => 'Открива неестествени или грешни български думи и ги заменя с правилни изрази, като запазва първоначалната идея.',
            'limitations' => 'Не добавя нови факти, не променя оферти, цени, имена, линкове, хаштагове или CTA, освен ако има очевидна правописна грешка.',
            'input_description' => 'Финалният body текст от предходния агент.',
            'output_description' => 'Същият текст, коригиран на естествен и правилен български език.',
            'prompt_template' => 'Прегледай текста и поправи САМО правописните грешки на кирилица. Върни само коригирания текст.',
            'system_prompt' => 'Ти си коректор на правопис. Поправяш САМО правописни грешки в кирилица. Запазваш структурата, хаштаговете, емоджитата, линковете и CTA непроменени. Не пренаписваш, не преструктурираш, не добавяш нова информация. Връщаш само коригирания текст без обяснения.',
            'model' => $this->modelSelector->selectModel(self::BG_TEXT_CORRECTOR_TYPE),
            'model_reason' => 'Избран е модел, оптимизиран за естествен български език и редакция на текст.',
            'order' => 1,
            'is_verifier' => false,
            'qa_threshold' => null,
            'config' => ['temperature' => 0.2, 'num_predict' => -1],
        ];
    }

    private function defaultQaVerifierAgent(): array
    {
        return [
            'name' => 'QA Верификатор',
            'type' => self::QA_VERIFIER_TYPE,
            'role' => 'Проверява качеството на финалния коригиран изход. Оценява дали текстът отговаря на задачата, тона, формата и минималния праг за качество.',
            'capabilities' => ['оценка на качество', 'проверка на изисквания', 'финална верификация'],
            'strengths' => 'Открива пропуски в задачата, проблеми с формата и ниско качество на финалния изход.',
            'limitations' => 'Не редактира текста директно, а само оценява дали е готов за използване.',
            'input_description' => 'Финалният коригиран текст от предходния агент.',
            'output_description' => 'QA оценка и резултат за преминаване според зададения праг.',
            'prompt_template' => 'Оцени качеството на следния финален текст по скала 0-100: {{input}}. Провери дали текстът изпълнява целта на flow-а, дали е ясен, полезен, правилно форматиран и без сериозни езикови проблеми. Върни структурирана оценка с кратко обяснение и pass/fail резултат според прага.',
            'system_prompt' => 'Ти си QA специалист за AI-generated съдържание. Проверяваш финалния изход обективно по качество, релевантност, яснота, формат и език. Не пренаписваш съдържанието, а оценяваш дали е готово за употреба.',
            'model' => $this->modelSelector->selectModel(self::QA_VERIFIER_TYPE),
            'model_reason' => 'Избран е лек и бърз модел за финална QA проверка.',
            'order' => 1,
            'is_verifier' => true,
            'qa_threshold' => self::DEFAULT_QA_THRESHOLD,
            'config' => ['temperature' => 0.1, 'num_predict' => 500],
        ];
    }
}
