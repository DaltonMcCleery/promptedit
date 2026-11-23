<?php

use App\Generations\FailureReason;

it('explains each failure in plain language', function (string $code, string $reason) {
    expect(FailureReason::for($code))->toBe($reason);
})->with([
    ['generation_failed', "Pixelforge couldn't produce an image for this prompt."],
    ['internal_error', 'Something went wrong at Pixelforge. Trying again usually works.'],
    ['content_policy_violation', 'Pixelforge blocked this prompt.'],
    ['daily_limit_exceeded', "Pixelforge's daily generation limit was reached. Try again after midnight UTC."],
    ['overloaded', 'Pixelforge stayed overloaded after 5 tries.'],
    ['rate_limit_exceeded', 'Too many requests to Pixelforge, even after 5 tries.'],
    ['concurrency_timeout', 'No free generation slot opened within 10 minutes.'],
]);

it('names the code for rejections that point to a bug or bad configuration', function (string $code) {
    expect(FailureReason::for($code))->toBe("Pixelforge rejected the request ({$code}).");
})->with(['validation_failed', 'idempotency_key_reused', 'invalid_api_key']);

it('still gives a reason when there is no code', function () {
    expect(FailureReason::for(null))->toBe('Pixelforge rejected the request (unknown error).');
});
