<?php

namespace App\Support;

class PricingOutputMetrics
{
    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function fromOutput(?string $output, array $extra = []): array
    {
        $text = (string) $output;
        $tableRows = self::markdownTableRows($text);
        $sourceDomains = self::sourceDomains($text);

        return array_merge([
            'char_count' => mb_strlen($text),
            'markdown_table_rows' => count($tableRows),
            'priced_rows' => count(array_filter($tableRows, self::hasNumericPrice(...))),
            'source_domains' => $sourceDomains,
            'source_domain_count' => count($sourceDomains),
        ], $extra);
    }

    /**
     * @return array<int, string>
     */
    private static function markdownTableRows(string $text): array
    {
        $rows = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $trimmed = trim($line);
            if (! str_starts_with($trimmed, '|') || ! str_ends_with($trimmed, '|')) {
                continue;
            }

            if (preg_match('/^\|[\s:\-|]+\|$/', $trimmed)) {
                continue;
            }

            if (preg_match('/\|\s*(конкурент|услуга|цена|тип|линк)\s*\|/iu', $trimmed)) {
                continue;
            }

            $rows[] = $trimmed;
        }

        return $rows;
    }

    private static function hasNumericPrice(string $row): bool
    {
        return (bool) preg_match('/\b\d+(?:[,.]\d+)?\s*(?:лв\.?|bgn|eur|€|euro|евро)(?=\s|[|),.;]|$)/iu', $row);
    }

    /**
     * @return array<int, string>
     */
    private static function sourceDomains(string $text): array
    {
        preg_match_all('/https?:\/\/[^\s|)]+|(?<!@)\b(?:[a-z0-9-]+\.)+[a-z]{2,}(?:\/[^\s|)]*)?/iu', $text, $matches);

        $domains = [];
        foreach ($matches[0] ?? [] as $match) {
            $host = parse_url(str_starts_with($match, 'http') ? $match : 'https://'.$match, PHP_URL_HOST);
            $domain = strtolower((string) preg_replace('/^www\./i', '', (string) $host));

            if ($domain !== '') {
                $domains[$domain] = true;
            }
        }

        $domains = array_keys($domains);
        sort($domains);

        return $domains;
    }
}
