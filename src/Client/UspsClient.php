<?php

namespace Ashrafic\UspsShipping\Client;

use Ashrafic\UspsShipping\Support\ConfigResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class UspsClient
{
    /**
     * Domestic base rates search endpoint.
     */
    public const DOMESTIC_RATES_URL = 'https://api.usps.com/prices/v3/base-rates/search';

    /**
     * International base rates search endpoint.
     */
    public const INTERNATIONAL_RATES_URL = 'https://api.usps.com/international-prices/v3/base-rates/search';

    /**
     * USPS error code for a parcel marked machinable that the route classifies nonstandard.
     */
    public const NONSTANDARD_REJECTION = '030011';

    /**
     * Create a new client instance.
     */
    public function __construct(protected UspsOAuth $oauth, protected ConfigResolver $configResolver = new ConfigResolver) {}

    /**
     * Resolve an access token, failing fast on credential problems.
     */
    public function accessToken(): string
    {
        return $this->oauth->token();
    }

    /**
     * Search base rates; a machinable classification rejection is retried once as nonstandard.
     */
    public function searchBaseRates(array $payload): array
    {
        $url = $this->ratesUrlFor($payload);

        $response = Http::withToken($this->oauth->token())
            ->acceptJson()
            ->timeout(20)
            ->retry(2, 200, throw: false)
            ->post($url, $payload);

        if ($response->status() === 400 && $this->nonstandardRejection($response->json())) {
            $payload['processingCategory'] = 'NONSTANDARD';

            $response = Http::withToken($this->oauth->token())
                ->acceptJson()
                ->timeout(20)
                ->post($url, $payload);
        }

        $body = $response->json() ?? [];

        if (! $response->successful()) {
            $this->log('error', 'USPS API failure', ['url' => $url, 'response' => $body]);

            throw new RuntimeException("USPS API error (HTTP {$response->status()}): ".$this->extractError($body));
        }

        $this->log('debug', 'USPS API call', ['url' => $url, 'mailClass' => $payload['mailClass'] ?? null]);

        return $body;
    }

    /**
     * Pick the endpoint that prices the payload's destination.
     */
    protected function ratesUrlFor(array $payload): string
    {
        return isset($payload['destinationCountryCode'])
            ? self::INTERNATIONAL_RATES_URL
            : self::DOMESTIC_RATES_URL;
    }

    /**
     * Whether the error payload rejects the machinable classification.
     */
    protected function nonstandardRejection(mixed $payload): bool
    {
        if (! is_array($payload)) {
            return false;
        }

        foreach ($payload['errors'] ?? [] as $error) {
            if (($error['code'] ?? null) === self::NONSTANDARD_REJECTION) {
                return true;
            }
        }

        return ($payload['error']['code'] ?? null) === self::NONSTANDARD_REJECTION;
    }

    /**
     * Pull the first human-readable error, prefixed with its USPS code when present.
     */
    protected function extractError(array $payload): string
    {
        $error = $payload['errors'][0]['title'] ?? null
            ?? $payload['error']['message'] ?? null
            ?? $payload['message'] ?? null;

        if ($error === null) {
            return 'Unknown USPS API error.';
        }

        $code = $payload['errors'][0]['code'] ?? $payload['error']['code'] ?? null;

        return $code !== null ? "[{$code}] {$error}" : (string) $error;
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
