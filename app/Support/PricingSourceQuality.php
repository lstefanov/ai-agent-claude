<?php

namespace App\Support;

class PricingSourceQuality
{
    private const LOW_VALUE_DOMAINS = [
        'facebook.com',
        'grabo.bg',
        'instagram.com',
        'linkedin.com',
        'tiktok.com',
        'tripadvisor.com',
        'youtube.com',
    ];

    public static function filterSearchResults(string $searchResults): string
    {
        $blocks = preg_split('/(?=^\[\d+\]\s+Title:)/m', $searchResults) ?: [];
        $headerBlocks = [];
        $preferredBlocks = [];
        $fallbackBlocks = [];

        foreach ($blocks as $block) {
            if (! preg_match('/URL:\s*(https?:\/\/\S+)/i', $block, $match)) {
                $headerBlocks[] = $block;

                continue;
            }

            if (self::isLowValueDomain(self::domainFromUrl($match[1]))) {
                $fallbackBlocks[] = $block;
            } else {
                $preferredBlocks[] = $block;
            }
        }

        return implode('', array_merge($headerBlocks, $preferredBlocks, $fallbackBlocks));
    }

    public static function hasPricingEvidence(string $markdown): bool
    {
        return (bool) preg_match('/\b\d+(?:[,.]\d+)?\s*(?:лв\.?|bgn|eur|€|euro|евро)(?=\s|[|),.;]|$)/iu', $markdown);
    }

    public static function isLowValueDomain(string $domain): bool
    {
        foreach (self::LOW_VALUE_DOMAINS as $lowValueDomain) {
            if ($domain === $lowValueDomain || str_ends_with($domain, '.'.$lowValueDomain)) {
                return true;
            }
        }

        return false;
    }

    public static function domainFromUrl(string $url): string
    {
        return strtolower((string) preg_replace('/^www\./i', '', parse_url($url, PHP_URL_HOST) ?? ''));
    }
}
