<?php

namespace Ashrafic\UspsShipping\Services;

use Ashrafic\UspsShipping\Client\UspsClient;
use Ashrafic\UspsShipping\Data\QuotedRate;
use Ashrafic\UspsShipping\Support\ConfigResolver;
use Ashrafic\UspsShipping\Support\ServiceCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class RateService
{
    /**
     * Create a new rate service instance.
     */
    public function __construct(
        protected UspsClient $client,
        protected ConfigResolver $configResolver = new ConfigResolver,
        protected ServiceCatalog $catalog = new ServiceCatalog,
    ) {}

    /**
     * Quote every allowed mail class for a set of cart items.
     *
     * Each item is priced separately per mail class; only classes available for every item survive,
     * with their per-item prices summed, so the quote covers the whole shipment.
     *
     * @param  array<int, array{weight: float, quantity: int, store_weight_unit?: string, length?: ?float, width?: ?float, height?: ?float}>  $items
     * @return array<int, QuotedRate>
     */
    public function quote(array $items, string $shipToCountry, ?string $shipToPostcode, array $origin): array
    {
        if ($items === []) {
            return [];
        }

        $cacheKey = $this->cacheKey($items, $shipToCountry, $shipToPostcode, $origin);

        $ttl = max(0, (int) $this->config('rate_cache_ttl', 0));

        if ($ttl > 0 && Cache::has($cacheKey)) {
            return array_map(
                fn (array $row) => new QuotedRate(...$row),
                Cache::get($cacheKey, [])
            );
        }

        $quotes = $this->collectQuotes($items, $shipToCountry, $shipToPostcode, $origin);

        if ($ttl > 0) {
            Cache::put($cacheKey, array_map(fn (QuotedRate $rate) => (array) $rate, $quotes), now()->addSeconds($ttl));
        }

        return $quotes;
    }

    /**
     * Price and merge every item across every allowed mail class.
     *
     * @return array<int, QuotedRate>
     */
    protected function collectQuotes(array $items, string $shipToCountry, ?string $shipToPostcode, array $origin): array
    {
        $domestic = $this->isDomesticDestination($shipToCountry);

        $classes = array_values(array_filter(
            $this->catalog->allowedClasses($this->config('services')),
            fn (string $class) => $this->catalog->isInternationalClass($class) !== $domestic
        ));

        if ($classes === []) {
            return [];
        }

        $this->client->accessToken();

        $perItem = [];

        foreach ($items as $item) {
            $weight = $this->pounds($item);

            $dimensions = $this->dimensions($item);

            $prices = [];

            foreach ($classes as $class) {
                try {
                    $amount = $this->priceItem($weight, $dimensions, $class, $domestic, $shipToCountry, $shipToPostcode, $origin);
                } catch (RuntimeException $exception) {
                    $this->log('warning', 'mail class skipped', ['mail_class' => $class, 'reason' => $exception->getMessage()]);

                    continue;
                }

                $prices[$class] = $amount;
            }

            if ($prices === []) {
                return [];
            }

            $perItem[] = $prices;
        }

        $merged = array_intersect_key(...$perItem);

        $quotes = [];

        foreach ($merged as $class => $unitPrice) {
            $amount = round(array_sum(array_column($perItem, $class)), 4);

            $quotes[] = new QuotedRate(
                serviceCode: $class,
                serviceLabel: $this->catalog->label($class),
                currency: 'USD',
                amount: $this->applyHandlingFee($amount),
            );
        }

        usort($quotes, fn (QuotedRate $left, QuotedRate $right) => $left->amount <=> $right->amount);

        return $quotes;
    }

    /**
     * Price a single item on one mail class through the pricing API.
     *
     * @param  array<string, float>  $dimensions
     */
    protected function priceItem(float $weight, array $dimensions, string $class, bool $domestic, string $shipToCountry, ?string $shipToPostcode, array $origin): float
    {
        $payload = $domestic
            ? $this->domesticPayload($weight, $dimensions, $class, (string) $shipToPostcode, $origin)
            : $this->internationalPayload($weight, $dimensions, $class, $shipToCountry, $shipToPostcode, $origin);

        $body = $this->client->searchBaseRates($payload);

        $amount = $body['totalBasePrice'] ?? null;

        if (! is_numeric($amount)) {
            throw new RuntimeException('USPS response did not include a usable price.');
        }

        return (float) $amount;
    }

    /**
     * Build a domestic base rates payload.
     *
     * @param  array<string, float>  $dimensions
     * @return array<string, float|int|string>
     */
    protected function domesticPayload(float $weight, array $dimensions, string $class, string $destinationZip, array $origin): array
    {
        return [
            'originZIPCode' => $this->zip5((string) ($origin['zipcode'] ?? '')),
            'destinationZIPCode' => $this->zip5($destinationZip),
            'weight' => $weight,
            'mailClass' => $class,
            'processingCategory' => 'MACHINABLE',
            'rateIndicator' => 'SP',
            'destinationEntryFacilityType' => 'NONE',
            'priceType' => 'COMMERCIAL',
            'mailingDate' => now()->toDateString(),
        ] + $dimensions;
    }

    /**
     * Build an international base rates payload.
     *
     * @param  array<string, float>  $dimensions
     * @return array<string, float|int|string>
     */
    protected function internationalPayload(float $weight, array $dimensions, string $class, string $shipToCountry, ?string $shipToPostcode, array $origin): array
    {
        return [
            'originZIPCode' => $this->zip5((string) ($origin['zipcode'] ?? '')),
            'foreignPostalCode' => trim((string) $shipToPostcode),
            'destinationCountryCode' => substr(strtoupper($shipToCountry), 0, 2),
            'weight' => $weight,
            'mailClass' => $class,
            'processingCategory' => 'MACHINABLE',
            'rateIndicator' => 'SP',
            'destinationEntryFacilityType' => 'NONE',
            'priceType' => 'COMMERCIAL',
            'mailingDate' => now()->toDateString(),
        ] + $dimensions;
    }

    /**
     * Resolve an item's weight into the pounds the pricing API expects.
     */
    protected function pounds(array $item): float
    {
        $unitWeight = max(0.0, (float) ($item['weight'] ?? 0));

        $total = $unitWeight * max(1, (int) ($item['quantity'] ?? 1));

        $unit = strtoupper(trim((string) ($item['store_weight_unit'] ?? 'LBS')));

        $pounds = $unit === 'KGS' || $unit === 'KG'
            ? $total * 2.2046226218
            : $total;

        return round(max($pounds, 0.0625), 2);
    }

    /**
     * Resolve the package dimensions for an item: its own when set, the default box otherwise.
     *
     * @return array<string, float>
     */
    protected function dimensions(array $item): array
    {
        $length = (float) ($item['length'] ?? 0);
        $width = (float) ($item['width'] ?? 0);
        $height = (float) ($item['height'] ?? 0);

        if ($length > 0 && $width > 0 && $height > 0) {
            return $this->roundedDimensions($length, $width, $height);
        }

        $boxLength = (float) $this->config('length', 0);
        $boxWidth = (float) $this->config('width', 0);
        $boxHeight = (float) $this->config('height', 0);

        if ($boxLength > 0 && $boxWidth > 0 && $boxHeight > 0) {
            return $this->roundedDimensions($boxLength, $boxWidth, $boxHeight);
        }

        throw new RuntimeException('USPS requires package dimensions. Set the default box under Configure → Sales → Shipping Methods → USPS Shipping, or fill Length × Width × Height on the product.');
    }

    /**
     * Round dimensions to the two decimals the API accepts.
     *
     * @return array<string, float>
     */
    protected function roundedDimensions(float $length, float $width, float $height): array
    {
        return [
            'length' => round($length, 2),
            'width' => round($width, 2),
            'height' => round($height, 2),
        ];
    }

    /**
     * Reduce a postcode to the five digit ZIP the domestic endpoint expects.
     */
    protected function zip5(string $postcode): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9]/', '', $postcode) ?? '';

        return strtoupper(substr($normalized, 0, 5));
    }

    /**
     * Whether USPS prices the destination on the domestic table.
     */
    protected function isDomesticDestination(string $country): bool
    {
        return in_array(strtoupper($country), ['US', 'AS', 'GU', 'MP', 'PR', 'PW', 'VI'], true);
    }

    /**
     * Apply the configured handling fee to a quoted amount.
     */
    protected function applyHandlingFee(float $amount): float
    {
        $type = (string) $this->config('handling_fee_type', 'fixed');
        $fee = (float) $this->config('handling_fee_amount', 0);

        if ($fee <= 0) {
            return round($amount, 2);
        }

        $amount += $type === 'percent' ? $amount * ($fee / 100) : $fee;

        return round($amount, 2);
    }

    /**
     * Build the rate cache key for a quote request.
     */
    protected function cacheKey(array $items, string $shipToCountry, ?string $shipToPostcode, array $origin): string
    {
        return 'usps-shipping.rates.'.hash('sha256', implode('|', [
            json_encode($items),
            strtoupper($shipToCountry),
            trim((string) $shipToPostcode),
            json_encode($origin),
            json_encode($this->catalog->allowedClasses($this->config('services'))),
            (string) $this->config('handling_fee_type'),
            (string) $this->config('handling_fee_amount'),
        ]));
    }

    /**
     * Read carrier configuration value.
     */
    protected function config(string $key, mixed $default = null): mixed
    {
        return $this->configResolver->get($key, $default);
    }

    /**
     * Write to the log when debug mode is enabled.
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        if (! $this->configResolver->get('debug')) {
            return;
        }

        Log::{$level}('[USPS] '.$message, $context);
    }
}
