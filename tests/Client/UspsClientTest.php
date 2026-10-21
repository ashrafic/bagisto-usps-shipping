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

it('fetches a payment authorization token with payer and label owner roles', function () {
    config([
        'carriers.usps.payment_crid' => '1234567890',
        'carriers.usps.payment_mid' => '123456',
        'carriers.usps.payment_account_number' => '7654321',
    ]);

    Http::fake([
        'apis.usps.com/payments/v3/payment-authorization' => Http::response(['paymentAuthorizationToken' => 'pay-tok'], 200),
    ]);

    $token = (new UspsClient(new UspsOAuth))->paymentAuthorizationToken();

    expect($token)->toBe('pay-tok');

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'payments/v3/payment-authorization')) {
            return true;
        }

        $roles = $request->data()['roles'];

        return collect($roles)->pluck('roleName')->sort()->values()->all() === ['LABEL_OWNER', 'PAYER']
            && collect($roles)->every(fn (array $role) => $role['CRID'] === '1234567890'
                && $role['MID'] === '123456'
                && $role['accountType'] === 'EPS'
                && $role['accountNumber'] === '7654321');
    });
});

it('refuses label payment when credentials are missing', function () {
    expect(fn () => (new UspsClient(new UspsOAuth))->paymentAuthorizationToken())
        ->toThrow(RuntimeException::class, 'label payment is not configured');
});

it('sends the payment token header when creating labels', function () {
    Http::fake([
        'apis.usps.com/labels/v3/label' => Http::response(['trackingNumber' => '94055', 'labelImage' => 'JVBERi0'], 200),
    ]);

    $body = (new UspsClient(new UspsOAuth))->createLabel([
        'packageDescription' => ['mailClass' => 'PRIORITY_MAIL'],
    ], 'pay-tok');

    expect($body['trackingNumber'])->toBe('94055');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'labels/v3/label')
        && $request->hasHeader('X-Payment-Authorization-Token', ['pay-tok']));
});

it('retries a label as nonstandard when the machinable classification is rejected', function () {
    Http::fake([
        'apis.usps.com/labels/v3/label' => Http::sequence()
            ->push([
                'error' => ['code' => '030011', 'message' => 'Package was marked as machinable, but is nonstandard'],
            ], 400)
            ->push(['trackingNumber' => '94055', 'labelImage' => 'JVBERi0'], 200),
    ]);

    $body = (new UspsClient(new UspsOAuth))->createLabel([
        'packageDescription' => ['mailClass' => 'PRIORITY_MAIL', 'processingCategory' => 'MACHINABLE'],
    ], 'pay-tok');

    expect($body['trackingNumber'])->toBe('94055');

    $categories = [];

    Http::assertSent(function ($request) use (&$categories) {
        if (str_contains($request->url(), 'labels/v3/label')) {
            $categories[] = $request->data()['packageDescription']['processingCategory'];
        }

        return true;
    });

    expect($categories)->toBe(['MACHINABLE', 'NONSTANDARD']);
});
