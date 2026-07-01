#!/usr/bin/env bash
# Cross-vendor "second opinion" reviewer for FlowAI.
# Sends the current git diff (or PLAN.md) to a DIFFERENT vendor's model
# (Codex / Cursor / Antigravity) and prints its findings —
# vendor diversity catches bugs a single model misses.
#
# Usage:
#   scripts/ai-review.sh review            # review the uncommitted diff (default)
#   scripts/ai-review.sh plan              # critique PLAN.md
#   scripts/ai-review.sh "<free text>"     # custom instruction over the diff
#   REVIEWER=codex scripts/ai-review.sh review     # force one vendor
#   scripts/ai-review.sh --all review              # run every available vendor
#
# Vendor auto-detect order: codex -> cursor-agent -> agy.

set -uo pipefail
cd "$(git rev-parse --show-toplevel 2>/dev/null || echo .)"

ALL=0
[ "${1:-}" = "--all" ] && { ALL=1; shift; }
MODE="${1:-review}"

# --- build the prompt -------------------------------------------------------
case "$MODE" in
  review)
    PAYLOAD=$(git diff HEAD); [ -z "$PAYLOAD" ] && PAYLOAD=$(git diff)
    [ -z "$PAYLOAD" ] && { echo "Няма промени за ревю (git diff е празен)."; exit 1; }
    INSTRUCTION="You are a senior Laravel reviewer. Review this FlowAI diff for bugs, security issues (SQL injection, IDOR/company-scoping, SSRF, prompt injection), N+1 queries, missing validation/authorization, and convention violations (thin controllers, business logic in services, LLM only via services, no tests, no hardcoded hex). Be specific: file, line, fix. List only real problems."
    CONTENT=$'```diff\n'"$PAYLOAD"$'\n```' ;;
  plan)
    [ -f PLAN.md ] || { echo "PLAN.md не е намерен."; exit 1; }
    INSTRUCTION="You are a senior Laravel architect. Critique this implementation plan for missing edge cases, risks, wrong abstractions, and convention violations. Be specific and concise."
    CONTENT=$(cat PLAN.md) ;;
  *)
    PAYLOAD=$(git diff HEAD)
    INSTRUCTION="$MODE"
    CONTENT=$'```diff\n'"$PAYLOAD"$'\n```' ;;
esac
PROMPT="$INSTRUCTION"$'\n\n'"$CONTENT"

# --- config -----------------------------------------------------------------
TIMEOUT="${AI_REVIEW_TIMEOUT:-300}"

have() { command -v "$1" >/dev/null 2>&1; }
run_codex()  { codex exec --skip-git-repo-check "$PROMPT"; }
run_cursor() { timeout "$TIMEOUT" cursor-agent -p "$PROMPT" --output-format text; }
run_agy()    { agy -p "$PROMPT" --output-format text --print-timeout "${TIMEOUT}s"; }

invoke() {
  case "$1" in
    codex)       have codex        && { echo "===== Codex ====="; run_codex; return 0; } ;;
    cursor)      have cursor-agent && { echo "===== Cursor ====="; run_cursor; return 0; } ;;
    antigravity) have agy          && { echo "===== Antigravity ====="; run_agy; return 0; } ;;
  esac
  return 1
}

# --- dispatch ---------------------------------------------------------------
if [ -n "${REVIEWER:-}" ]; then
  invoke "$REVIEWER" || { echo "Reviewer '$REVIEWER' не е наличен (codex/cursor/antigravity)."; exit 1; }
  exit 0
fi

if [ "$ALL" = 1 ]; then
  ran=0
  for v in codex cursor antigravity; do invoke "$v" && ran=1; echo; done
  [ "$ran" = 1 ] || { echo "Няма наличен external reviewer (codex/cursor-agent/agy)."; exit 1; }
  exit 0
fi

for v in codex cursor antigravity; do invoke "$v" && exit 0; done
echo "Няма наличен external reviewer. Инсталирай един от: codex, cursor-agent, agy."
exit 1
