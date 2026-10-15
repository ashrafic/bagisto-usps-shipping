<?php

use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'carriers.usps.client_id' => 'id-123',
        'carriers.usps.client_secret' => 'secret-456',
        'carriers.usps.services' => 'USPS_GROUND_ADVANTAGE,PRIORITY_MAIL',
        'carriers.usps.length' => '12',
        'carriers.usps.width' => '9',
        'carriers.usps.height' => '6',
        'carriers.usps.handling_fee_type' => 'fixed',
        'carriers.usps.handling_fee_amount' => '0',
        'carriers.usps.rate_cache_ttl' => '0',
        'carriers.usps.debug' => false,
        'shipping.origin.zipcode' => '87410',
    ]);
});

it('prints the sample rates table', function () {
    Http::fake([
        'api.usps.com/oauth2/v3/token' => Http::response([
            'access_token' => 'tok', 'expires_in' => 3600,
        ], 200),
        'api.usps.com/prices/v3/base-rates/search' => Http::response([
            'totalBasePrice' => 16.3,
        ], 200),
    ]);

    $this->artisan('usps:test')->assertSuccessful();
});

it('fails when the credentials are rejected', function () {
    Http::fake([
        'api.usps.com/oauth2/v3/token' => Http::response([
            'error' => 'invalid_client',
        ], 401),
    ]);

    $this->artisan('usps:test')->assertFailed();
});
