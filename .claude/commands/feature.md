---
description: Run the full multi-agent pipeline for a FlowAI change (plan -> review -> implement -> review -> QA -> docs), stopping before commit
argument-hint: [what to build or change]
allowed-tools: Read, Write, Edit, Grep, Glob, Bash, Task
model: opus
---

Task: $ARGUMENTS

Orchestrate this pipeline on the current branch/worktree, looping back on failures. Keep ALL work local — never commit or push (a hook blocks it).

1. **planner** subagent -> write PLAN.md.
2. **plan-reviewer** subagent -> if `CHANGES-REQUESTED`, send the list back to **planner** and repeat (max 2 loops).
3. **external-reviewer** subagent (`scripts/ai-review.sh plan`) -> cross-vendor critique of PLAN.md. Fold the valid points into PLAN.md.
4. When the plan is solid, **implementer** subagent -> write the code per PLAN.md.
5. In parallel, run **code-reviewer** + **security-reviewer** + **external-reviewer** (`scripts/ai-review.sh review`). If any returns `FAIL`/`CRITICAL`, send the specifics back to **implementer** (step 4). Max 2 loops.
6. **qa-checker** subagent -> non-test verification. If `QA-FAIL`, back to **implementer**.
7. **doc-writer** subagent -> update docs.
8. Show `git diff --stat` and STOP. Do NOT commit — wait for my review and approval.

Hard rules to enforce throughout: no tests, no legacy/back-compat, LLM only via services, validate every LLM output, design tokens only, Bulgarian comments / English identifiers.
