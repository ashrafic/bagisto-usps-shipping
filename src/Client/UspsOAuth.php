<?php

namespace Ashrafic\UspsShipping\Client;

use Ashrafic\UspsShipping\Support\ConfigResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class UspsOAuth
{
    /**
     * OAuth token endpoint.
     */
    public const TOKEN_URL = 'https://api.usps.com/oauth2/v3/token';

    /**
     * Create a new OAuth client instance.
     */
    public function __construct(protected ConfigResolver $configResolver = new ConfigResolver) {}

    /**
     * Fetch a cached access token.
     */
    public function token(): string
    {
        $cacheKey = 'usps-shipping.oauth.'.$this->fingerprint();

        $token = Cache::get($cacheKey);

        if ($token) {
            return $token;
        }

        $clientId = $this->config('client_id');
        $clientSecret = $this->config('client_secret');

        if (! $clientId || ! $clientSecret) {
            throw new RuntimeException('USPS credentials are not configured. Set the Client ID and Client Secret in admin under Configure → Sales → Shipping Methods → USPS Shipping.');
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);

        $payload = $response->json();

        if (! $response->successful() || empty($payload['access_token'])) {
            throw new RuntimeException('USPS OAuth failed: '.(string) $response->body());
        }

        $ttl = max(60, ((int) ($payload['expires_in'] ?? 0)) - 300);

        Cache::put($cacheKey, $payload['access_token'], $ttl);

        return $payload['access_token'];
    }

    /**
     * Cache fingerprint unique to the credentials.
     */
    protected function fingerprint(): string
    {
        return hash('sha256', implode('|', [
            $this->config('client_id'),
            $this->config('client_secret'),
        ]));
    }

    /**
     * Read carrier configuration value.
     */
    protected function config(string $key): mixed
    {
        return $this->configResolver->get($key);
    }
}
