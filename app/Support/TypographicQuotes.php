<?php

namespace App\Support;

/**
 * Normalizes typographic double quotes („…“, “…”) to guillemets («…») in text
 * bound for LLM prompts.
 *
 * Models reproduce „…“-quoted phrases closing them with a plain ASCII " —
 * emitted unescaped inside a structured-output JSON string value, that quote
 * terminates the value mid-sentence and derails the rest of the plan (the
 * planner then returns a 1-agent "pipeline"). Guillemets need no JSON escaping
 * and are mirrored back safely. ASCII quotes are left untouched — they can be
 * structural (embedded JSON in the planner user messages).
 */
class TypographicQuotes
{
    public static function normalize(string $text): string
    {
        // Paired quotes first („…“ / „…” / “…”), then any stray singles.
        $text = (string) preg_replace('/„([^„“”]*)[“”]/u', '«$1»', $text);
        $text = (string) preg_replace('/“([^“”]*)”/u', '«$1»', $text);

        return strtr($text, ['„' => '«', '“' => '»', '”' => '»']);
    }
}
