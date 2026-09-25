<?php

use App\Models\Store;
use App\Models\User;
use Livewire\Livewire;

test('the storefront is themed in the store\'s brand color', function () {
    $store = Store::factory()->create(['brand_primary_color' => '#74C3E4']);
    $palette = $store->brandPalette();

    $response = $this->get(route('storefront.start', ['store' => $store->slug]))
        ->assertOk()
        ->assertSee('--store-accent: #74c3e4;', escape: false)
        ->assertSee('--store-accent-foreground: #18181b;', escape: false);

    foreach ($palette as $shade => $color) {
        $response->assertSee("--color-brand-{$shade}: {$color};", escape: false);
    }
});

test('a storefront without a usable brand color keeps the platform colors', function (?string $color) {
    $store = Store::factory()->create(['brand_primary_color' => $color]);

    $this->get(route('storefront.start', ['store' => $store->slug]))
        ->assertOk()
        ->assertDontSee('--store-accent', escape: false);
})->with([
    'none set' => [null],
    'not a hex color' => ['red'],
    'markup' => ['</style'],
]);

test('button text stays legible on light and dark brand colors', function (string $color, string $expectedForeground) {
    $store = Store::factory()->make(['brand_primary_color' => $color]);

    expect($store->brandForegroundColor())->toBe($expectedForeground);
})->with([
    'deep teal' => ['#29564b', '#ffffff'],
    'navy' => ['#1e3a8a', '#ffffff'],
    'light blue' => ['#74C3E4', '#18181b'],
    'pale yellow' => ['#fde68a', '#18181b'],
    'white' => ['#ffffff', '#18181b'],
]);

describe('editing the brand color', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->create());
        $this->store = Store::factory()->create();
    });

    test('a color without the leading # is accepted', function () {
        Livewire::test('pages::admin.stores.show', ['store' => $this->store])
            ->set('brandPrimaryColor', ' 74C3E4 ')
            ->call('save')
            ->assertHasNoErrors();

        expect($this->store->fresh()->brandColor())->toBe('#74c3e4');
    });

    test('a value that is not a hex color is rejected with a helpful message', function () {
        Livewire::test('pages::admin.stores.show', ['store' => $this->store])
            ->set('brandPrimaryColor', 'blue')
            ->call('save')
            ->assertHasErrors('brandPrimaryColor')
            ->assertSee('Enter the brand color as a hex code, like #29564b.');
    });

    test('clearing the color removes it', function () {
        $this->store->update(['brand_primary_color' => '#29564b']);

        Livewire::test('pages::admin.stores.show', ['store' => $this->store])
            ->set('brandPrimaryColor', '')
            ->call('save')
            ->assertHasNoErrors();

        expect($this->store->fresh()->brand_primary_color)->toBeNull();
    });
});
