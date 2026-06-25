<?php

namespace App\Agents;

use App\Models\CompanyConnector;
use App\Models\FlowNode;
use App\Models\FlowRun;
use App\Services\Mcp\McpParamResolver;
use App\Services\Mcp\McpToolResult;
use App\Services\McpClientService;

/**
 * Изпълнява един mcp_action node: резолвира tool_params (variables) и вика
 * McpClientService. НЕ е BaseAgent — извиква се директно от NodeExecutorService
 * (като human_approval, прихваща се преди AgentFactory). Не генерира текст;
 * изходът е човешко-четимото описание на резултата.
 */
class McpActionAgent
{
    public function __construct(
        private McpClientService $mcp,
        private McpParamResolver $resolver,
    ) {}

    /**
     * @param  array<string,string>  $predecessorOutputs  изходите на завършилите възли, keyed by node_key И име
     */
    public function run(FlowNode $node, FlowRun $run, array $predecessorOutputs, ?int $nodeRunId = null, string $reportOutput = ''): McpToolResult
    {
        $config = (array) $node->config;
        $connectorId = (int) ($config['connector_id'] ?? 0);
        $tool = (string) ($config['tool'] ?? '');
        $rawParams = (array) ($config['tool_params'] ?? []);
        $flowSettings = (array) ($config['flow_settings'] ?? []);

        if ($connectorId === 0 || $tool === '') {
            return McpToolResult::fail('mcp_action node без connector_id или tool');
        }

        $connector = CompanyConnector::where('company_id', $run->flow?->company_id)->find($connectorId);
        if (! $connector) {
            return McpToolResult::fail("Конектор #{$connectorId} не съществува за тази фирма");
        }
        if ($connector->status !== 'active') {
            return McpToolResult::fail("Конектор „{$connector->display_name}\" не е активен (статус: {$connector->status})");
        }

        try {
            $params = $this->resolver->resolve(
                $rawParams,
                $run,
                $predecessorOutputs,
                (array) $connector->settings,
                $flowSettings,
                $reportOutput,
            );
        } catch (\Throwable $e) {
            return McpToolResult::fail("Резолюция на параметри: {$e->getMessage()}");
        }

        return $this->mcp->callTool(
            connector: $connector,
            tool: $tool,
            params: $params,
            flowRunId: $run->id,
            nodeRunId: $nodeRunId,
        );
    }
}
