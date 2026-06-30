<?php

namespace App\Support;

/**
 * Помощник за JSON Schema-та, подавани на LLM structured outputs.
 */
class JsonSchema
{
    /**
     * Прави JSON Schema стриктно-съвместима с OpenAI Structured Outputs (`strict: true`):
     * рекурсивно сетва `additionalProperties: false` + `required` = ВСИЧКИ ключове на всеки
     * обект. БЕЗ това OpenAI връща 400 и call-ът пада към fallback (виж org_design тънките
     * персони). Идемпотентно за вече съвместими схеми.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function strict(array $schema): array
    {
        if (($schema['type'] ?? null) === 'object' && isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $k => $prop) {
                $schema['properties'][$k] = self::strict((array) $prop);
            }
            $schema['required'] = array_keys($schema['properties']);
            $schema['additionalProperties'] = false;
        } elseif (($schema['type'] ?? null) === 'array' && isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = self::strict((array) $schema['items']);
        }

        return $schema;
    }
}
