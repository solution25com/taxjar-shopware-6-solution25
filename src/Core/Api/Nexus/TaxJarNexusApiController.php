<?php declare(strict_types=1);

namespace solu1TaxJar\Core\Api\Nexus;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request as GRequest;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ["_routeScope" => ["api"]])]
class TaxJarNexusApiController extends AbstractController
{
  private SystemConfigService $systemConfigService;
  public const LIVE_API_URL = 'https://api.taxjar.com/v2';
  public const SANDBOX_API_URL = 'https://api.sandbox.taxjar.com/v2';

  public function __construct(SystemConfigService $systemConfigService)
  {
    $this->systemConfigService = $systemConfigService;
  }

  #[Route(
    path: '/api/nexus/states',
    name: 'api.nexus.states',
    methods: ['GET']
  )]
  public function getStates(): JsonResponse
  {
    $method = 'GET';
    $apiEndpointUrl = $this->_getApiEndPoint() . '/nexus/regions';

    try {
      $request = new GRequest($method, $apiEndpointUrl, $this->getHeaders());
      $client = new Client();

      try {
        $response = $client->send($request);
        $body = (string) $response->getBody();

        return new JsonResponse([
          'status' => $response->getStatusCode(),
          'data'   => json_decode($body, true),
        ], $response->getStatusCode());

      } catch (ClientException $e) {
        $response = $e->getResponse();
        $body = (string) $response->getBody();

        return new JsonResponse([
          'status' => $response->getStatusCode(),
          'error'  => json_decode($body, true) ?: $body,
        ], $response->getStatusCode());
      }

    } catch (\Throwable $e) {
      return new JsonResponse([
        'status'  => 500,
        'error'   => 'Unexpected error',
        'message' => $e->getMessage(),
      ], 500);
    }
  }


  protected function _taxJarApiToken()
  {
    if ($this->_isSandboxMode()) {
      return $this->systemConfigService->get('solu1TaxJar.setting.sandboxApiToken');
    }
    return $this->systemConfigService->get('solu1TaxJar.setting.liveApiToken');
  }
  protected function _isSandboxMode(): int
  {
    return (int)$this->systemConfigService->get('solu1TaxJar.setting.sandboxMode');
  }

  protected function _getApiEndPoint(): string
  {
    if ($this->_isSandboxMode()) {
      return self::SANDBOX_API_URL;
    }
    return self::LIVE_API_URL;
  }
  private function getHeaders(): array
  {
    return [
      'Content-Type' => 'application/json',
      'Authorization' => 'Bearer ' . $this->_taxJarApiToken(),
      "X-CSRF-Token" => $this->_taxJarApiToken()
    ];
  }
}
