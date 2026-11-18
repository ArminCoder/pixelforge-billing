<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\GenerationUpdater;
use App\Support\WebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, GenerationUpdater $updater): Response
    {
        // The signature covers the exact bytes sent, so read the raw body.
        $rawBody = $request->getContent();

        $signatureOk = WebhookSignature::isValid(
            $rawBody,
            $request->header('Pixelforge-Signature'),
            config('pixelforge.webhook_secret'),
            now()->getTimestamp(),
        );

        if (! $signatureOk) {
            return response('Invalid signature.', 400);
        }

        $event = json_decode($rawBody, true);

        // "ping" is the dashboard's test button. Signature was fine, nothing else to do.
        if (! is_array($event) || ($event['type'] ?? null) === 'ping') {
            return response()->noContent();
        }

        DB::transaction(function () use ($event, $rawBody, $updater) {
            // insertOrIgnore returns 0 when this event id is already stored.
            // That means Pixelforge sent it twice, and we stop here.
            $isNew = WebhookEvent::insertOrIgnore([
                'event_id' => $event['id'],
                'type' => $event['type'],
                'payload' => $rawBody,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (! $isNew) {
                return;
            }

            $remote = $event['data']['object'];

            // Match on our own uuid from the metadata. The webhook can arrive
            // before we have saved Pixelforge's "gen_..." id. A missing or
            // non-uuid value is not ours (Postgres would reject it in the query).
            $uuid = $remote['metadata']['generation_uuid'] ?? null;
            $generation = Str::isUuid($uuid) ? Generation::where('uuid', $uuid)->first() : null;

            if ($generation !== null) {
                $updater->apply($generation, $remote);
            }
        });

        // Any 2xx tells Pixelforge to stop retrying. If the transaction threw,
        // we never get here: Pixelforge gets a 500, and because the event row
        // was rolled back too, the retry is handled as new.
        return response()->noContent();
    }
}
