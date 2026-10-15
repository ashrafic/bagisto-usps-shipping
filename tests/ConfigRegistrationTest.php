<?php

it('registers the usps carrier in carriers config', function () {
    expect(config('carriers.usps.class'))->toBe('Ashrafic\UspsShipping\Carriers\Usps');
});

it('registers the usps system config group in core config', function () {
    $group = collect(config('core'))->firstWhere('key', 'sales.carriers.usps');

    expect($group)->not->toBeNull()
        ->and($group['fields'])->not->toBeEmpty();
});
