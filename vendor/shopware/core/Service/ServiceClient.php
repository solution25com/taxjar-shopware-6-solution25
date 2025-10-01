<?php declare(strict_types=1);

namespace Shopware\Core\Service;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Service\ServiceRegistry\ServiceEntry;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * @internal
 */
#[Package('framework')]
class ServiceClient
{
    public function __construct(
        public readonly HttpClientInterface $client,
        private readonly string $shopwareVersion,
        private readonly ServiceEntry $entry,
        private readonly Filesystem $filesystem,
    ) {
    }

    public function downloadAppZipForVersion(string $zipUrl, string $destination): AppInfo
    {
        $response = $this->client->request('GET', $zipUrl, [
            'headers' => [
                'Accept' => 'application/zip',
            ],
        ]);

        $this->checkResponse($response);

        return $this->parseAppZipResponse($response, $destination);
    }

    public function latestAppInfo(): AppInfo
    {
        $response = $this->client->request('GET', $this->entry->appEndpoint, [
            'headers' => [
                'Accept' => 'application/json',
            ],
            'query' => [
                'shopwareVersion' => $this->shopwareVersion,
            ],
        ]);

        $this->checkResponse($response);

        return AppInfo::fromNameAndArray($this->entry->name, $response->toArray());
    }

    private function checkResponse(ResponseInterface $response): void
    {
        try {
            if ($response->getStatusCode() !== 200) {
                throw ServiceException::requestFailed($response);
            }
        } catch (TransportExceptionInterface $exception) {
            throw ServiceException::requestTransportError($exception);
        }
    }

    private function parseAppZipResponse(ResponseInterface $response, string $destination): AppInfo
    {
        $appInfo = AppInfo::fromNameAndArray($this->entry->name, [
            'app-version' => $response->getHeaders()['sw-app-version'][0] ?? null,
            'app-hash' => $response->getHeaders()['sw-app-hash'][0] ?? null,
            'app-revision' => $response->getHeaders()['sw-app-revision'][0] ?? null,
            'app-zip-url' => $response->getHeaders()['sw-app-zip-url'][0] ?? null,
        ]);

        foreach ($this->client->stream($response) as $chunk) {
            try {
                $this->filesystem->appendToFile($destination, $chunk->getContent());
            } catch (IOException) {
                throw ServiceException::cannotWriteAppToDestination($destination);
            }
        }

        return $appInfo;
    }
}
