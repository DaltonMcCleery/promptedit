<?php

namespace App\Http\Controllers;

use App\Generations\GenerationStatusUpdater;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Pixelforge\WebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Receives Pixelforge's webhooks. Delivery is at least once and in any order,
 * so each event is recorded under its unique id and applied in the same
 * transaction: a repeat inserts nothing, and a crash leaves nothing half-done.
 */
class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, GenerationStatusUpdater $updater): Response|JsonResponse
    {
        $payload = $request->getContent();

        if (! WebhookSignature::verify($payload, $request->header('Pixelforge-Signature'), (string) config('services.pixelforge.webhook_secret'), time())) {
            return response()->json(['error' => 'Invalid Pixelforge signature.'], 400);
        }

        $event = json_decode($payload, true);

        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null)) {
            return response()->json(['error' => 'Not a Pixelforge event.'], 400);
        }

        $outcome = DB::transaction(fn (): string => $this->record($event, $updater));

        return $outcome === 'applied' ? response()->noContent() : response()->json(['status' => $outcome]);
    }

    /**
     * @param  array<string, mixed>  $event
     * @return string applied, duplicate, ping or unknown_generation
     */
    private function record(array $event, GenerationStatusUpdater $updater): string
    {
        $inserted = WebhookEvent::query()->insertOrIgnore([
            'event_id' => $event['id'],
            'type' => $event['type'],
            'payload' => json_encode($event),
            'received_at' => now(),
        ]);

        if ($inserted === 0) {
            return 'duplicate';
        }

        if ($event['type'] === 'ping') {
            return 'ping';
        }

        $object = $event['data']['object'] ?? null;
        $generation = is_array($object) ? $this->findGeneration($object) : null;

        if ($generation === null) {
            Log::warning('Pixelforge webhook for a generation this app does not know.', [
                'event_id' => $event['id'],
                'type' => $event['type'],
                'pixelforge_id' => is_array($object) ? ($object['id'] ?? null) : null,
            ]);

            return 'unknown_generation';
        }

        $updater->apply($generation, $object, 'webhook');

        WebhookEvent::query()->where('event_id', $event['id'])->update(['generation_id' => $generation->id]);

        return 'applied';
    }

    /**
     * By Pixelforge's id, or by our own id in metadata when the webhook beat
     * the create response and the Pixelforge id isn't stored yet.
     *
     * @param  array<string, mixed>  $object
     */
    private function findGeneration(array $object): ?Generation
    {
        if (is_string($object['id'] ?? null)) {
            $generation = Generation::query()->where('pixelforge_id', $object['id'])->first();

            if ($generation !== null) {
                return $generation;
            }
        }

        $localId = $object['metadata']['app_generation_id'] ?? null;

        return is_string($localId) ? Generation::query()->find($localId) : null;
    }
}
