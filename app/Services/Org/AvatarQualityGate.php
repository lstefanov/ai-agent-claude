<?php

namespace App\Services\Org;

/**
 * Пост-рендер QA за org портрети — отхвърля ч/б, 2×2 колажи и letterbox рамки
 * преди да маркираме аватара като ready. Чист PHP + GD.
 */
class AvatarQualityGate
{
    private const SAMPLE_PIXELS = 300;

    private const GRAYSCALE_SAT_THRESHOLD = 0.08;

    /**
     * @return array{ok: bool, reason: ?string}
     */
    public function passes(string $pngPath): array
    {
        if (! is_file($pngPath)) {
            return ['ok' => false, 'reason' => 'missing_file'];
        }

        $img = @imagecreatefrompng($pngPath);
        if ($img === false) {
            return ['ok' => false, 'reason' => 'unreadable'];
        }

        try {
            if ($reason = $this->detectGrayscale($img)) {
                return ['ok' => false, 'reason' => $reason];
            }
            if ($reason = $this->detectCollageGrid($img)) {
                return ['ok' => false, 'reason' => $reason];
            }

            return ['ok' => true, 'reason' => null];
        } finally {
            imagedestroy($img);
        }
    }

    private function detectGrayscale(\GdImage $img): ?string
    {
        $w = imagesx($img);
        $h = imagesy($img);
        if ($w < 8 || $h < 8) {
            return 'too_small';
        }

        $satSum = 0.0;
        for ($i = 0; $i < self::SAMPLE_PIXELS; $i++) {
            $rgb = imagecolorat($img, random_int(0, $w - 1), random_int(0, $h - 1));
            $satSum += $this->saturation($rgb);
        }

        if (($satSum / self::SAMPLE_PIXELS) < self::GRAYSCALE_SAT_THRESHOLD) {
            return 'grayscale';
        }

        return null;
    }

    private function detectCollageGrid(\GdImage $img): ?string
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $midX = (int) ($w / 2);
        $midY = (int) ($h / 2);

        $brightMidX = 0;
        for ($y = 0; $y < $h; $y++) {
            if ($this->brightness(imagecolorat($img, $midX, $y)) > 220) {
                $brightMidX++;
            }
        }

        $brightMidY = 0;
        for ($x = 0; $x < $w; $x++) {
            if ($this->brightness(imagecolorat($img, $x, $midY)) > 220) {
                $brightMidY++;
            }
        }

        $midXRatio = $brightMidX / max(1, $h);
        $midYRatio = $brightMidY / max(1, $w);

        // Пълен вертикален шев (типичен 2×2 колаж) или хоризонтален шев / UI рамка.
        if ($midXRatio > 0.40 || $midYRatio > 0.10 || ($midXRatio > 0.10 && $midYRatio > 0.08)) {
            return 'collage_grid';
        }

        return null;
    }

    private function saturation(int $rgb): float
    {
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);

        return $max > 0 ? ($max - $min) / $max : 0.0;
    }

    private function brightness(int $rgb): float
    {
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;

        return ($r + $g + $b) / 3;
    }
}
