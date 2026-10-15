<?php

namespace Ashrafic\UspsShipping\Carriers;

use Ashrafic\UspsShipping\Data\QuotedRate;
use Ashrafic\UspsShipping\Services\RateService;
use Webkul\Checkout\Facades\Cart;
use Webkul\Checkout\Models\CartShippingRate;
use Webkul\Shipping\Carriers\AbstractShipping;

class Usps extends AbstractShipping
{
    /**
     * Shipping method carrier code.
     *
     * @var string
     */
    protected $code = 'usps';

    /**
     * Calculate shipping rates for the current cart.
     *
     * @return array|false
     */
    public function calculate()
    {
        if (! $this->isAvailable()) {
            return false;
        }

        $cart = Cart::getCart();

        if (! $cart?->shipping_address) {
            return false;
        }

        $items = $this->cartItems($cart);

        if ($items === []) {
            return false;
        }

        try {
            $quotes = app(RateService::class)->quote(
                items: $items,
                shipToCountry: (string) $cart->shipping_address->country,
                shipToPostcode: (string) $cart->shipping_address->postcode,
                origin: $this->origin(),
            );
        } catch (\Throwable $e) {
            logger()->error('[USPS] rate calculation failed', ['exception' => $e]);

            return false;
        }

        if ($quotes === []) {
            return false;
        }

        return array_map(fn (QuotedRate $quote) => $this->toShippingRate($quote), $quotes);
    }

    /**
     * Extract shippable items from the cart.
     */
    protected function cartItems($cart): array
    {
        return $cart->items
            ->filter(fn ($item) => $item->getTypeInstance()->isStockable())
            ->map(fn ($item) => [
                'weight' => (float) ($item->product->weight ?? 0),
                'quantity' => (int) $item->quantity,
                'store_weight_unit' => core()->getConfigData('general.general.locale_options.weight_unit') ?: 'LBS',
                'length' => $this->optionalFloat($item->product->length ?? null),
                'width' => $this->optionalFloat($item->product->width ?? null),
                'height' => $this->optionalFloat($item->product->height ?? null),
            ])
            ->values()
            ->all();
    }

    /**
     * Cast an optional dimension; EAV attributes arrive as strings or empty strings.
     */
    protected function optionalFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $float = (float) $value;

        return $float > 0 ? $float : null;
    }

    /**
     * Shipper origin from the channel shipping configuration.
     */
    protected function origin(): array
    {
        return [
            'zipcode' => core()->getConfigData('sales.shipping.origin.zipcode'),
            'country' => core()->getConfigData('sales.shipping.origin.country'),
        ];
    }

    /**
     * Convert a quoted rate into a Bagisto shipping rate.
     */
    protected function toShippingRate(QuotedRate $quote): CartShippingRate
    {
        $rate = new CartShippingRate;

        $rate->carrier = $this->getCode();
        $rate->carrier_title = $this->getConfigData('title');
        $rate->method = $this->getCode().'_'.$quote->serviceCode;
        $rate->method_title = $quote->serviceLabel;
        $rate->method_description = $this->getConfigData('description');
        $rate->is_calculate_tax = $this->getConfigData('is_calculate_tax');
        $rate->price = core()->convertPrice($quote->amount);
        $rate->base_price = $quote->amount;

        return $rate;
    }
}
