<?php

namespace Ashrafic\UspsShipping\Support;

class ConfigResolver
{
    /**
     * Resolved value memo, per request.
     */
    protected array $memo = [];

    /**
     * Resolve a carrier setting: Bagisto admin value when inside Bagisto, config file default otherwise.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $value = $this->fromBagisto($key) ?? config('carriers.usps.'.$key, $default);

        return $this->memo[$key] = $value;
    }

    /**
     * Read through Bagisto's core config when the helper exists.
     */
    protected function fromBagisto(string $key): mixed
    {
        if (! function_exists('core')) {
            return null;
        }

        try {
            return core()->getConfigData('sales.carriers.usps.'.$key);
        } catch (\Throwable) {
            return null;
        }
    }
}
