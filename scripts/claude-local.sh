#!/usr/bin/env bash
# Run Claude Code against a LOCAL Ollama model in an ISOLATED env.
# Setting ANTHROPIC_* globally BREAKS your Claude Max OAuth — so never put these
# in ~/.zshrc. Launch this in its OWN terminal for cheap local "grunt work".
#
# Usage:  scripts/claude-local.sh [claude args...]
# Config: OLLAMA_URL (endpoint), CLAUDE_LOCAL_MODEL (default qwen3-coder:30b)
set -uo pipefail

OLLAMA_ENDPOINT="${OLLAMA_URL:-http://192.168.0.19:11434}"   # your Ollama host (see .env)
MODEL="${CLAUDE_LOCAL_MODEL:-qwen3-coder:30b}"
export OLLAMA_CONTEXT_LENGTH="${OLLAMA_CONTEXT_LENGTH:-65536}"

exec env ANTHROPIC_BASE_URL="$OLLAMA_ENDPOINT" \
         ANTHROPIC_AUTH_TOKEN="ollama" \
         ANTHROPIC_API_KEY="" \
         claude --model "$MODEL" "$@"
