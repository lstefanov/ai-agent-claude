<?php

namespace App\Support;

class UrlExtractor
{
    /**
     * Common file extensions that look like a domain TLD ("report.pdf") but are
     * not websites. Bare matches ending in one of these (with no path) are skipped.
     */
    private const FILE_EXTENSIONS = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv',
        'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico', 'mp3', 'mp4',
        'zip', 'rar', 'gz', 'json', 'xml', 'html', 'htm', 'php', 'js', 'css',
    ];

    /**
     * Extract all website URLs from free text, de-duplicated and stripped of
     * trailing punctuation. Recognises both full `http(s)://` URLs AND bare
     * domains written without a scheme (e.g. `primelaser.bg`, `www.x.com/path`),
     * normalising the latter to `https://…` so they are usable for crawling.
     *
     * @return array<int, string>
     */
    public static function all(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        // Alternation tries a scheme'd URL FIRST so a domain inside one is consumed
        // as part of that match (matches are non-overlapping, left-to-right) and is
        // not re-captured as a bare domain. The negative lookbehind skips emails
        // (info@example.com) and domains glued to a preceding word char.
        $pattern = '~(?<![@\w])(?:'
            .'https?://[^\s<>"\')]+'                                    // full URL
            .'|(?:www\.)?(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}(?:/[^\s<>"\')]*)?'  // bare domain (+path)
            .')~i';

        preg_match_all($pattern, $text, $matches);

        $urls = [];
        foreach ($matches[0] as $raw) {
            $url = rtrim($raw, '.,;:!?\'")');
            if ($url === '') {
                continue;
            }

            if (! preg_match('~^https?://~i', $url)) {
                // Bare domain: skip obvious filenames, then add a scheme.
                $host    = strtok($url, '/');
                $lastDot = strrchr($host, '.');
                $tld     = $lastDot !== false ? strtolower(substr($lastDot, 1)) : '';
                if (! str_contains($url, '/') && in_array($tld, self::FILE_EXTENSIONS, true)) {
                    continue;
                }
                $url = 'https://'.$url;
            }

            $urls[] = $url;
        }

        return array_values(array_unique($urls));
    }

    /**
     * Return the first website URL found in the text (normalised to http(s)://),
     * or null.
     */
    public static function first(string $text): ?string
    {
        return self::all($text)[0] ?? null;
    }
}
