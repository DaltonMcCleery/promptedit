<?php

namespace App\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A thin wrapper over the Pixelforge API.
 *
 * It never retries on its own: SubmitGeneration owns the retry policy, so
 * every retry is visible in one place and reuses the same idempotency key.
 */
class PixelforgeClient
{
    /**
     * The only body keys Pixelforge accepts; anything else is a 422.
     */
    private const CREATE_PARAMETERS = ['prompt', 'size', 'quality', 'reference_images'];

    /**
     * Ask Pixelforge to create a generation and classify the answer.
     *
     * @param  array<string, mixed>  $parameters  prompt, size, quality and reference_images.
     */
    public function createGeneration(array $parameters, string $idempotencyKey, string $localGenerationId): CreateResult
    {
        $body = Arr::only($parameters, self::CREATE_PARAMETERS);
        $body['metadata'] = ['app_generation_id' => $localGenerationId];

        try {
            $response = $this->request()
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->post('generations', $body);
        } catch (ConnectionException $exception) {
            Log::warning('Pixelforge create request did not get a response.', [
                'generation_id' => $localGenerationId,
                'error' => $exception->getMessage(),
            ]);

            return new CreateResult(CreateOutcome::Unknown, errorMessage: $exception->getMessage());
        }

        if ($response->successful()) {
            return CreateResult::accepted($response->json());
        }

        $this->logError('Pixelforge rejected a create request.', $response, $localGenerationId);

        return new CreateResult(
            outcome: $this->classify($response),
            errorCode: $response->json('error.code'),
            errorMessage: $response->json('error.message'),
            retryAfterSeconds: $this->retryAfter($response),
            httpStatus: $response->status(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getGeneration(string $pixelforgeId, string $localGenerationId): array
    {
        return $this->request()
            ->get("generations/{$pixelforgeId}")
            ->throw(fn (Response $response) => $this->logError('Pixelforge generation lookup failed.', $response, $localGenerationId))
            ->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function pricing(): array
    {
        return $this->request()
            ->get('pricing')
            ->throw(fn (Response $response) => $this->logError('Pixelforge pricing request failed.', $response))
            ->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->request()
            ->get('usage', ['start_date' => $startDate, 'end_date' => $endDate])
            ->throw(fn (Response $response) => $this->logError('Pixelforge usage request failed.', $response))
            ->json();
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(config('services.pixelforge.base_url'))
            ->withToken((string) config('services.pixelforge.api_key'))
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(15);
    }

    private function classify(Response $response): CreateOutcome
    {
        $code = $response->json('error.code');

        return match (true) {
            $response->status() === 503 => CreateOutcome::Retryable,
            $response->status() === 429 && $code === 'concurrency_limit_exceeded' => CreateOutcome::NoSlot,
            $response->status() === 429 && $code === 'daily_limit_exceeded' => CreateOutcome::Final,
            $response->status() === 429 => CreateOutcome::Retryable,
            $response->serverError() => CreateOutcome::Unknown,
            default => CreateOutcome::Final,
        };
    }

    private function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return is_numeric($header) ? (int) $header : null;
    }

    /**
     * Log the status and error code only: never the request, whose headers carry the API key.
     */
    private function logError(string $message, Response $response, ?string $localGenerationId = null): void
    {
        Log::warning($message, array_filter([
            'http_status' => $response->status(),
            'code' => $response->json('error.code'),
            'generation_id' => $localGenerationId,
        ], fn (mixed $value) => $value !== null));
    }
}
