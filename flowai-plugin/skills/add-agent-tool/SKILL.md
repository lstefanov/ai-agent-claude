---
name: add-agent-tool
description: >
  Use this skill when adding a new tool that agents can call in FlowAI, for requests like
  "add an agent tool", "create a new tool", "give agents a web/scrape/search capability",
  or "expose X to the agent loop". Covers app/Agents/Tools and wiring into AgentFactory.
---

# Add a FlowAI agent tool

Add a callable tool that agents invoke through the `AgentLoop` tool-calling loop.

## Files to touch

- `app/Agents/Tools/XxxTool.php` - the tool.
- `app/Agents/AgentFactory.php` - pass the tool to the agents that should have it.
- A backing service in `app/Services/` when the tool calls an external system.

## Steps

1. Create `app/Agents/Tools/XxxTool.php` following the `AgentTool` contract.
   Model it on `WebSearchTool`, `BraveSearchTool`, or `KnowledgeSearchTool`.
2. Expose a stable tool name, a description the model can select on, and a parameter schema.
3. Implement the invoke path by delegating to a service, not by calling the network inline.
4. Wire the tool into the relevant agents via the `AgentFactory` constructor tool arrays.

## Guardrails

- Keep external calls behind a service, for example `BraveSearchService` or `PerplexitySearchService`.
- Web and file fetches must pass through `SsrfGuard` to block unsafe internal targets.
- A tool is not an agent; the agent decides which tools to call while the tool just does one job.

## Verify

- `php -l app/Agents/Tools/XxxTool.php`.
- Do not run tests or eval suites.
