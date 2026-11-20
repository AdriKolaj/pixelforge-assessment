<?php

namespace App\Pixelforge;

use App\Pricing\PricingTable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PixelforgeClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly int $timeout = 15,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed> the generation object
     */
    public function createGeneration(array $params, string $idempotencyKey): array
    {
        return $this->send(fn () => $this->request()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('generations', $params));
    }

    /**
     * @return array<string, mixed> the generation object
     */
    public function getGeneration(string $id): array
    {
        return $this->send(fn () => $this->request()->get('generations/'.rawurlencode($id)));
    }

    /**
     * @return array<string, mixed>
     */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->send(fn () => $this->request()->get('usage', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]));
    }

    public function pricing(): PricingTable
    {
        // Rates can change, but not every few seconds; a short cache keeps the
        // form snappy without hardcoding anything.
        $pricing = Cache::remember('pixelforge.pricing', now()->addMinutes(10), fn () => $this->send(
            fn () => Http::baseUrl($this->baseUrl)->acceptJson()->timeout($this->timeout)->get('pricing'),
        ));

        return PricingTable::fromApi($pricing);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken((string) $this->apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout);
    }

    /**
     * @param  callable(): Response  $call
     * @return array<string, mixed>
     */
    private function send(callable $call): array
    {
        try {
            $response = $call();
        } catch (ConnectionException $e) {
            throw PixelforgeException::connection($e);
        }

        if ($response->failed()) {
            throw PixelforgeException::fromResponse($response);
        }

        return $response->json();
    }
}
