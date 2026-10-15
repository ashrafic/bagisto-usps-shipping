<?php

it('resolves every admin translation key used by the package', function () {
    $keys = [
        'app.admin.system.usps',
        'app.admin.system.usps-info',
        'app.admin.system.title',
        'app.admin.system.description',
        'app.admin.system.calculate-tax',
        'app.admin.system.calculate-tax-info',
        'app.admin.system.status',
        'app.admin.system.client-id',
        'app.admin.system.client-id-info',
        'app.admin.system.client-secret',
        'app.admin.system.allowed-methods',
        'app.admin.system.allowed-methods-info',
        'app.admin.system.service-ground-advantage',
        'app.admin.system.service-priority',
        'app.admin.system.service-priority-express',
        'app.admin.system.service-media-mail',
        'app.admin.system.service-library-mail',
        'app.admin.system.service-priority-international',
        'app.admin.system.service-priority-express-international',
        'app.admin.system.service-first-class-international',
        'app.admin.system.length',
        'app.admin.system.width',
        'app.admin.system.height',
        'app.admin.system.dimensions-info',
        'app.admin.system.handling-fee-type',
        'app.admin.system.handling-fee-fixed',
        'app.admin.system.handling-fee-percent',
        'app.admin.system.handling-fee',
        'app.admin.system.rate-cache-ttl',
        'app.admin.system.rate-cache-ttl-info',
        'app.admin.system.debug',
    ];

    foreach ($keys as $key) {
        $translated = trans('usps-shipping::'.$key);

        expect($translated)->not->toBe('usps-shipping::'.$key)
            ->and($translated)->not->toBeEmpty();
    }
});
