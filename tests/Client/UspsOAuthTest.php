<?php

use Ashrafic\UspsShipping\Client\UspsOAuth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'carriers.usps.client_id' => 'id-123',
        'carriers.usps.client_secret' => 'secret-456',
    ]);

    Cache::flush();
});

it('fetches and caches an access token', function () {
    Http::fake([
        'api.usps.com/oauth2/v3/token' => Http::response([
            'access_token' => 'tok', 'expires_in' => 3600,
        ], 200),
    ]);

    $oauth = new UspsOAuth;

    expect($oauth->token())->toBe('tok')
        ->and($oauth->token())->toBe('tok');

    Http::assertSentCount(1);
});

it('throws a helpful message when credentials are missing', function () {
    config(['carriers.usps.client_id' => null, 'carriers.usps.client_secret' => null]);

    (new UspsOAuth)->token();
})->throws(RuntimeException::class, 'USPS credentials are not configured');

it('surfaces a rejected token request', function () {
    Http::fake([
        'api.usps.com/oauth2/v3/token' => Http::response([
            'error' => 'invalid_client', 'error_description' => 'Unknown client',
        ], 401),
    ]);

    (new UspsOAuth)->token();
})->throws(RuntimeException::class, 'USPS OAuth failed');
