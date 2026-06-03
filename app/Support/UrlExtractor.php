<?php

namespace App\Support;

class UrlExtractor
{
    /**
     * Extract all http(s) URLs from free text, de-duplicated and
     * stripped of trailing punctuation.
     *
     * @return array<int, string>
     */
    public static function all(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        preg_match_all('/https?:\/\/[^\s<>"\'\)]+/i', $text, $matches);

        $urls = array_map(
            static fn (string $url): string => rtrim($url, '.,;:!?'),
            $matches[0]
        );

        return array_values(array_unique($urls));
    }

    /**
     * Return the first http(s) URL found in the text, or null.
     */
    public static function first(string $text): ?string
    {
        return self::all($text)[0] ?? null;
    }
}
