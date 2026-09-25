<?php

namespace App\Services;

/**
 * Builds the storefront's full "brand" color scale (50–950) from a funeral
 * home's single brand color, replacing the platform teal shade for shade.
 *
 * Light shades are the brand color blended with white, for fills and
 * borders. The shades used for outlines and text are the brand color
 * darkened only as far as needed to stay readable on white, so a light brand
 * color still gets legible prices and links while a dark one keeps its
 * exact hue.
 */
class BrandPalette
{
    /**
     * How much of the brand color each light shade keeps; the rest is white.
     *
     * @var array<int, float>
     */
    private const TINTS = [
        50 => 0.07,
        100 => 0.15,
        200 => 0.30,
        300 => 0.48,
        400 => 0.70,
        500 => 0.88,
    ];

    /**
     * Minimum contrast against white for the shades used on outlines (600)
     * and text (700, 800). 3:1 and 4.5:1 are the WCAG AA minimums for UI
     * components and normal text.
     *
     * @var array<int, float>
     */
    private const MINIMUM_CONTRAST = [
        600 => 3.0,
        700 => 4.5,
        800 => 7.0,
    ];

    private const DARK_TEXT = '#18181b';

    /**
     * @param  string  $color  a "#rrggbb" hex color
     * @return array<int, string> shade => "#rrggbb"
     */
    public static function fromColor(string $color): array
    {
        $palette = [];

        foreach (self::TINTS as $shade => $amount) {
            $palette[$shade] = self::mix($color, '#ffffff', $amount);
        }

        foreach (self::MINIMUM_CONTRAST as $shade => $minimum) {
            $palette[$shade] = self::darkenUntilContrast($color, $minimum);
        }

        $palette[900] = self::mix($palette[800], '#000000', 0.70);
        $palette[950] = self::mix($palette[800], '#000000', 0.40);

        return $palette;
    }

    /**
     * White or near-black, whichever reads better on the given color.
     */
    public static function foregroundFor(string $color): string
    {
        return self::contrast($color, '#ffffff') >= self::contrast($color, self::DARK_TEXT)
            ? '#ffffff'
            : self::DARK_TEXT;
    }

    /**
     * WCAG contrast ratio between two colors, from 1 (none) to 21.
     */
    public static function contrast(string $first, string $second): float
    {
        $lighter = max(self::luminance($first), self::luminance($second));
        $darker = min(self::luminance($first), self::luminance($second));

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private static function darkenUntilContrast(string $color, float $minimum): string
    {
        for ($share = 1.0; $share > 0; $share -= 0.01) {
            $candidate = self::mix($color, '#000000', $share);

            if (self::contrast($candidate, '#ffffff') >= $minimum) {
                return $candidate;
            }
        }

        return '#000000';
    }

    /**
     * Blend two colors, keeping $share of the first.
     */
    private static function mix(string $first, string $second, float $share): string
    {
        $firstChannels = self::channels($first);
        $secondChannels = self::channels($second);

        $mixed = array_map(
            fn (int $a, int $b): int => (int) round($a * $share + $b * (1 - $share)),
            $firstChannels,
            $secondChannels,
        );

        return sprintf('#%02x%02x%02x', ...$mixed);
    }

    /**
     * @return array{int, int, int}
     */
    private static function channels(string $color): array
    {
        return array_map('hexdec', str_split(substr($color, 1), 2));
    }

    private static function luminance(string $color): float
    {
        [$red, $green, $blue] = array_map(function (int $channel): float {
            $value = $channel / 255;

            return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, self::channels($color));

        return 0.2126 * $red + 0.7152 * $green + 0.0722 * $blue;
    }
}
