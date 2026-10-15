<?php

use Ashrafic\UspsShipping\Carriers\Usps;

return [
    'usps' => [
        'code' => 'usps',
        'title' => 'USPS Shipping',
        'description' => 'USPS live shipping rates',
        'active' => true,
        'class' => Usps::class,
    ],
];
