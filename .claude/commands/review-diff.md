---
description: Fast multi-perspective review of the current uncommitted diff (Claude code + security review + cross-vendor second opinion)
allowed-tools: Read, Grep, Glob, Bash, Task
model: sonnet
---

Review the current uncommitted changes (`git diff HEAD`). Run these in parallel:

1. **code-reviewer** subagent.
2. **security-reviewer** subagent.
3. **external-reviewer** subagent (`scripts/ai-review.sh review`) for a cross-vendor opinion.

Consolidate into one report: `CRITICAL` / `WARNING` / `SUGGESTION` with `file:line`, noting where reviewers **agree** (higher confidence) or **disagree**. End with a prioritized fix list. Do not modify code, do not commit, do not run tests.
