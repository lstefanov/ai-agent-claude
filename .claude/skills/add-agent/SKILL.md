---
name: add-agent
description: >
  Use this skill when adding a new agent type to the FlowAI engine, for requests like
  "add a new agent", "create an agent type", "register an agent", or "the planner should
  be able to use a new kind of agent". Covers the Agent class, AgentFactory registration,
  and config/agent_types.php.
---

# Add a FlowAI agent

Add a new agent type that the planner can place in a DAG and the runtime can execute.

## Files to touch

- `app/Agents/XxxAgent.php` - the agent class.
- `app/Agents/AgentFactory.php` - register the type in the `make()` match.
- `config/agent_types.php` - declare the type, its `output_role`, label, and description.

## Steps

1. Create `app/Agents/XxxAgent.php` extending `BaseAgent`.
   Model it on an existing agent of the same shape, for example `AnalyzerAgent` for a processor or `ResearcherAgent` for a tool-using gatherer.
2. Register the type string in `AgentFactory::make()` inside the `match ($agent->type)` arm.
   Inject `$this->ollama` and any tools the agent needs, following the existing helper pattern such as `$this->webSearchTool()`.
3. Add the type to `config/agent_types.php` with an `output_role` (`hidden`, `body`, and so on), a `label`, and a `description`.
   The planner reads these descriptions, so write the description as guidance the planner can select on.
4. If the agent needs tools, pass them via the factory constructor arrays, not inline in the agent.

## Guardrails

- Agents run through the shared `AgentLoop` step and tool-calling loop; do not open your own provider connection.
- Route model calls through `OllamaService` (local) or paid-prefixed providers via the router, never a direct SDK call.
- `mcp_action` and `human_approval` are special node types and are never executed as agents; do not model those as agents.
- Treat any planner-provided config as untrusted and validate it in deterministic code.

## Verify

- `php -l app/Agents/XxxAgent.php` and `php -l app/Agents/AgentFactory.php`.
- Do not run tests or eval suites.
