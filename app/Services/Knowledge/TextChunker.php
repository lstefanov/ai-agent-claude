<?php

namespace App\Services\Knowledge;

/**
 * Deterministic, dependency-free splitter for knowledge-base ingestion.
 * Structure-aware: markdown headings, "## Лист:" sheet markers and OCR page
 * markers open a new section whose heading travels with every chunk in
 * meta['heading']. Oversized sections split on paragraph, then sentence
 * boundaries, greedily packed to ~chunk_size chars with an overlap tail.
 */
class TextChunker
{
    private const MIN_CHUNK_CHARS = 40;

    /**
     * @return array<int, array{content: string, meta: array<string, mixed>}>
     */
    public function chunk(string $text, ?int $size = null, ?int $overlap = null): array
    {
        $size = max(200, $size ?? (int) config('services.knowledge.chunk_size', 1200));
        $overlap = max(0, min($size - 100, $overlap ?? (int) config('services.knowledge.chunk_overlap', 150)));

        $chunks = [];
        foreach ($this->sections($text) as [$heading, $body]) {
            $meta = $heading !== '' ? ['heading' => $heading] : [];
            $prefix = $heading !== '' ? $heading."\n" : '';

            foreach ($this->splitToSize($body, $size, $overlap) as $piece) {
                if (mb_strlen(trim(preg_replace('/\s+/u', ' ', $piece) ?? '')) < self::MIN_CHUNK_CHARS) {
                    continue;
                }
                $chunks[] = ['content' => $prefix.trim($piece), 'meta' => $meta];
            }
        }

        return $chunks;
    }

    /**
     * @return array<int, array{0: string, 1: string}> [heading, body] pairs
     */
    private function sections(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $sections = [];
        $heading = '';
        $buffer = [];

        $flush = function () use (&$sections, &$heading, &$buffer) {
            $body = trim(implode("\n", $buffer));
            if ($body !== '') {
                $sections[] = [$heading, $body];
            }
            $buffer = [];
        };

        foreach ($lines as $line) {
            $isMarker = preg_match('/^#{1,3}\s\S/u', $line)
                || str_starts_with($line, '## Лист:')
                || str_starts_with($line, '=== OCR PAGE')
                || str_starts_with($line, '=== OCR DOCUMENT');

            if ($isMarker) {
                $flush();
                $heading = trim($line);

                continue;
            }

            $buffer[] = $line;
        }
        $flush();

        return $sections;
    }

    /**
     * @return array<int, string>
     */
    private function splitToSize(string $body, int $size, int $overlap): array
    {
        if (mb_strlen($body) <= $size) {
            return [$body];
        }

        // Paragraphs first; a paragraph that alone exceeds the budget gets
        // re-split on sentence boundaries (hard cut as the last resort).
        $units = [];
        foreach (preg_split('/\n{2,}/u', $body) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }
            if (mb_strlen($paragraph) <= $size) {
                $units[] = $paragraph;

                continue;
            }
            foreach ($this->sentences($paragraph, $size) as $sentence) {
                $units[] = $sentence;
            }
        }

        $pieces = [];
        $current = '';
        foreach ($units as $unit) {
            if ($current !== '' && mb_strlen($current) + mb_strlen($unit) + 2 > $size) {
                $pieces[] = $current;
                // Carry an overlap tail so context survives the cut.
                $current = $overlap > 0 ? mb_substr($current, -$overlap).' ' : '';
            }
            $current .= ($current === '' || str_ends_with($current, ' ') ? '' : "\n\n").$unit;
        }
        if (trim($current) !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }

    /**
     * @return array<int, string>
     */
    private function sentences(string $paragraph, int $size): array
    {
        $parts = preg_split('/(?<=[.!?…])\s+/u', $paragraph) ?: [$paragraph];

        $out = [];
        foreach ($parts as $part) {
            // Pathological "sentence" (tables, URLs) — hard cut to the budget.
            while (mb_strlen($part) > $size) {
                $out[] = mb_substr($part, 0, $size);
                $part = mb_substr($part, $size);
            }
            if (trim($part) !== '') {
                $out[] = $part;
            }
        }

        return $out;
    }
}
