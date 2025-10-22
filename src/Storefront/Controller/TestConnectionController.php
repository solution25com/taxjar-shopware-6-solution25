<?php declare(strict_types=1);

namespace solu1TaxJar\Storefront\Controller;

use Exception;
use GuzzleHttp\Exception\ClientException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request as GRequest;

#[Route(defaults: ['_routeScope' => ['api'], '_acl' => ['system.plugin_maintain']])]
class TestConnectionController extends AbstractController
{
    public const VERSION = '1.10.4';
    public const LIVE_API_URL = 'https://api.taxjar.com/v2';
    public const SANDBOX_API_URL = 'https://api.sandbox.taxjar.com/v2';

    #[Route(path: '/api/_action/tax-jar/test-connection', name: 'frontend.taxjar.test.connection', methods: ['POST'], defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false])]
    public function testConnection(Request $request): JsonResponse
    {
        /** @var array<string, mixed>|null $requestBodyContent */
        $requestBodyContent = json_decode($request->getContent(), true);
        $result = [];
        $token = (string) ($requestBodyContent['token'] ?? '');
        $isSandBoxMode = isset($requestBodyContent['sandbox']) ? (int) $requestBodyContent['sandbox'] : 0;

        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
            'X-CSRF-Token' => $token,
        ];

        $fromAddress = $this->getShippingOriginAddress($requestBodyContent ?? []);

        $orderDetail = [
            'to_country' => 'US',
            'to_zip'     => '53204',
            'to_state'   => 'WI',
            'to_city'    => 'Milwaukee',
            'to_street'  => '1139 N Jackson Street, APT 325',
            'amount'     => 16.5,
            'shipping'   => 1.5,
        ];

        /** @var array<string, mixed> $orderDetail */
        $orderDetail = array_merge($fromAddress, $orderDetail);

        // Ensure body is string (json_encode can return false) to satisfy PHPStan without altering flow.
        $body = (string) json_encode($orderDetail);

        $request = new GRequest(
            'POST',
            $this->_getApiEndPoint($isSandBoxMode !== 0) . '/taxes',
            $headers,
            $body
        );

        try {
            $restClient = new Client();
            $response = $restClient->send($request);
        } catch (ClientException $e) {
            $error = $e->getResponse();
            $responseBodyAsString = $error->getBody()->getContents();
            $result = json_decode($responseBodyAsString, true);
            return new JsonResponse($result);
        }

        try {
            $result = json_decode($response->getBody()->getContents(), true);
        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
        }

        return new JsonResponse($result);
    }

    /**
     * Get API End Point URL
     *
     * @param bool $sandBoxMode
     */
    private function _getApiEndPoint(bool $sandBoxMode = false): string
    {
        if ($sandBoxMode) {
            return self::SANDBOX_API_URL;
        }
        return self::LIVE_API_URL;
    }

    /**
     * @param array<string, mixed> $requestData
     * @return array{
     *     from_country: string,
     *     from_zip: string,
     *     from_state: string,
     *     from_city: string,
     *     from_street: string
     * }
     */
    private function getShippingOriginAddress(array $requestData): array
    {
        return [
            'from_country' => (string) ($requestData['from_country'] ?? ''),
            'from_zip'     => (string) ($requestData['from_zip'] ?? ''),
            'from_state'   => (string) ($requestData['from_state'] ?? ''),
            'from_city'    => (string) ($requestData['from_city'] ?? ''),
            'from_street'  => (string) ($requestData['from_street'] ?? ''),
        ];
    }
}
