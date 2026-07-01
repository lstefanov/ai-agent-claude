---
name: add-mcp-connector
description: >
  Use this skill when adding an MCP connector for an external app in FlowAI, for requests
  like "add a connector", "integrate Slack/Notion/Gmail/Sheets", "add an MCP integration",
  or "let companies connect app X". Covers app/Services/Mcp/Connectors and config/mcp.php.
---

# Add a FlowAI MCP connector

Add an external-app connector that agents and the org layer can act through.

## Files to touch

- `app/Services/Mcp/Connectors/XxxConnector.php` - the connector.
- `config/mcp.php` - register it in the connector registry.

## Steps

1. Create `app/Services/Mcp/Connectors/XxxConnector.php` extending `AbstractConnector` and satisfying `McpConnectorInterface`.
   Model it on `GmailConnector`, `GoogleSheetsConnector`, `GoogleDriveConnector`, `GoogleDocsConnector`, or `GoogleCalendarConnector`.
2. Register the connector in `config/mcp.php` (`registry`, `tool_namespaces`, `write_tools`, and the `catalog` tile).
3. Resolve tool parameters with `McpParamResolver` and return results as `McpToolResult`.

## Guardrails

- All calls go through `McpClientService`; do not call a connector directly from a controller or agent.
- Per-company state lives in `CompanyConnector`; every call is audited in `ConnectorToolLog`.
- Write tools must honor `OrgActPolicy` act-mode before touching the real world.
- Outbound targets pass through `SsrfGuard`.
- See `docs/MCP-CONNECTORS.md`.

## Verify

- `php -l` on the connector.
- Do not run tests or eval suites.
