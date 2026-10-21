<?php

use Ashrafic\UspsShipping\Support\ItemPacker;

beforeEach(function () {
    config()->set('carriers.usps.length', 12);
    config()->set('carriers.usps.width', 8);
    config()->set('carriers.usps.height', 6);
});

it('packs a light order into a single package', function () {
    $packages = (new ItemPacker)->pack([
        ['weight' => 2, 'quantity' => 3, 'length' => 10.0, 'width' => 6.0, 'height' => 4.0],
    ]);

    expect($packages)->toHaveCount(1)
        ->and($packages[0]->weight)->toBe(6.0)
        ->and($packages[0]->length)->toBe(10.0);
});

it('splits orders heavier than the 70 pound cap', function () {
    $packages = (new ItemPacker)->pack([
        ['weight' => 50, 'quantity' => 2, 'length' => 10.0, 'width' => 6.0, 'height' => 4.0],
    ]);

    expect($packages)->toHaveCount(2)
        ->and($packages[0]->weight)->toBe(50.0)
        ->and($packages[1]->weight)->toBe(50.0);
});

it('converts kilograms to pounds', function () {
    $packages = (new ItemPacker)->pack([
        ['weight' => 1, 'quantity' => 1, 'store_weight_unit' => 'KGS', 'length' => 10.0, 'width' => 6.0, 'height' => 4.0],
    ]);

    expect($packages[0]->weight)->toBe(2.2);
});

it('falls back to the default box when the item has no dimensions', function () {
    $packages = (new ItemPacker)->pack([
        ['weight' => 2, 'quantity' => 1, 'length' => '', 'width' => null, 'height' => 0],
    ]);

    expect($packages[0]->length)->toBe(12.0)
        ->and($packages[0]->width)->toBe(8.0)
        ->and($packages[0]->height)->toBe(6.0);
});

it('throws when no dimensions are available anywhere', function () {
    config()->set('carriers.usps.length', 0);

    expect(fn () => (new ItemPacker)->pack([
        ['weight' => 2, 'quantity' => 1],
    ]))->toThrow(RuntimeException::class, 'requires package dimensions');
});

it('enforces the one ounce minimum weight', function () {
    $packages = (new ItemPacker)->pack([
        ['weight' => 0.01, 'quantity' => 1, 'length' => 10.0, 'width' => 6.0, 'height' => 4.0],
    ]);

    expect($packages[0]->weight)->toBe(0.0625);
});
