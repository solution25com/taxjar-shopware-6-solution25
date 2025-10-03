<?php

namespace solu1TaxJar\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request as GRequest;

class ClientApiService
{
    public function sendRequest(string $method, string $endpointUrl, array $headers, array $body): array
    {
        $client = new Client();
        $request = new GRequest(
            $method,
            $endpointUrl,
            $headers,
            json_encode($body)
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

