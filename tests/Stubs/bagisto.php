<?php

// Minimal Bagisto stand-ins so the carrier glue layer can be exercised in the
// testbench suite. Loaded only when the real Bagisto classes are absent.

namespace Webkul\Shipping\Carriers
{
    if (! class_exists(AbstractShipping::class)) {
        abstract class AbstractShipping
        {
            /**
             * Shipping method carrier code.
             *
             * @var string
             */
            protected $code;

            /**
             * Get carrier code.
             */
            public function getCode(): string
            {
                return $this->code;
            }

            /**
             * Read carrier configuration through the core helper.
             */
            public function getConfigData($field)
            {
                return core()->getConfigData('sales.carriers.'.$this->getCode().'.'.$field);
            }

            /**
             * Check carrier availability.
             */
            public function isAvailable(): bool
            {
                return (bool) $this->getConfigData('active');
            }
        }
    }
}

namespace Webkul\Checkout\Models
{
    if (! class_exists(CartShippingRate::class)) {
        #[\AllowDynamicProperties]
        class CartShippingRate {}
    }
}

namespace Webkul\Checkout\Facades
{
    if (! class_exists(Cart::class)) {
        class Cart
        {
            /**
             * Test-injected cart.
             *
             * @var object|null
             */
            public static $cart;

            /**
             * Get the current cart.
             */
            public static function getCart(): ?object
            {
                return static::$cart;
            }
        }
    }
}

namespace {
    if (! class_exists(FakeBagistoCore::class)) {
        #[AllowDynamicProperties]
        class FakeBagistoCore
        {
            /**
             * Resolve config, mirroring Bagisto's sales-prefixed config keys.
             */
            public function getConfigData($field)
            {
                $rest = str_starts_with($field, 'sales.') ? substr($field, 6) : $field;

                return config($rest);
            }

            /**
             * Get base currency code.
             */
            public function getBaseCurrencyCode()
            {
                return config('bagisto_stubs.base_currency', 'USD');
            }

            /**
             * Convert price (identity in tests).
             */
            public function convertPrice($price)
            {
                return $price;
            }
        }

        function core(): FakeBagistoCore
        {
            static $instance;

            return $instance ??= new FakeBagistoCore;
        }
    }
}
