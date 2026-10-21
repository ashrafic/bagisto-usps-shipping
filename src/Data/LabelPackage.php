<?php

namespace Ashrafic\UspsShipping\Data;

class LabelPackage
{
    /**
     * Create a new package instance, weights in pounds and dimensions in inches.
     */
    public function __construct(
        public readonly float $weight,
        public readonly float $length,
        public readonly float $width,
        public readonly float $height,
    ) {}
}
