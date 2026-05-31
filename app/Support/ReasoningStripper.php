<?php

namespace App\Support;

class ReasoningStripper
{
    public static function strip(string $output): string
    {
        $clean = $output;

        $patterns = [
            '/<think\b[^>]*>.*?<\/think>/is',
            '/<thinking\b[^>]*>.*?<\/thinking>/is',
            '/◁think▷.*?◁\/think▷/is',
        ];

        foreach ($patterns as $pattern) {
            $clean = preg_replace($pattern, '', $clean) ?? $clean;
        }

        $clean = trim($clean);

        return $clean !== '' ? $clean : $output;
    }
}
