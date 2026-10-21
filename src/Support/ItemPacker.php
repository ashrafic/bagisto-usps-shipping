<?php

namespace Ashrafic\UspsShipping\Support;

use Ashrafic\UspsShipping\Data\LabelPackage;
use RuntimeException;

class ItemPacker
{
    /**
     * Pounds per kilogram.
     */
    public const LBS_PER_KG = 2.2046226218;

    /**
     * USPS's maximum weight per package for the supported mail classes.
     */
    public const MAX_WEIGHT = 70.0;

    /**
     * Create a new packer instance.
     */
    public function __construct(protected ConfigResolver $configResolver = new ConfigResolver) {}

    /**
     * Pack items into whole-item packages within the 70 pound USPS cap.
     *
     * @param  array<int, array{weight: float, quantity: int, store_weight_unit?: string, length?: ?float, width?: ?float, height?: ?float}>  $items
     * @return array<int, LabelPackage>
     */
    public function pack(array $items): array
    {
        $packages = [];

        foreach ($items as $item) {
            $unitWeight = $this->toPounds(
                (float) ($item['weight'] ?? 0),
                strtoupper(trim((string) ($item['store_weight_unit'] ?? 'LBS')))
            );

            if ($unitWeight <= 0) {
                continue;
            }

            $dimensions = $this->dimensions($item);

            $remaining = round($unitWeight * max(1, (int) ($item['quantity'] ?? 1)), 6);

            while ($remaining > 0) {
                $itemsPerPackage = $unitWeight >= self::MAX_WEIGHT
                    ? 1
                    : max(1, (int) floor((self::MAX_WEIGHT + 1e-9) / $unitWeight));

                $slice = min($remaining, $itemsPerPackage * $unitWeight);

                $packages[] = new LabelPackage(
                    weight: max(round($slice, 2), 0.0625),
                    length: $dimensions['length'],
                    width: $dimensions['width'],
                    height: $dimensions['height'],
                );

                $remaining = round($remaining - $slice, 6);
            }
        }

        return $packages;
    }

    /**
     * Resolve a package's dimensions: the item's own when set, the default box otherwise.
     *
     * @param  array<string, mixed>  $item
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
     * Convert a weight from the store unit to pounds.
     */
    protected function toPounds(float $weight, string $storeUnit): float
    {
        return in_array($storeUnit, ['KGS', 'KG'], true)
            ? $weight * self::LBS_PER_KG
            : $weight;
    }

    /**
     * Read carrier configuration value.
     */
    protected function config(string $key, mixed $default = null): mixed
    {
        return $this->configResolver->get($key, $default);
    }
}
