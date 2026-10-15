<?php

use Ashrafic\UspsShipping\Client\UspsClient;
use Ashrafic\UspsShipping\Client\UspsOAuth;
use Ashrafic\UspsShipping\Services\RateService;
use Illuminate\Support\Facades\Cache;
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
    ]);

    Cache::flush();

    Http::fake([
        'api.usps.com/oauth2/v3/token' => Http::response([
            'access_token' => 'tok', 'expires_in' => 3600,
        ], 200),
    ]);
});

function uspsRateService(): RateService
{
    return new RateService(new UspsClient(new UspsOAuth));
}

function uspsPricedClasses(array $prices): void
{
    Http::fake(function ($request) use ($prices) {
        if (! str_contains($request->url(), 'base-rates/search')) {
            return Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200);
        }

        $mailClass = $request['mailClass'] ?? '';

        return isset($prices[$mailClass])
            ? Http::response(['totalBasePrice' => $prices[$mailClass]], 200)
            : Http::response(['errors' => [['code' => '030002', 'title' => 'No price for this class']]], 400);
    });
}

it('quotes every allowed class, cheapest first', function () {
    uspsPricedClasses(['USPS_GROUND_ADVANTAGE' => 16.3, 'PRIORITY_MAIL' => 21.42]);

    $rates = uspsRateService()->quote(
        items: [['weight' => 1.0, 'quantity' => 2, 'store_weight_unit' => 'LBS']],
        shipToCountry: 'US',
        shipToPostcode: '11225',
        origin: ['zipcode' => '87410'],
    );

    expect($rates)->toHaveCount(2)
        ->and($rates[0]->serviceCode)->toBe('USPS_GROUND_ADVANTAGE')
        ->and($rates[0]->amount)->toBe(16.3)
        ->and($rates[0]->currency)->toBe('USD')
        ->and($rates[1]->serviceCode)->toBe('PRIORITY_MAIL');
});

it('skips a failing mail class instead of losing the whole quote', function () {
    uspsPricedClasses(['USPS_GROUND_ADVANTAGE' => 16.3]);

    $rates = uspsRateService()->quote(
        items: [['weight' => 2.0, 'store_weight_unit' => 'LBS']],
        shipToCountry: 'US',
        shipToPostcode: '11225',
        origin: ['zipcode' => '87410'],
    );

    expect($rates)->toHaveCount(1)
        ->and($rates[0]->serviceCode)->toBe('USPS_GROUND_ADVANTAGE');
});

it('returns no rates when one item kills every class', function () {
    Http::fake(function ($request) {
        if (! str_contains($request->url(), 'base-rates/search')) {
            return Http::response(['access_token' => 'tok'], 200);
        }

        return $request['weight'] === 2.0
            ? Http::response(['totalBasePrice' => 16.3], 200)
            : Http::response(['errors' => [['code' => '030002', 'title' => 'No price']]], 400);
    });

    $rates = uspsRateService()->quote(
        items: [
            ['weight' => 2.0, 'store_weight_unit' => 'LBS'],
            ['weight' => 5.0, 'store_weight_unit' => 'LBS'],
        ],
        shipToCountry: 'US',
        shipToPostcode: '11225',
        origin: ['zipcode' => '87410'],
    );

    expect($rates)->toBe([]);
});

it('sums per-item prices and only keeps classes every item can use', function () {
    Http::fake(function ($request) {
        if (! str_contains($request->url(), 'base-rates/search')) {
            return Http::response(['access_token' => 'tok'], 200);
        }

        $prices = $request['weight'] === 2.0
            ? ['USPS_GROUND_ADVANTAGE' => 10.0, 'PRIORITY_MAIL' => 20.0]
            : ['USPS_GROUND_ADVANTAGE' => 5.0];

        $mailClass = $request['mailClass'] ?? '';

        return isset($prices[$mailClass])
            ? Http::response(['totalBasePrice' => $prices[$mailClass]], 200)
            : Http::response(['errors' => [['code' => '030002', 'title' => 'No price']]], 400);
    });

    $rates = uspsRateService()->quote(
        items: [
            ['weight' => 2.0, 'store_weight_unit' => 'LBS'],
            ['weight' => 1.0, 'store_weight_unit' => 'LBS'],
        ],
        shipToCountry: 'US',
        shipToPostcode: '11225',
        origin: ['zipcode' => '87410'],
    );

    expect($rates)->toHaveCount(1)
        ->and($rates[0]->serviceCode)->toBe('USPS_GROUND_ADVANTAGE')
        ->and($rates[0]->amount)->toBe(15.0);
});

it('converts kilograms into pounds and floors at one ounce', function () {
    $captured = [];

    Http::fake(function ($request) use (&$captured) {
        if (! str_contains($request->url(), 'base-rates/search')) {
            return Http::response(['access_token' => 'tok'], 200);
        }

        $captured[] = $request->data();

        return Http::response(['totalBasePrice' => 1.0], 200);
    });

    uspsRateService()->quote(
        items: [
            ['weight' => 1.0, 'quantity' => 1, 'store_weight_unit' => 'kgs'],
            ['weight' => 0.0, 'quantity' => 3, 'store_weight_unit' => 'LBS'],
        ],
        shipToCountry: 'US',
        shipToPostcode: '11225',
        origin: ['zipcode' => '87410'],
    );

    $weights = array_column($captured, 'weight');

    expect($weights[0])->toBe(2.2)
        ->and($weights[1])->toBe(2.2)
        ->and($weights[2])->toBe(0.06)
        ->and($weights[3])->toBe(0.06);
});

it('prefers product dimensions and falls back to the default box', function () {
    $captured = [];

    Http::fake(function ($request) use (&$captured) {
        if (! str_contains($request->url(), 'base-rates/search')) {
            return Http::response(['access_token' => 'tok'], 200);
        }

        $captured[] = $request->data();

        return Http::response(['totalBasePrice' => 1.0], 200);
    });

    uspsRateService()->quote(
        items: [
            ['weight' => 1.0, 'store_weight_unit' => 'LBS', 'length' => 8.5, 'width' => 5.5, 'height' => 3.25],
            ['weight' => 1.0, 'store_weight_unit' => 'LBS'],
        ],
        shipToCountry: 'US',
        shipToPostcode: '11225',
        origin: ['zipcode' => '87410'],
    );

    expect($captured[0])->toHaveKey('length', 8.5)
        ->and($captured[0])->toHaveKey('width', 5.5)
        ->and($captured[0])->toHaveKey('height', 3.25)
        ->and($captured[2])->toHaveKey('length', 12.0)
        ->and($captured[2])->toHaveKey('width', 9.0)
        ->and($captured[2])->toHaveKey('height', 6.0);
});

it('refuses to quote without any dimensions', function () {
    config([
        'carriers.usps.length' => null,
        'carriers.usps.width' => null,
        'carriers.usps.height' => null,
    ]);

    uspsRateService()->quote(
        items: [['weight' => 1.0, 'store_weight_unit' => 'LBS']],
        shipToCountry: 'US',
        shipToPostcode: '11225',
        origin: ['zipcode' => '87410'],
    );
})->throws(RuntimeException::class, 'USPS requires package dimensions');

it('quotes international classes for foreign destinations only', function () {
    config(['carriers.usps.services' => 'USPS_GROUND_ADVANTAGE,PRIORITY_MAIL_INTERNATIONAL']);

    $captured = [];

    Http::fake(function ($request) use (&$captured) {
        if (! str_contains($request->url(), 'base-rates/search')) {
            return Http::response(['access_token' => 'tok'], 200);
        }

        $captured[] = $request->url();

        return Http::response(['totalBasePrice' => 48.81], 200);
    });

    $rates = uspsRateService()->quote(
        items: [['weight' => 2.2, 'store_weight_unit' => 'LBS']],
        shipToCountry: 'CA',
        shipToPostcode: 'M5V 2T6',
        origin: ['zipcode' => '87410'],
    );

    expect($rates)->toHaveCount(1)
        ->and($rates[0]->serviceCode)->toBe('PRIORITY_MAIL_INTERNATIONAL')
        ->and($captured[0])->toContain('international-prices');
});

it('applies the handling fee', function () {
    uspsPricedClasses(['USPS_GROUND_ADVANTAGE' => 10.0]);

    config([
        'carriers.usps.handling_fee_type' => 'percent',
        'carriers.usps.handling_fee_amount' => '10',
    ]);

    $rates = uspsRateService()->quote(
        items: [['weight' => 1.0, 'store_weight_unit' => 'LBS']],
        shipToCountry: 'US',
        shipToPostcode: '11225',
        origin: ['zipcode' => '87410'],
    );

    expect($rates[0]->amount)->toBe(11.0);
});

it('caches identical quotes for the configured ttl', function () {
    uspsPricedClasses(['USPS_GROUND_ADVANTAGE' => 16.3, 'PRIORITY_MAIL' => 21.42]);

    config(['carriers.usps.rate_cache_ttl' => '10']);

    $service = uspsRateService();

    $args = [
        'items' => [['weight' => 1.0, 'store_weight_unit' => 'LBS']],
        'shipToCountry' => 'US',
        'shipToPostcode' => '11225',
        'origin' => ['zipcode' => '87410'],
    ];

    $first = $service->quote(...$args);
    $second = uspsRateService()->quote(...$args);

    expect($second[0]->amount)->toBe($first[0]->amount);

    Http::assertSentCount(3);
});
