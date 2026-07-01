---
name: planner-hardening
description: >
  Use this skill when changing the FlowAI dynamic planner, for requests like "change the
  planner", "improve plan generation", "fix the DAG the planner produces", or "harden
  planner output". Covers FlowPlannerService, PlanGraphBuilder, and AgentGeneratorService.
---

# Harden the FlowAI planner

Change how flows are planned while keeping planner output trustworthy.

## The pipeline

1. `FlowPlannerService` runs intent analysis, pipeline design, and critique.
2. `PlanGraphBuilder` assembles planner output into graph shape.
3. `AgentGeneratorService` hardens the plan into a safe, runnable DAG.
4. `GeneratorService` selects the provider used for planning.

## Steps

1. Make planning changes in `FlowPlannerService`, keeping the three stages distinct.
2. If graph shape changes, update `PlanGraphBuilder`.
3. Add or update deterministic validation in `AgentGeneratorService` for any new field or structure.

## Guardrails

- Treat all planner output as untrusted.
- Validate and normalize structure in deterministic code, especially in `AgentGeneratorService`; never trust the model to return valid shape.
- `PlanLibraryService` stores proven plans and `FlowMemoryService` stores run-to-run memory; feed improvements back rather than hardcoding.
- See `docs/DYNAMIC-AGENT-PLANNER.md`.

## Verify

- `php -l` on changed files.
- Do not run tests or eval suites.
