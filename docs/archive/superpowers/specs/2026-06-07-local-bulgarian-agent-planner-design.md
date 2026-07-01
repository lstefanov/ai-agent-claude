# Spec: Reliable Bulgarian agent generation (local strong-model planner + translation)

## Problem

`plan-ab` planning with the local `todorov/bggpt:Gemma-3-12B-IT-Q5_K_M` is unreliable:
sometimes < 3 agents ("Planner върна по-малко от 3 агента"), hallucinated facts (claims
the `.bg` site is Russian), English `name`/`role`/prompts, duplicate single-purpose
agents ("Генератор хаштагове BG" + "...BG2"), and a save step where the persisted flow
differs from the A/B preview. No single **local** model is both strong at structure and
fluent in Bulgarian (bake-off: qwen2.5:14b = clean structure but English; gemma3:12b =
invalid JSON; qwq:32b / deepseek-r1 = HTTP 400 on structured output; BgGPT = Bulgarian
but unreliable structure).

## Goal

Generate correct, Bulgarian agent flows from a flow's topic, reliably, using only local
models. Decided approach (user): **strong model + translate only the output.**

## Approach — separate STRUCTURE (hard) from LANGUAGE (mechanical)

1. **Plan** with a strong local model, optimised for structure + grounding (language is
   irrelevant here — translated afterwards). Keep the existing deterministic guards
   (slugify ids, type-recovery, role-based DAG ordering, URL-sanitize) as a safety net.
2. **Translate** only human-readable fields EN→BG after finalization, via `aya-expanse:8b`
   using the existing concurrent `OllamaService::chatBatch()`. Identifiers and
   `{{placeholders}}` are never translated; already-Cyrillic fields are skipped.
3. **Runtime** unchanged — agents already carry `output_language=bg`.

## Components

### 1. Planner model — bake-off, then set `OLLAMA_PLANNER_MODEL`
Candidates (all installed on the server): **qwen2.5:14b** (baseline), **phi4:14b**,
**llama3.1:70b**, **gemma4** (E4B). Score the `pipeline_design` phase on flow 19 (+1–2
others): structured output works (no HTTP 400), clean snake_case ids, valid acyclic DAG,
correct/sensible types, ≥ 3 agents, no duplicate single-purpose agents, no hallucinated
facts/URLs, latency. Pick the best. Caveat: `gemma4` has *thinking* → may reject
`format=schema` like qwq/deepseek; test before trusting. Read-only `OllamaService::chat`
calls, no DB writes.

### 2. Bulgarian translation pass — NEW
- Where: after `AgentGeneratorService::finalizePlannedAgents()` (a new `translateAgentsToBulgarian()`
  step), so it runs for every generated plan (plan-ab and normal generation).
- Fields translated: `name`, `role`, `system_prompt`, `prompt_template`,
  `qa_custom_prompt`, `output_description`. NOT: `uid`, `type`, `depends_on`, `config`.
- Mechanism: build one `chatBatch()` request per field (or per agent) → `aya-expanse:8b`
  (from ModelSelector `translate` profile). System prompt: "Translate to Bulgarian.
  Keep every `{{...}}` placeholder and any URL verbatim. Return only the translation."
- Robust: skip a field already containing Cyrillic; on any failure keep the original
  text (never crash, never blank a prompt).

### 3. Dedup of duplicate single-purpose agents
Extend `AgentGeneratorService::dedupeAgents()`: for a curated set of single-purpose
types (e.g. `hashtag_generator`, `faq_generator`, `meta_generator`, `image_prompt`, …)
keep only the first occurrence per type (regardless of name). `bg_text_corrector` /
`qa_verifier` are already singletonised by the ensure* methods.

### 4. "< 3 agents" handling
In `FlowPlannerService::plan()`, if `designPipeline()` yields < 3 agents, retry the
design phase once before aborting; keep a clear error if it still under-produces. A
strong model rarely under-produces, so this is a safety net.

### 5. Save-discrepancy (saved flow ≠ A/B preview) — investigate + fix
`FlowGraphController::store()` persists whatever graph the builder JS posts via
`GraphNormalizer::sync()`. Investigate the staging → builder-JS → normalizer path
(`resources/views/flows/builder.blade.php` consuming `stagedAgents`, and the normalizer).
Hypothesis: BgGPT's duplicate/odd uids made the normalizer merge/drop nodes on save;
clean planner output + dedup likely resolves it. Fix any real mismatch found.

## Data flow

`flow.description (BG)` → intent_analysis → pipeline_design → critique (strong model, EN)
→ `finalizePlannedAgents` (guards + dedup) → **translate fields EN→BG** → cache/stage →
builder → save (`normalizer->sync`) → nodes/edges.

## Error handling

- Translator failure per field → keep original text.
- Planner structured-output failure → existing `chatJsonOllama` retry (repeat_penalty).
- < 3 agents → retry design once, then clear error.

## Verification (manual — repo rule: NO automated tests)

- Bake-off table per candidate (works?, ids, DAG, types, dups, halluc, ≥3, latency).
- End-to-end flow 19 with the winner: ≥ 3 agents; `name`/`role`/prompts Bulgarian
  (Cyrillic), no "Russian"/foreign facts, no duplicates, correct role-tier order, valid
  DAG; `{{url}}`→primelaser.bg.
- Apply → save → reload: persisted nodes == preview (count + uids + names).
- `php -l` + `vendor/bin/pint` clean.

## Out of scope

OpenAI 429 quota; plan-ab requiring ≥ 2 providers for a full A/B comparison.
