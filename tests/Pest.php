<?php

use App\Pixelforge\PriceSheet;
use App\Pixelforge\WebhookSignature;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * The GET /v1/pricing response from Pixelforge's docs.
 *
 * @return array<string, mixed>
 */
function pixelforgePricing(): array
{
    return json_decode(file_get_contents(__DIR__.'/Fixtures/pricing.json'), true);
}

function docsPriceSheet(): PriceSheet
{
    return PriceSheet::fromPricingResponse(pixelforgePricing());
}

/**
 * A Pixelforge generation object, shaped like the docs' queued example.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pixelforgeGeneration(array $overrides = []): array
{
    return array_replace([
        'id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
        'object' => 'generation',
        'status' => 'queued',
        'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/style.png'],
        'metadata' => [],
        'image_url' => null,
        'image_url_expires_at' => null,
        'usage' => null,
        'rates' => pixelforgePricing()['rates'],
        'error' => null,
        'created_at' => '2026-11-04T14:03:11Z',
        'started_at' => null,
        'completed_at' => null,
    ], $overrides);
}

/**
 * The docs' succeeded generation: 12 text, 765 image, 6,450 output and 3,184
 * reasoning tokens, which cost Pixelforge $0.305510000.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pixelforgeSucceededGeneration(array $overrides = []): array
{
    return pixelforgeGeneration(array_replace([
        'status' => 'succeeded',
        'image_url' => 'https://pixelforge.promptedit.dev/images/gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K.png?expires=1762351409&signature=abc',
        'image_url_expires_at' => '2026-11-05T14:03:29Z',
        'usage' => [
            'input_text_tokens' => 12,
            'input_image_tokens' => 765,
            'output_tokens' => 6450,
            'reasoning_tokens' => 3184,
            'total_tokens' => 10411,
        ],
        'started_at' => '2026-11-04T14:03:13Z',
        'completed_at' => '2026-11-04T14:03:29Z',
    ], $overrides));
}

/**
 * A Pixelforge error response in the documented shape.
 *
 * @param  array<string, string>  $headers
 */
function pixelforgeError(int $status, string $code, array $headers = []): PromiseInterface
{
    return Http::response(['error' => ['type' => 'error', 'code' => $code, 'message' => "Pixelforge said {$code}."]], $status, $headers);
}

/**
 * A Pixelforge webhook event wrapping a generation object.
 *
 * @param  array<string, mixed>  $object
 * @return array<string, mixed>
 */
function pixelforgeEvent(string $type, array $object, string $id = 'evt_01JC2Z9B7K2M4P6R8T0V3X5Z7A'): array
{
    return [
        'id' => $id,
        'object' => 'event',
        'type' => $type,
        'created_at' => '2026-11-04T14:03:29Z',
        'data' => ['object' => $object],
    ];
}

/**
 * POST an event to the webhook endpoint, signed like Pixelforge signs it.
 *
 * @param  array<string, mixed>  $event
 */
function sendPixelforgeWebhook(array $event, ?string $secret = null, ?int $timestamp = null): TestResponse
{
    $payload = json_encode($event);
    $signature = WebhookSignature::sign($payload, $secret ?? config('services.pixelforge.webhook_secret'), $timestamp ?? time());

    return test()->call('POST', route('webhooks.pixelforge'), server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => $signature,
        'HTTP_PIXELFORGE_EVENT_ID' => $event['id'],
        'HTTP_PIXELFORGE_EVENT_TYPE' => $event['type'],
    ], content: $payload);
}
