<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Repairs structured-output results where the LLM double-encoded a nested
 * array/object as a JSON string (e.g. Anthropic tool input returning
 * {"agents": "[{...}]"} instead of {"agents": [{...}]}).
 *
 * Walks the JSON schema and json_decodes string values wherever the schema
 * expects an array or object. Values that can't be decoded are left as-is —
 * the planner's own validation handles them downstream.
 */
final class SchemaCoercion
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function coerce(array $data, array $schema): array
    {
        foreach ((array) ($schema['properties'] ?? []) as $key => $propSchema) {
            if (! array_key_exists($key, $data) || ! is_array($propSchema)) {
                continue;
            }

            $data[$key] = self::coerceValue($data[$key], $propSchema, $key);
        }

        return $data;
    }

    private static function coerceValue(mixed $value, array $schema, string $path): mixed
    {
        $type = $schema['type'] ?? null;

        if (! in_array($type, ['array', 'object'], true)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (! is_array($decoded)) {
                return $value;
            }

            Log::warning("[SchemaCoercion] Decoded double-encoded '{$path}' (expected {$type}, got JSON string).");
            $value = $decoded;
        }

        if (! is_array($value)) {
            return $value;
        }

        if ($type === 'object') {
            return self::coerce($value, $schema);
        }

        $itemSchema = $schema['items'] ?? null;

        if (is_array($itemSchema)) {
            foreach ($value as $i => $item) {
                $value[$i] = self::coerceValue($item, $itemSchema, "{$path}[{$i}]");
            }
        }

        return $value;
    }
}
