<?php

namespace Ashrafic\UspsShipping\Client;

use Ashrafic\UspsShipping\Support\ConfigResolver;
use Illuminate\Http\Client\Response;
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
     * Label payment authorization endpoint.
     */
    public const PAYMENT_AUTHORIZATION_URL = 'https://apis.usps.com/payments/v3/payment-authorization';

    /**
     * Domestic label creation endpoint.
     */
    public const LABEL_URL = 'https://apis.usps.com/labels/v3/label';

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
     * Resolve a payment authorization token; labels are charged to the EPS account it names.
     */
    public function paymentAuthorizationToken(): string
    {
        $crid = $this->configResolver->get('payment_crid');
        $mid = $this->configResolver->get('payment_mid');
        $accountNumber = $this->configResolver->get('payment_account_number');

        if (! $crid || ! $mid || ! $accountNumber) {
            throw new RuntimeException('USPS label payment is not configured. Set the CRID, MID and EPS Account Number in admin under Configure → Sales → Shipping Methods → USPS Shipping.');
        }

        $role = [
            'CRID' => (string) $crid,
            'MID' => (string) $mid,
            'accountType' => 'EPS',
            'accountNumber' => (string) $accountNumber,
        ];

        $response = Http::withToken($this->oauth->token())
            ->acceptJson()
            ->timeout(20)
            ->post(self::PAYMENT_AUTHORIZATION_URL, [
                'roles' => [
                    ['roleName' => 'PAYER'] + $role,
                    ['roleName' => 'LABEL_OWNER'] + $role,
                ],
            ]);

        $body = $response->json() ?? [];

        if (! $response->successful() || empty($body['paymentAuthorizationToken'])) {
            $this->log('error', 'USPS payment authorization failure', ['response' => $body]);

            $reason = $response->successful() ? 'response contained no token.' : $this->extractError($body);

            throw new RuntimeException("USPS payment authorization failed: {$reason}");
        }

        return (string) $body['paymentAuthorizationToken'];
    }

    /**
     * Create one domestic label; a machinable classification rejection is retried once as nonstandard.
     */
    public function createLabel(array $payload, string $paymentToken): array
    {
        $response = $this->postLabel($payload, $paymentToken);

        if ($response->status() === 400 && $this->nonstandardRejection($response->json())) {
            $payload['packageDescription']['processingCategory'] = 'NONSTANDARD';

            $response = $this->postLabel($payload, $paymentToken);
        }

        $body = $response->json() ?? [];

        if (! $response->successful()) {
            $this->log('error', 'USPS API failure', ['url' => self::LABEL_URL, 'response' => $body]);

            throw new RuntimeException("USPS API error (HTTP {$response->status()}): ".$this->extractError($body));
        }

        $this->log('debug', 'USPS API call', ['url' => self::LABEL_URL, 'mailClass' => $payload['packageDescription']['mailClass'] ?? null]);

        return $body;
    }

    /**
     * Cancel an unused label and request its refund.
     */
    public function voidLabel(string $trackingNumber): void
    {
        $response = Http::withToken($this->oauth->token())
            ->acceptJson()
            ->timeout(20)
            ->delete(self::LABEL_URL.'/'.$trackingNumber);

        if (! $response->successful()) {
            $body = $response->json() ?? [];

            $this->log('error', 'USPS API failure', ['url' => self::LABEL_URL.'/'.$trackingNumber, 'response' => $body]);

            throw new RuntimeException("USPS API error (HTTP {$response->status()}): ".$this->extractError($body));
        }
    }

    /**
     * Post a label request; never blindly retried, since a succeeded call charges the EPS account.
     */
    protected function postLabel(array $payload, string $paymentToken): Response
    {
        return Http::withToken($this->oauth->token())
            ->acceptJson()
            ->timeout(30)
            ->withHeaders(['X-Payment-Authorization-Token' => $paymentToken])
            ->post(self::LABEL_URL, $payload);
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
