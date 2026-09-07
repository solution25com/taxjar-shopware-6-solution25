<?php

namespace solu1TaxJar\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Psr7\Request as GRequest;
use Psr\Log\LoggerInterface;

class ClientApiService
{
    private const CONNECT_TIMEOUT = 5;
    private const REQUEST_TIMEOUT = 15;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function sendRequest(string $method, string $endpointUrl, array $headers, array $body): array
    {
        $client = new Client(['connect_timeout' => self::CONNECT_TIMEOUT, 'timeout' => self::REQUEST_TIMEOUT]);
        $request = new GRequest($method, $endpointUrl, $headers, json_encode($body));

        try {
            $response = $client->send($request);

            return ['success' => true, 'body' => $response->getBody()->getContents(), 'status' => $response->getStatusCode(), 'error' => null];
        } catch (\Throwable $e) {
            $hasResponse = $e instanceof BadResponseException;
            $status = $hasResponse ? $e->getResponse()->getStatusCode() : null;
            $responseBody = $hasResponse ? $e->getResponse()->getBody()->getContents() : (string) json_encode(['error' => $e->getMessage()]);

            $this->logger->error('TaxJar API request failed', [
                'method' => $method,
                'endpoint' => $endpointUrl,
                'httpStatus' => $status,
                'exceptionClass' => \get_class($e),
                'exceptionMessage' => $e->getMessage(),
                'responseBody' => substr($responseBody, 0, 2000),
            ]);

            return ['success' => false, 'body' => $responseBody, 'status' => $status, 'error' => $e->getMessage()];
        }
    }
}
