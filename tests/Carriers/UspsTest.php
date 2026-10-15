<?php

use Ashrafic\UspsShipping\Carriers\Usps;
use Illuminate\Support\Facades\Http;
use Webkul\Checkout\Facades\Cart;

beforeEach(function () {
    config([
        'carriers.usps.active' => true,
        'carriers.usps.title' => 'USPS Shipping',
        'carriers.usps.description' => 'Live USPS rates',
        'carriers.usps.is_calculate_tax' => true,
        'carriers.usps.client_id' => 'id-123',
        'carriers.usps.client_secret' => 'secret-456',
        'carriers.usps.services' => 'USPS_GROUND_ADVANTAGE',
        'carriers.usps.length' => '12',
        'carriers.usps.width' => '9',
        'carriers.usps.height' => '6',
        'carriers.usps.handling_fee_type' => 'fixed',
        'carriers.usps.handling_fee_amount' => '0',
        'carriers.usps.rate_cache_ttl' => '0',
        'carriers.usps.debug' => false,
        'shipping.origin.zipcode' => '87410',
        'shipping.origin.country' => 'US',
        'general.general.locale_options.weight_unit' => 'LBS',
    ]);

    Http::fake([
        'api.usps.com/oauth2/v3/token' => Http::response([
            'access_token' => 'tok', 'expires_in' => 3600,
        ], 200),
    ]);
});

function uspsFakeSuccessfulRates(): void
{
    Http::fake([
        'api.usps.com/oauth2/v3/token' => Http::response([
            'access_token' => 'tok', 'expires_in' => 3600,
        ], 200),
        'api.usps.com/prices/v3/base-rates/search' => Http::response([
            'totalBasePrice' => 16.3,
        ], 200),
    ]);
}

function uspsCartItem(float $weight, int $quantity = 1, ?float $length = null): object
{
    $product = new stdClass;
    $product->weight = (string) $weight;
    $product->length = $length !== null ? (string) $length : '';
    $product->width = $length !== null ? (string) ($length / 2) : '';
    $product->height = $length !== null ? (string) ($length / 4) : '';

    $item = new class($product, $quantity)
    {
        public function __construct(
            public stdClass $product,
            public int $quantity,
        ) {}

        public function getTypeInstance(): object
        {
            return new class
            {
                public function isStockable(): bool
                {
                    return true;
                }
            };
        }
    };

    return $item;
}

function uspsCartWith(array $items, ?string $country = 'US', ?string $postcode = '11225'): void
{
    $address = new stdClass;
    $address->country = $country;
    $address->postcode = $postcode;

    $cart = new stdClass;
    $cart->shipping_address = $address;
    $cart->items = collect($items);

    Cart::$cart = $cart;
}

it('returns false when no shipping address exists yet', function () {
    Cart::$cart = null;

    expect((new Usps)->calculate())->toBeFalse();
});

it('maps quoted rates into bagisto shipping rates', function () {
    uspsFakeSuccessfulRates();

    uspsCartWith([
        uspsCartItem(2.0),
        uspsCartItem(1.0, 2, 8.5),
    ]);

    $rates = (new Usps)->calculate();

    expect($rates)->toBeArray()
        ->and($rates)->toHaveCount(1)
        ->and($rates[0]->carrier)->toBe('usps')
        ->and($rates[0]->carrier_title)->toBe('USPS Shipping')
        ->and($rates[0]->method)->toBe('usps_USPS_GROUND_ADVANTAGE')
        ->and($rates[0]->method_title)->toBe('USPS Ground Advantage')
        ->and($rates[0]->base_price)->toBe(32.6);
});

it('sends product dimensions through to the payload', function () {
    uspsFakeSuccessfulRates();

    uspsCartWith([uspsCartItem(2.0, 1, 8.5)]);

    (new Usps)->calculate();

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'base-rates/search')) {
            return false;
        }

        return $request['length'] === 8.5 && $request['weight'] === 2.0;
    });
});

it('returns false instead of breaking checkout when the api fails', function () {
    Http::fake([
        'api.usps.com/oauth2/v3/token' => Http::response(['access_token' => 'tok'], 200),
        'api.usps.com/*' => Http::response(['error' => ['code' => '500', 'message' => 'boom']], 500),
    ]);

    uspsCartWith([uspsCartItem(2.0)]);

    expect((new Usps)->calculate())->toBeFalse();
});
