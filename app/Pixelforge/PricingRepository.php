<?php

namespace App\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;

/**
 * Pixelforge's current pricing, read from GET /v1/pricing and never hard-coded.
 */
class PricingRepository
{
    public const CACHE_KEY = 'pixelforge.pricing';

    public const LAST_KNOWN_KEY = 'pixelforge.pricing.last';

    private const CACHE_SECONDS = 600;

    public function __construct(private PixelforgeClient $client) {}

    /**
     * The pricing cached for up to 10 minutes; the last pricing fetched if
     * Pixelforge can't be reached; null if pricing has never been fetched.
     */
    public function current(): ?PriceSheet
    {
        $body = Cache::get(self::CACHE_KEY) ?? $this->fetch();

        return $body === null ? null : PriceSheet::fromPricingResponse($body);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetch(): ?array
    {
        try {
            $body = $this->client->pricing();
        } catch (ConnectionException|RequestException) {
            return Cache::get(self::LAST_KNOWN_KEY);
        }

        Cache::put(self::CACHE_KEY, $body, self::CACHE_SECONDS);
        Cache::forever(self::LAST_KNOWN_KEY, $body);

        return $body;
    }
}
