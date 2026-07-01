---
description: Lighter pipeline for a bug fix (diagnose -> fix -> review -> non-test verify), no full plan cycle, stops before commit
argument-hint: [bug description / error / failing behaviour]
allowed-tools: Read, Write, Edit, Grep, Glob, Bash, Task
model: sonnet
---

Bug: $ARGUMENTS

Fix this on the current branch/worktree. Keep it minimal and local — never commit or push (a hook blocks it). Skip the full planning ceremony.

1. **Locate the root cause.** Read the relevant code and reproduce mentally (routes, services, jobs, models, views). If the cause is obvious, note it in one or two sentences and move on. Only if it is genuinely non-trivial, use the **planner** subagent for a SHORT root-cause note (no full PLAN.md, no new feature design).
2. **implementer** subagent -> apply the smallest correct fix. No refactors beyond the bug. Delete any dead/legacy path you replace (no back-compat). LLM only via services; validate LLM output; design tokens only; Bulgarian comments / English identifiers.
3. **code-reviewer** subagent. Add **security-reviewer** and/or **external-reviewer** (`scripts/ai-review.sh review`) ONLY if the fix touches auth, input, DB, uploads, webhooks, or LLM/tool wiring. If any returns `FAIL`/`CRITICAL`, back to step 2 (max 1 loop).
4. **qa-checker** subagent -> non-test verification (`pint --test`, `php -l`, artisan sanity) plus a concrete manual repro checklist proving the bug is gone.
5. Show `git diff --stat` and STOP. Do NOT commit — wait for my review.

Hard rules: no tests, no legacy/back-compat, LLM only via services, design tokens only.
