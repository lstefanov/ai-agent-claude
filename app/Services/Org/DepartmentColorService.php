<?php

namespace App\Services\Org;

class DepartmentColorService
{
    /**
     * Разпределя уникални цветове на списък отдели.
     * Домейн-мапването е hint за първоначален избор; ако цвят вече е зает,
     * се избира най-отдалеченият свободен hue от палитрата.
     *
     * @param  array<int, array{domain?: string, color?: ?string}>  $departments
     * @return array<int, string> цвят за всеки отдел (по същия индекс)
     */
    public function assignUnique(array $departments): array
    {
        $palette = array_keys((array) config('organization.department_colors'));
        $hues = (array) config('organization.department_color_hues');
        $functionColors = (array) config('organization.function_colors');
        $default = (string) config('organization.default_function_color', 'blue');

        $assigned = [];
        $used = [];

        foreach ($departments as $i => $d) {
            if (! empty($d['color']) && in_array($d['color'], $palette, true)) {
                $assigned[$i] = $d['color'];
                $used[$d['color']] = true;

                continue;
            }

            $hint = $default;
            $domain = mb_strtolower((string) ($d['domain'] ?? ''));
            foreach ($functionColors as $needle => $color) {
                if ($domain !== '' && str_contains($domain, mb_strtolower((string) $needle))) {
                    $hint = (string) $color;
                    break;
                }
            }

            $color = isset($used[$hint])
                ? $this->mostDistinct($palette, $used, $hues)
                : $hint;
            $assigned[$i] = $color;
            $used[$color] = true;
        }

        return $assigned;
    }

    /** @return array<int, string> */
    public function validColors(): array
    {
        return array_keys((array) config('organization.department_colors'));
    }

    /** @param  array<int, string>  $palette  @param  array<string, true>  $used  @param  array<string, int|float>  $hues */
    private function mostDistinct(array $palette, array $used, array $hues): string
    {
        $usedHues = array_map(fn ($c) => (float) ($hues[$c] ?? 0), array_keys($used));
        $best = $palette[0];
        $bestDist = -1.0;

        foreach ($palette as $c) {
            if (isset($used[$c])) {
                continue;
            }

            $minDist = $usedHues === []
                ? 360.0
                : min(array_map(
                    fn ($uh) => $this->hueDistance((float) ($hues[$c] ?? 0), $uh),
                    $usedHues
                ));

            if ($minDist > $bestDist) {
                $bestDist = $minDist;
                $best = $c;
            }
        }

        return $best;
    }

    private function hueDistance(float $a, float $b): float
    {
        $d = abs($a - $b);

        return min($d, 360 - $d);
    }
}
