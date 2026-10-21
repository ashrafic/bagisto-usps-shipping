<?php

return [
    [
        'key' => 'sales.usps-shipping',
        'name' => 'usps-shipping::app.admin.menu.usps-shipments',
        'route' => [
            'admin.usps-shipping.index',
            'admin.usps-shipping.labels.create',
            'admin.usps-shipping.labels.store',
            'admin.usps-shipping.labels.download',
            'admin.usps-shipping.labels.void',
        ],
        'sort' => 6,
    ],
];
