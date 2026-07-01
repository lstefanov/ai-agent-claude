---
name: add-org-mutation
description: >
  Use this skill when changing AI Organization structure in FlowAI, for requests like
  "add an org mutation", "change the org structure", "add a director or assistant action",
  or "propose an org change". Covers OrgProposal and OrgEvent, budget caps, and act-mode.
---

# Add a FlowAI org mutation

Change organization structure through the proposal and event flow.

## Files to touch

- `app/Services/Org/OrgMutationService.php` and `ApprovalService.php`.
- Jobs in `app/Jobs/Org/` for autonomous work; they run on the `org` queue.

## Steps

1. Express every structural change as an `OrgProposal`, then record the applied change in `OrgEvent`.
2. Treat `OrgVersion` as an immutable snapshot; `OrgMember` is the stable identity while `Director` and `Assistant` are placements inside a version.
3. Route autonomous mutations through the approval path.

## Guardrails

- Autonomous work must pass `AutonomousBudgetService` caps.
- Manual chat, manual ticks, and manual task runs bypass autonomous budget caps by design.
- Real-world connector writes must respect `OrgActPolicy`.
- See the `docs/AI-ORGANIZATION-*` docs.

## Verify

- `php -l` on changed files.
- Do not run tests or eval suites.
