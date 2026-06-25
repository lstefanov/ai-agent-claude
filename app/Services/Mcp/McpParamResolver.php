<?php

namespace App\Services\Mcp;

use App\Models\FlowRun;

/**
 * Резолвира placeholder-ите в `tool_params` на mcp_action node-а ПРЕДИ tool
 * call-а. Работи СЛЕД fan-in, така че {{agent.X.output}} е наличен.
 *
 * Поддържани:
 *   {{flow.input.X}}            — от run seed-а (входните variables)
 *   {{agent.KEY.output}}        — изход на ЛЮБОЙ завършил възел (KEY = node_key ИЛИ име)
 *   {{report}} / {{flow.output}} — финалният доклад на run-а (последния body възел)
 *   {{connector.setting.X}}     — от CompanyConnector.settings
 *   {{flow.setting.X}}          — от FlowNode.config['flow_settings']
 *   {{date:FORMAT}}             — текуща дата (PHP date format)
 *
 * Сигурност: {{credentials.*}} / {{connector.credentials.*}} хвърлят — tokens
 * никога не влизат в params.
 */
class McpParamResolver
{
    /**
     * @param  array<string,mixed>  $params
     * @param  array<string,string>  $nodeOutputs  изходите на завършилите възли, keyed by node_key И/ИЛИ име
     * @param  array<string,mixed>  $connectorSettings
     * @param  array<string,mixed>  $flowSettings
     * @return array<string,mixed>
     */
    public function resolve(
        array $params,
        FlowRun $run,
        array $nodeOutputs,
        array $connectorSettings = [],
        array $flowSettings = [],
        string $reportOutput = '',
    ): array {
        return $this->walk($params, $run, $nodeOutputs, $connectorSettings, $flowSettings, $reportOutput);
    }

    private function walk(array $params, FlowRun $run, array $pred, array $cs, array $fs, string $report): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $out[$key] = $this->walk($value, $run, $pred, $cs, $fs, $report);
            } elseif (is_string($value)) {
                $out[$key] = $this->resolveString($value, $run, $pred, $cs, $fs, $report);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function resolveString(string $value, FlowRun $run, array $pred, array $cs, array $fs, string $report): string
    {
        return preg_replace_callback('/\{\{\s*(.+?)\s*\}\}/u', function (array $m) use ($run, $pred, $cs, $fs, $report): string {
            $expr = trim($m[1]);

            // Сигурност: credentials никога не се резолвират в params.
            if (preg_match('/^(connector\.)?credentials\b/i', $expr)) {
                throw new \RuntimeException("Забранен placeholder в MCP параметри: {{{$expr}}}");
            }

            if (preg_match('/^date:(.+)$/', $expr, $dm)) {
                return now()->format($dm[1]);
            }
            // Финалният доклад на run-а — удобен таг, за да не трябва да се знае
            // точното име на възела.
            if (preg_match('/^(report|flow\.output)$/i', $expr)) {
                return $report;
            }
            if (preg_match('/^flow\.input\.(.+)$/u', $expr, $im)) {
                return $this->stringify(data_get($run->context['seed'] ?? [], $im[1]));
            }
            if (preg_match('/^agent\.(.+)\.output$/u', $expr, $am)) {
                return $this->stringify($pred[$am[1]] ?? '');
            }
            if (preg_match('/^connector\.setting\.(.+)$/u', $expr, $om)) {
                return $this->stringify(data_get($cs, $om[1]));
            }
            if (preg_match('/^flow\.setting\.(.+)$/u', $expr, $sm)) {
                return $this->stringify(data_get($fs, $sm[1]));
            }

            // Непознат placeholder — оставяме го непроменен (видим за дебъг).
            return $m[0];
        }, $value);
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
}
