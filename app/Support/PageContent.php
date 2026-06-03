<?php

namespace App\Support;

class PageContent
{
    /**
     * Strip common WordPress/Elementor boilerplate that appears BEFORE the
     * actual page content: cookie-consent popups, repeated navigation headers.
     *
     * Strategy: find the first H1 heading ("# "), which is always the page's
     * real title (product name, section name, etc.). Everything before it is
     * nav / cookie-popup boilerplate.
     *
     * Fallback 1: strip after the cookie-consent dismissal phrase.
     * Fallback 2: skip the first 2 000 chars (heuristic minimum boilerplate size).
     */
    public static function stripBoilerplate(string $markdown): string
    {
        $markdown = trim($markdown);
        if ($markdown === '') {
            return '';
        }

        // Primary: find the first H1 heading (the real page title).
        // Match "\n# " or the string starting with "# ".
        if (preg_match('/(?:^|\n)(# [^\n])/u', $markdown, $m, PREG_OFFSET_CAPTURE)) {
            $offset = $m[1][1]; // byte offset of the "# " marker
            // Convert byte offset to char offset for mb_substr safety.
            $charOffset = mb_strlen(substr($markdown, 0, $offset));

            return mb_substr($markdown, $charOffset);
        }

        // Fallback 1: cookie-consent popup ends with this phrase.
        $cookieEnd = 'Запазване на предпочитанията';
        $pos       = mb_strpos($markdown, $cookieEnd);
        if ($pos !== false) {
            return mb_substr($markdown, $pos + mb_strlen($cookieEnd));
        }

        // Fallback 2: heuristic — skip first 2 000 chars of boilerplate.
        return mb_strlen($markdown) > 2000 ? mb_substr($markdown, 2000) : $markdown;
    }
}
