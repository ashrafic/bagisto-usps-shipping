<?php

use Ashrafic\UspsShipping\Client\UspsClient;
use Ashrafic\UspsShipping\Client\UspsOAuth;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'carriers.usps.client_id' => 'id-123',
        'carriers.usps.client_secret' => 'secret-456',
        'carriers.usps.debug' => false,
    ]);

    Http::fake([
        'api.usps.com/oauth2/v3/token' => Http::response([
            'access_token' => 'tok', 'expires_in' => 3600,
        ], 200),
    ]);
});

it('returns the parsed body for a successful search', function () {
    Http::fake([
        'api.usps.com/oauth2/v3/token' => Http::response(['access_token' => 'tok'], 200),
        'api.usps.com/prices/v3/base-rates/search' => Http::response([
            'totalBasePrice' => 16.3,
        ], 200),
    ]);

    $body = (new UspsClient(new UspsOAuth))->searchBaseRates([
        'mailClass' => 'USPS_GROUND_ADVANTAGE',
        'destinationZIPCode' => '11225',
    ]);

    expect($body['totalBasePrice'])->toBe(16.3);
});

it('retries as nonstandard when the machinable classification is rejected', function () {
    Http::fake([
        'api.usps.com/oauth2/v3/token' => Http::response(['access_token' => 'tok'], 200),
        'api.usps.com/prices/v3/base-rates/search' => Http::sequence()
            ->push([
                'error' => ['code' => '030011', 'message' => 'Package was marked as machinable, but is nonstandard'],
            ], 400)
            ->push(['totalBasePrice' => 21.42], 200),
    ]);

    $body = (new UspsClient(new UspsOAuth))->searchBaseRates([
        'mailClass' => 'PRIORITY_MAIL',
        'processingCategory' => 'MACHINABLE',
    ]);

    expect($body['totalBasePrice'])->toBe(21.42);

    Http::assertSent(function ($request) {
        return $request->hasHeader('Authorization', ['Bearer tok'])
            && str_contains($request->url(), 'prices/v3');
    });
});

it('routes international payloads to the international endpoint', function () {
    Http::fake([
        'api.usps.com/oauth2/v3/token' => Http::response(['access_token' => 'tok'], 200),
        'api.usps.com/international-prices/v3/base-rates/search' => Http::response([
            'totalBasePrice' => 48.81,
        ], 200),
    ]);

    $body = (new UspsClient(new UspsOAuth))->searchBaseRates([
        'mailClass' => 'PRIORITY_MAIL_INTERNATIONAL',
        'destinationCountryCode' => 'CA',
    ]);

    expect($body['totalBasePrice'])->toBe(48.81);
});

it('throws with the USPS error code on failure', function () {
    Http::fake([
        'api.usps.com/oauth2/v3/token' => Http::response(['access_token' => 'tok'], 200),
        'api.usps.com/prices/v3/base-rates/search' => Http::response([
            'errors' => [['code' => '030008', 'title' => 'Package size exceeds maximum allowed for mail class']],
        ], 400),
    ]);

    (new UspsClient(new UspsOAuth))->searchBaseRates(['mailClass' => 'PRIORITY_MAIL']);
})->throws(RuntimeException::class, '[030008] Package size exceeds maximum allowed for mail class');
