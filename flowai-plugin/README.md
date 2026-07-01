# FlowAI plugin

Project-specific skills for building and operating the FlowAI app: a Laravel 12 and PHP 8.2 multi-agent workflow platform.
Each skill encodes the repository's service boundaries and non-negotiable conventions so the same task is done the same way every time.

## Skills

Build primitives
- add-agent - new agent type: class, AgentFactory, config/agent_types.php.
- add-agent-tool - new tool for the agent loop under app/Agents/Tools.
- add-llm-provider - new cloud provider behind GeneratorService and the model router.
- add-mcp-connector - new external-app connector under app/Services/Mcp/Connectors.
- add-queued-job - background job on the correct Horizon queue.
- add-model-migration - Eloquent model and migration, reset-first convention.

Org and billing
- add-billable-operation - meter work through BillableOperationService.
- add-payment-provider - fund wallets via the PaymentProvider contract.
- add-org-mutation - change org structure through OrgProposal and OrgEvent.

Surfaces, planner, knowledge, graph
- add-http-surface - route, thin controller, and view, with Tailwind v4 and accessibility rules.
- planner-hardening - change the planner and keep its output trusted.
- knowledge-ingest - extend the knowledge ingest pipeline.
- graph-normalizer-work - handle Drawflow graphs and the builder data contract through GraphNormalizer.

Frontend
- ui-builder - Blade, Tailwind v4, Alpine, and Drawflow UI work, with a design-system, drawflow-guardrails, and ui-workflows reference set.

Guardrails and ops
- flowai-guardrail-check - review a diff against the non-negotiable rules.
- verify-changes - verify using only the allowed commands, with a final sanity checklist.
- debug-stuck-flow - diagnose stuck runs, stalled queues, and provider routing.

## Setup

No configuration or credentials are required.
The skills reference the FlowAI repository layout and trigger on the phrasing in each description.

## Usage

Ask for the task in natural language, for example "add a new agent" or "style this admin screen", and the matching skill loads.
The ui-builder skill uses progressive disclosure: its SKILL.md routes to detailed references under skills/ui-builder/references.

## Maintenance

Add a new skill by creating skills/<name>/SKILL.md with a name and a third-person description full of trigger phrases.
Re-package by zipping the plugin directory into flowai.plugin.
