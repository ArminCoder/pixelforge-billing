<?php

namespace App\Services\Pixelforge;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The only class that talks HTTP to Pixelforge.
 * Every method returns the decoded JSON body or throws PixelforgeException.
 */
class PixelforgeClient
{
    /**
     * Ask for a new image.
     *
     * The idempotency key makes a retry safe: sending the same key again
     * returns the first image instead of creating (and billing) a second one.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed> the generation object
     */
    public function createGeneration(array $params, string $idempotencyKey): array
    {
        return $this->send(fn (PendingRequest $http) => $http
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('generations', $params));
    }

    /** @return array<string, mixed> the generation object */
    public function findGeneration(string $pixelforgeId): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get("generations/{$pixelforgeId}"));
    }

    /** @return array<string, mixed> rates and token numbers for estimates */
    public function pricing(): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('pricing'));
    }

    /**
     * @param  Closure(PendingRequest): Response  $call
     * @return array<string, mixed>
     */
    private function send(Closure $call): array
    {
        $http = Http::baseUrl(config('pixelforge.base_url'))
            ->withToken((string) config('pixelforge.api_key'))
            ->acceptJson()
            ->asJson()
            ->timeout(15);

        try {
            $response = $call($http);
        } catch (ConnectionException $e) {
            throw PixelforgeException::noAnswer($e->getMessage());
        }

        if ($response->failed()) {
            throw PixelforgeException::fromResponse($response);
        }

        return $response->json();
    }
}
