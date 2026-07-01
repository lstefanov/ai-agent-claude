---
name: add-llm-provider
description: >
  Use this skill when integrating a new cloud LLM provider into FlowAI, for requests like
  "add a provider", "integrate a new model backend", "wire another LLM", or "route some
  nodes to provider X". Covers the chat service, GeneratorService, and the model router.
---

# Add a FlowAI LLM provider

Add a new cloud provider and make it selectable for agent nodes.

## Files to touch

- `app/Services/XxxChatService.php` - the provider client.
- `app/Services/GeneratorService.php` - provider selection for generation.
- `app/Services/ModelRouterService.php` and `config/model_router.php` - routing by task profile.

## Steps

1. Create `app/Services/XxxChatService.php` mirroring `OpenAiChatService` and `AnthropicChatService`.
2. Add provider selection through `GeneratorService` so planning and generation can pick it.
3. For agent-node execution, add the provider to `ModelRouterService` and give it a task profile in `config/model_router.php`.
4. Keep local and paid-prefixed runtime routing consistent with `OllamaService`, which owns that dispatch.

## Guardrails

- All provider calls stay behind a service; never call an SDK from a controller, job, or agent directly.
- Paid usage accumulates in `LlmUsage`; metered work reserves and settles through `BillableOperationService`.
- Never debit a `CreditWallet` directly.

## Verify

- `php -l` on the new service.
- Do not run tests or eval suites.
