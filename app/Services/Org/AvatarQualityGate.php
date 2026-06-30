<?php

namespace App\Services\Org;

/**
 * Пост-рендер QA за org портрети — отхвърля ч/б, split/contact-sheet колажи
 * и тънки рамки преди да маркираме аватара като ready. Чист PHP + GD.
 */
class AvatarQualityGate
{
    private const SAMPLE_GRID_SIZE = 17;

    private const GRAYSCALE_SAT_THRESHOLD = 0.08;

    private const SEAM_SCAN_RATIO = 0.05;

    private const SEAM_DIFF_THRESHOLD = 20.0;

    private const COLLAGE_MIN_AVG = 25.0;

    private const COLLAGE_MIN_HIGH_RATIO = 0.45;

    private const HORIZONTAL_SPLIT_MIN_AVG = 35.0;

    private const HORIZONTAL_SPLIT_MIN_HIGH_RATIO = 0.75;

    private const VERTICAL_SPLIT_MIN_AVG = 18.0;

    private const VERTICAL_SPLIT_MIN_HIGH_RATIO = 0.30;

    private const FRAME_DARK_BRIGHTNESS = 100.0;

    private const FRAME_DARK_RATIO = 0.70;

    private const FRAME_INNER_OFFSET_RATIO = 0.015;

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
            if ($reason = $this->detectSplitOrCollage($img)) {
                return ['ok' => false, 'reason' => $reason];
            }
            if ($reason = $this->detectFrame($img)) {
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
        $samples = 0;
        for ($gy = 0; $gy < self::SAMPLE_GRID_SIZE; $gy++) {
            $y = min($h - 1, (int) floor(($gy + 0.5) * $h / self::SAMPLE_GRID_SIZE));
            for ($gx = 0; $gx < self::SAMPLE_GRID_SIZE; $gx++) {
                $x = min($w - 1, (int) floor(($gx + 0.5) * $w / self::SAMPLE_GRID_SIZE));
                $satSum += $this->saturation(imagecolorat($img, $x, $y));
                $samples++;
            }
        }

        if (($satSum / max(1, $samples)) < self::GRAYSCALE_SAT_THRESHOLD) {
            return 'grayscale';
        }

        return null;
    }

    private function detectSplitOrCollage(\GdImage $img): ?string
    {
        $vertical = $this->strongestCenterSeam($img, 'vertical');
        $horizontal = $this->strongestCenterSeam($img, 'horizontal');

        if (
            $vertical['avg'] >= self::COLLAGE_MIN_AVG
            && $horizontal['avg'] >= self::COLLAGE_MIN_AVG
            && $vertical['high_ratio'] >= self::COLLAGE_MIN_HIGH_RATIO
            && $horizontal['high_ratio'] >= self::COLLAGE_MIN_HIGH_RATIO
        ) {
            return 'collage_grid';
        }

        if (
            $horizontal['avg'] >= self::HORIZONTAL_SPLIT_MIN_AVG
            && $horizontal['high_ratio'] >= self::HORIZONTAL_SPLIT_MIN_HIGH_RATIO
        ) {
            return 'horizontal_split';
        }

        if (
            $vertical['avg'] >= self::VERTICAL_SPLIT_MIN_AVG
            && $vertical['high_ratio'] >= self::VERTICAL_SPLIT_MIN_HIGH_RATIO
        ) {
            return 'vertical_split';
        }

        return null;
    }

    private function detectFrame(\GdImage $img): ?string
    {
        $framedEdges = 0;
        foreach (['top', 'bottom', 'left', 'right'] as $edge) {
            if ($this->edgeLooksFramed($img, $edge)) {
                $framedEdges++;
            }
        }

        return $framedEdges >= 2 ? 'frame' : null;
    }

    /**
     * @return array{avg: float, high_ratio: float}
     */
    private function strongestCenterSeam(\GdImage $img, string $axis): array
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $length = $axis === 'vertical' ? $w : $h;
        $center = (int) floor($length / 2);
        $window = max(4, (int) floor($length * self::SEAM_SCAN_RATIO));
        $from = max(1, $center - $window);
        $to = min($length - 1, $center + $window);
        $best = ['avg' => 0.0, 'high_ratio' => 0.0];

        for ($pos = $from; $pos <= $to; $pos++) {
            $sum = 0.0;
            $high = 0;
            $samples = 0;

            if ($axis === 'vertical') {
                for ($y = 0; $y < $h; $y++) {
                    $diff = $this->colorDistance(imagecolorat($img, $pos - 1, $y), imagecolorat($img, $pos, $y));
                    $sum += $diff;
                    $high += $diff >= self::SEAM_DIFF_THRESHOLD ? 1 : 0;
                    $samples++;
                }
            } else {
                for ($x = 0; $x < $w; $x++) {
                    $diff = $this->colorDistance(imagecolorat($img, $x, $pos - 1), imagecolorat($img, $x, $pos));
                    $sum += $diff;
                    $high += $diff >= self::SEAM_DIFF_THRESHOLD ? 1 : 0;
                    $samples++;
                }
            }

            $avg = $sum / max(1, $samples);
            if ($avg > $best['avg']) {
                $best = [
                    'avg' => $avg,
                    'high_ratio' => $high / max(1, $samples),
                ];
            }
        }

        return $best;
    }

    private function edgeLooksFramed(\GdImage $img, string $edge): bool
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $innerOffset = max(4, (int) floor(min($w, $h) * self::FRAME_INNER_OFFSET_RATIO));
        $maxDarkRatio = 0.0;
        $maxContrast = 0.0;

        for ($offset = 0; $offset <= 2; $offset++) {
            $dark = 0;
            $edgeBrightness = 0.0;
            $innerBrightness = 0.0;
            $samples = 0;

            $length = $edge === 'left' || $edge === 'right' ? $h : $w;
            for ($i = 0; $i < $length; $i++) {
                [$edgeX, $edgeY, $innerX, $innerY] = $this->edgeSamplePoints($edge, $offset, $innerOffset, $i, $w, $h);
                $edgeB = $this->brightness(imagecolorat($img, $edgeX, $edgeY));
                $innerB = $this->brightness(imagecolorat($img, $innerX, $innerY));

                $dark += $edgeB <= self::FRAME_DARK_BRIGHTNESS ? 1 : 0;
                $edgeBrightness += $edgeB;
                $innerBrightness += $innerB;
                $samples++;
            }

            $darkRatio = $dark / max(1, $samples);
            $contrast = abs(($edgeBrightness / max(1, $samples)) - ($innerBrightness / max(1, $samples)));
            $maxDarkRatio = max($maxDarkRatio, $darkRatio);
            $maxContrast = max($maxContrast, $contrast);
        }

        return $maxDarkRatio >= self::FRAME_DARK_RATIO && $maxContrast >= 45.0;
    }

    /**
     * @return array{int, int, int, int}
     */
    private function edgeSamplePoints(string $edge, int $offset, int $innerOffset, int $i, int $w, int $h): array
    {
        return match ($edge) {
            'top' => [$i, $offset, $i, min($h - 1, $innerOffset)],
            'bottom' => [$i, $h - 1 - $offset, $i, max(0, $h - 1 - $innerOffset)],
            'left' => [$offset, $i, min($w - 1, $innerOffset), $i],
            default => [$w - 1 - $offset, $i, max(0, $w - 1 - $innerOffset), $i],
        };
    }

    private function colorDistance(int $a, int $b): float
    {
        $ar = ($a >> 16) & 0xFF;
        $ag = ($a >> 8) & 0xFF;
        $ab = $a & 0xFF;
        $br = ($b >> 16) & 0xFF;
        $bg = ($b >> 8) & 0xFF;
        $bb = $b & 0xFF;

        return (abs($ar - $br) + abs($ag - $bg) + abs($ab - $bb)) / 3;
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
