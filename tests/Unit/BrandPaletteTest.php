<?php

use App\Services\BrandPalette;

dataset('brand colors', [
    'platform teal' => '#29564b',
    'navy' => '#1e3a8a',
    'light blue' => '#74c3e4',
    'pale yellow' => '#fde68a',
    'bright red' => '#ef4444',
    'white' => '#ffffff',
    'black' => '#000000',
]);

test('outline and text shades stay readable on white for any brand color', function (string $color) {
    $palette = BrandPalette::fromColor($color);

    expect(BrandPalette::contrast($palette[600], '#ffffff'))->toBeGreaterThanOrEqual(3.0)
        ->and(BrandPalette::contrast($palette[700], '#ffffff'))->toBeGreaterThanOrEqual(4.5)
        ->and(BrandPalette::contrast($palette[800], '#ffffff'))->toBeGreaterThanOrEqual(7.0);
})->with('brand colors');

test('shades run from lightest to darkest', function (string $color) {
    $contrasts = array_map(
        fn (string $shade): float => BrandPalette::contrast($shade, '#ffffff'),
        array_values(BrandPalette::fromColor($color)),
    );

    expect(array_keys(BrandPalette::fromColor($color)))->toBe([50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950]);

    $sorted = $contrasts;
    sort($sorted);
    expect($contrasts)->toBe($sorted);
})->with('brand colors');

test('a dark brand color is used as-is for outlines and text', function () {
    $palette = BrandPalette::fromColor('#29564b');

    expect($palette[600])->toBe('#29564b')
        ->and($palette[700])->toBe('#29564b');
});

test('a light brand color is darkened for text but kept for tints', function () {
    $palette = BrandPalette::fromColor('#74c3e4');

    expect($palette[700])->not->toBe('#74c3e4')
        ->and(BrandPalette::contrast($palette[50], '#ffffff'))->toBeLessThan(1.1);
});

test('button text is white on dark colors and near-black on light ones', function (string $color, string $expected) {
    expect(BrandPalette::foregroundFor($color))->toBe($expected);
})->with([
    ['#29564b', '#ffffff'],
    ['#1e3a8a', '#ffffff'],
    ['#74c3e4', '#18181b'],
    ['#fde68a', '#18181b'],
]);
