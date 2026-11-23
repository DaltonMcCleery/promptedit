<?php

use App\Pixelforge\WebhookSignature;

const SIGNING_SECRET = 'whsec_test';
const SIGNED_AT = 1_762_265_009;

it('accepts a signature made with the signing secret', function () {
    $payload = '{"id":"evt_1"}';

    expect(WebhookSignature::verify($payload, WebhookSignature::sign($payload, SIGNING_SECRET, SIGNED_AT), SIGNING_SECRET, SIGNED_AT + 10))->toBeTrue();
});

it('matches the documented header format', function () {
    $payload = '{"id":"evt_1"}';
    $signature = hash_hmac('sha256', SIGNED_AT.'.'.$payload, SIGNING_SECRET);

    expect(WebhookSignature::verify($payload, 't='.SIGNED_AT.',v1='.$signature, SIGNING_SECRET, SIGNED_AT))->toBeTrue();
});

it('rejects a signature made with another secret', function () {
    $payload = '{"id":"evt_1"}';

    expect(WebhookSignature::verify($payload, WebhookSignature::sign($payload, 'whsec_other', SIGNED_AT), SIGNING_SECRET, SIGNED_AT))->toBeFalse();
});

it('rejects a body changed after signing', function () {
    $header = WebhookSignature::sign('{"id":"evt_1"}', SIGNING_SECRET, SIGNED_AT);

    expect(WebhookSignature::verify('{"id":"evt_2"}', $header, SIGNING_SECRET, SIGNED_AT))->toBeFalse();
});

it('rejects a missing or malformed header', function (?string $header) {
    expect(WebhookSignature::verify('{}', $header, SIGNING_SECRET, SIGNED_AT))->toBeFalse();
})->with([
    'missing' => [null],
    'empty' => [''],
    'no v1' => ['t='.SIGNED_AT],
    'no timestamp' => ['v1=abc'],
    'garbage' => ['nonsense'],
]);

it('rejects a timestamp more than 300 seconds from now', function (int $now) {
    $payload = '{"id":"evt_1"}';

    expect(WebhookSignature::verify($payload, WebhookSignature::sign($payload, SIGNING_SECRET, SIGNED_AT), SIGNING_SECRET, $now))->toBeFalse();
})->with([
    'too old' => [SIGNED_AT + 301],
    'in the future' => [SIGNED_AT - 301],
]);

it('accepts a timestamp exactly 300 seconds old', function () {
    $payload = '{"id":"evt_1"}';

    expect(WebhookSignature::verify($payload, WebhookSignature::sign($payload, SIGNING_SECRET, SIGNED_AT), SIGNING_SECRET, SIGNED_AT + 300))->toBeTrue();
});

it('rejects everything when no signing secret is configured', function () {
    $payload = '{"id":"evt_1"}';

    expect(WebhookSignature::verify($payload, WebhookSignature::sign($payload, '', SIGNED_AT), '', SIGNED_AT))->toBeFalse();
});
