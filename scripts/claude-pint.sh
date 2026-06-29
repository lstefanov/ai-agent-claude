#!/usr/bin/env bash
# PostToolUse(Write|Edit|MultiEdit) hook: auto-format edited PHP files with Pint.
# Receives the tool-call JSON on stdin. Never blocks (always exit 0).
set -uo pipefail
input=$(cat)
f=$(printf '%s' "$input" | jq -r '.tool_input.file_path // empty' 2>/dev/null)
case "$f" in
  *.php)
    [ -f "$f" ] && "${CLAUDE_PROJECT_DIR:-.}/vendor/bin/pint" "$f" >/dev/null 2>&1 || true
    ;;
esac
exit 0
