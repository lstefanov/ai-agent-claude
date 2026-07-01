---
name: graph-normalizer-work
description: >
  Use this skill when working with the Drawflow graph in FlowAI, for requests like "change
  how graphs are saved", "parse the Drawflow export", "fix node or edge persistence", "work
  on the builder graph", or "the builder UI". Covers GraphNormalizer, flow_nodes/flow_edges,
  and the builder data contract.
---

# Work on the FlowAI graph format

Handle Drawflow graph payloads, their persistence, and the builder UI safely.

## The rule

`GraphNormalizer` is the only place that should understand the Drawflow export format.
Saving a graph goes through `GraphNormalizer` into `flow_nodes` and `flow_edges`.

## Backend steps

1. Put any Drawflow parsing or shaping inside `GraphNormalizer`; never parse the export ad hoc in a controller, job, or view.
2. Persist normalized output to `flow_nodes` and `flow_edges`.
3. `PlanGraphBuilder` assembles planner output into Drawflow export shape; `GraphFlowExecutor` and `NodeExecutorService` own runtime.
4. Use `FlowVersionService` to snapshot or restore flow templates.

## The builder UI is a data contract

The Drawflow graph is a product data contract, not just a visual canvas.
The builder view is `resources/views/flows/builder.blade.php`; preview partials may live under `resources/views/flows/partials`.

Safe builder changes: restyle toolbar, panels, controls, labels, spacing, focus and empty states, responsive chrome, and accessible labels.

Dangerous changes, so audit `GraphNormalizer` first: node ids, `data-*` attributes, node `data` shape, input or output port names, export JSON shape, save payload shape, and any JavaScript that imports, exports, or serializes graph data.

Load Drawflow CSS overrides after the CDN stylesheet when a cascade override depends on order.

## Manual smoke path

After builder changes, reason through: generate agents, confirm the graph renders, edit a node or connection, save, reload the builder, confirm shape and node config persist, run the flow, and confirm the run reaches `GraphFlowExecutor`.
Horizon must be running for queued execution.

## Guardrails

- Do not duplicate Drawflow knowledge anywhere else in the codebase.
- Treat the builder payload as untrusted input and validate it during normalization.
- When you change behavior, remove the old path rather than keeping a fallback.

## Verify

- `php -l` on changed files; static inspection and `git diff --check`.
- Do not run tests or eval suites.
