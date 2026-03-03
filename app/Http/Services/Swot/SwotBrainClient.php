<?php

namespace App\Http\Services\Swot;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SwotBrainClient
{
    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function ask(array $body): array
    {
        $baseUri = rtrim((string) config('services.brain.base_uri', ''), '/');
        if ($baseUri === '') {
            throw new RuntimeException('BRAIN base URI is not configured.');
        }

        // SWOT generation often needs multi-tool aggregation; avoid premature timeout.
        $timeout = max(300, (int) config('services.brain.timeout', 300));

        $request = Http::acceptJson()
            ->timeout($timeout)
            ->baseUrl($baseUri.'/');

        $apiKey = trim((string) config('services.brain.api_key', ''));
        if ($apiKey === '') {
            throw new RuntimeException('BRAIN_API_KEY is not configured.');
        }
        $request = $request->withToken($apiKey);

        $response = $request->post('v1/ask', $body);

        if ($response->failed()) {
            throw new RuntimeException($this->buildFailureMessage($response));
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new RuntimeException('Invalid Brain response payload.');
        }

        return $data;
    }

    private function buildFailureMessage(Response $response): string
    {
        $body = $response->json();
        if (is_array($body) && isset($body['message']) && is_string($body['message'])) {
            return $body['message'];
        }

        return sprintf('Brain request failed with status %d.', $response->status());
    }
}
