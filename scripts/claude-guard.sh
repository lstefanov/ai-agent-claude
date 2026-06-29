#!/usr/bin/env bash
# PreToolUse(Bash) guard. Enforces two project rules deterministically:
#   1) work stays local  -> block `git commit` / `git push`
#   2) no tests          -> block `php artisan test`, phpunit, pest, `composer test`
# Receives the tool-call JSON on stdin. Exit 2 = block the command.
set -uo pipefail
input=$(cat)
cmd=$(printf '%s' "$input" | jq -r '.tool_input.command // empty' 2>/dev/null)

if printf '%s' "$cmd" | grep -qiE '(^|[;&|[:space:]])git[[:space:]]+(push|commit)([[:space:]]|$)'; then
  echo "Blocked: git commit/push — работата остава локална. Преглеждаш diff-а и commit-ваш сам." >&2
  exit 2
fi

if printf '%s' "$cmd" | grep -qiE '(php[[:space:]]+artisan[[:space:]]+test|phpunit|(^|[;&|[:space:]/])pest([[:space:]]|$)|composer[[:space:]]+test)'; then
  echo "Blocked: тестове са забранени в този проект (CLAUDE.md). Верификацията е без тестове — виж qa-checker." >&2
  exit 2
fi

exit 0
