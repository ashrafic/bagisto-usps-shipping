<?php

namespace Ashrafic\UspsShipping\Console\Commands;

use Ashrafic\UspsShipping\Services\RateService;
use Illuminate\Console\Command;
use Throwable;

class TestUspsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'usps:test
                            {--postcode=10001 : Destination postcode}
                            {--country=US : Destination country}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Smoke-test USPS credentials and print sample rates';

    /**
     * Execute the console command.
     */
    public function handle(RateService $rateService): int
    {
        try {
            $rates = $rateService->quote(
                items: [[
                    'weight' => 5.0,
                    'quantity' => 1,
                    'store_weight_unit' => 'LBS',
                ]],
                shipToCountry: $this->option('country'),
                shipToPostcode: $this->option('postcode'),
                origin: ['zipcode' => $this->originZip()],
            );
        } catch (Throwable $e) {
            $this->error('USPS request failed: ['.get_class($e).'] '.$e->getMessage());

            return self::FAILURE;
        }

        if ($rates === []) {
            $this->warn('Request succeeded but no services were returned. Check the Allowed Methods setting.');

            return self::SUCCESS;
        }

        $this->table(
            ['Mail class', 'Code', 'Amount'],
            array_map(fn ($rate) => [
                $rate->serviceLabel,
                $rate->serviceCode,
                $rate->currency.' '.number_format($rate->amount, 2),
            ], $rates)
        );

        return self::SUCCESS;
    }

    /**
     * Resolve the origin ZIP through Bagisto when available.
     */
    protected function originZip(): ?string
    {
        if (function_exists('core')) {
            try {
                return core()->getConfigData('sales.shipping.origin.zipcode');
            } catch (Throwable) {
                return null;
            }
        }

        return config('shipping.origin.zipcode');
    }
}
