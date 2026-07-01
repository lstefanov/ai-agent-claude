# Drawflow Guardrails

## Critical Rule

The Drawflow graph is a product data contract, not just a visual canvas.
`GraphNormalizer` is the only backend place that should understand Drawflow export format.
Builder UI work must not mutate graph identity, data contracts, or export shape unless the user explicitly requested a graph data model change.

## Files And Boundaries

- Main builder view: `resources/views/flows/builder.blade.php`.
- DAG preview partials may live under `resources/views/flows/partials`.
- Graph persistence and parse behavior belongs to `app/Services/GraphNormalizer.php`.
- Planner to graph assembly belongs to `PlanGraphBuilder`.
- Runtime execution belongs to `GraphFlowExecutor` and `NodeExecutorService`.

## Safe Builder Changes

- Restyle toolbar, panels, controls, labels, spacing, focus states, empty states, and responsive chrome.
- Improve accessible labels and button states.
- Move visual CSS into Vite-managed CSS when it does not change graph behavior.
- Add non-invasive status indicators that read existing state.
- Improve loading, disabled, and error presentation.

## Dangerous Builder Changes

- Changing Drawflow node ids.
- Changing `data-*` attributes used by JavaScript or persistence.
- Changing node `data` shape.
- Changing input or output port names.
- Changing export JSON shape.
- Changing save payload shape.
- Changing JavaScript that imports, exports, or serializes graph data without auditing `GraphNormalizer`.
- Moving Drawflow CSS earlier than the CDN styles when an override depends on cascade order.

## Tailwind And CSS

Drawflow uses third-party CSS plus local overrides.
Load overrides after the Drawflow CDN stylesheet.
Prefer token-driven CSS and Tailwind utilities for surrounding UI.
Use custom CSS for Drawflow internals when Tailwind cannot target third-party markup safely.
Do not build dynamic Tailwind utility names in builder JavaScript unless they are safelisted.

## Manual Smoke Path

Use this manual reasoning path after builder changes:

1. Generate agents for a flow.
2. Confirm the graph renders.
3. Edit a node or connection if the changed UI touches editing.
4. Save the graph.
5. Reload the builder.
6. Confirm the graph shape and node config persist.
7. Run the flow.
8. Confirm run creation reaches `GraphFlowExecutor`.

Do not run automated tests for this repo.
If queued execution is involved, Horizon must be running.
