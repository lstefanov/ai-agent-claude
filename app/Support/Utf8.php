<?php

namespace App\Support;

/**
 * Scrubs invalid UTF-8 byte sequences so a value can be json_encode()'d safely.
 *
 * Live web content (scraped pages, search snippets) routinely carries malformed
 * UTF-8 — wrong server charset, truncated multibyte sequences, binary fragments.
 * When such bytes reach Guzzle's request serializer (GuzzleHttp\Utils::jsonEncode)
 * it throws "json_encode error: Malformed UTF-8 characters", killing the node.
 *
 * This is the single chokepoint every runtime LLM client runs its payload through
 * right before the HTTP call, so no source of bad bytes (tool output, seed input,
 * knowledge chunks) can ever blow up the request.
 *
 * PHP 8.2 has no mb_scrub(); mb_convert_encoding(UTF-8 → UTF-8) is the established
 * equivalent — it replaces/drops invalid sequences using mb_substitute_character.
 */
final class Utf8
{
    public static function clean(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::clean($item);
            }

            return $value;
        }

        if (is_string($value) && $value !== '' && ! mb_check_encoding($value, 'UTF-8')) {
            return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        return $value;
    }
}
