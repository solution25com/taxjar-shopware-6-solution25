<?php

namespace solu1TaxJar\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request as GRequest;

class ClientApiService
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $body
     * @return array{success: bool, body: string}
     */
    public function sendRequest(string $method, string $endpointUrl, array $headers, array $body): array
    {
        $client = new Client();

        // Ensure the body is always a string; json_encode can return false.
        $encodedBody = (string) json_encode($body);

        $request = new GRequest(
            $method,
            $endpointUrl,
            $headers,
            $encodedBody
        );

        try {
            $response = $client->send($request);
            return [
                'success' => true,
                'body' => $response->getBody()->getContents(),
            ];
        } catch (ClientException $e) {
            return [
                'success' => false,
                'body' => $e->getResponse()->getBody()->getContents(),
            ];
        }
    }
}
