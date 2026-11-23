<?php

use App\Pixelforge\CreateOutcome;
use App\Pixelforge\CreateResult;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

const CLIENT_CREATE_URL = 'https://pixelforge.promptedit.dev/v1/generations';

beforeEach(function () {
    config(['services.pixelforge.api_key' => 'pf_test_key']);
    Http::preventStrayRequests();
});

function clientCreate(array $parameters = []): CreateResult
{
    return app(PixelforgeClient::class)->createGeneration(
        array_replace(['prompt' => 'A lighthouse', 'size' => '1024x1024', 'quality' => 'medium', 'reference_images' => []], $parameters),
        'key-123',
        '01local',
    );
}

it('sends only documented parameters, the idempotency key, metadata and the API key', function () {
    Http::fake([CLIENT_CREATE_URL => Http::response(pixelforgeGeneration(), 202)]);

    $result = clientCreate(['negative_prompt' => 'blurry', 'estimate' => 1]);

    expect($result->outcome)->toBe(CreateOutcome::Accepted)
        ->and($result->generation['id'])->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === CLIENT_CREATE_URL
        && $request->header('Idempotency-Key') === ['key-123']
        && $request->header('Authorization') === ['Bearer pf_test_key']
        && array_keys($request->data()) === ['prompt', 'size', 'quality', 'reference_images', 'metadata']
        && $request['metadata'] === ['app_generation_id' => '01local']);
});

it('treats an idempotent replay as accepted', function () {
    Http::fake([CLIENT_CREATE_URL => Http::response(pixelforgeSucceededGeneration(), 202, ['Idempotent-Replayed' => 'true'])]);

    $result = clientCreate();

    expect($result->outcome)->toBe(CreateOutcome::Accepted)
        ->and($result->generation['status'])->toBe('succeeded');
});

it('classifies rejections by status and code', function (int $status, string $code, CreateOutcome $outcome) {
    Http::fake([CLIENT_CREATE_URL => pixelforgeError($status, $code, ['Retry-After' => '2'])]);

    $result = clientCreate();

    expect($result->outcome)->toBe($outcome)
        ->and($result->errorCode)->toBe($code)
        ->and($result->httpStatus)->toBe($status)
        ->and($result->retryAfterSeconds)->toBe(2);
})->with([
    'overloaded' => [503, 'overloaded', CreateOutcome::Retryable],
    'rate limit' => [429, 'rate_limit_exceeded', CreateOutcome::Retryable],
    'concurrency limit' => [429, 'concurrency_limit_exceeded', CreateOutcome::NoSlot],
    'daily limit' => [429, 'daily_limit_exceeded', CreateOutcome::Final],
    'content policy' => [400, 'content_policy_violation', CreateOutcome::Final],
    'bad key' => [401, 'invalid_api_key', CreateOutcome::Final],
    'key reused' => [409, 'idempotency_key_reused', CreateOutcome::Final],
    'validation' => [422, 'validation_failed', CreateOutcome::Final],
    'bad gateway' => [502, 'bad_gateway', CreateOutcome::Unknown],
]);

it('reports a connection failure as unknown, because Pixelforge may have created it', function () {
    Http::fake([CLIENT_CREATE_URL => fn () => throw new ConnectionException('Operation timed out')]);

    expect(clientCreate()->outcome)->toBe(CreateOutcome::Unknown);
});

it('logs the status, code and generation id but never the API key', function () {
    Log::spy();
    Http::fake([CLIENT_CREATE_URL => pixelforgeError(503, 'overloaded')]);

    clientCreate();

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context) => $context === ['http_status' => 503, 'code' => 'overloaded', 'generation_id' => '01local']
            && ! str_contains($message.json_encode($context), 'pf_test_key'),
    );
});

it('fetches a generation by its Pixelforge id', function () {
    Http::fake([CLIENT_CREATE_URL.'/gen_abc' => Http::response(pixelforgeGeneration(['id' => 'gen_abc']))]);

    expect(app(PixelforgeClient::class)->getGeneration('gen_abc', '01local')['id'])->toBe('gen_abc');
});

it('asks for usage with the given date range', function () {
    Http::fake(['https://pixelforge.promptedit.dev/v1/usage*' => Http::response(['object' => 'usage', 'data' => []])]);

    app(PixelforgeClient::class)->usage('2026-11-01', '2026-11-30');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://pixelforge.promptedit.dev/v1/usage?start_date=2026-11-01&end_date=2026-11-30');
});
