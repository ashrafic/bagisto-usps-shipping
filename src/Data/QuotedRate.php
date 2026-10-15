<?php

namespace Ashrafic\UspsShipping\Data;

final class QuotedRate
{
    /**
     * Create a new quoted rate.
     */
    public function __construct(
        public readonly string $serviceCode,
        public readonly string $serviceLabel,
        public readonly string $currency,
        public readonly float $amount,
        public readonly int $packages = 1,
    ) {}
}
