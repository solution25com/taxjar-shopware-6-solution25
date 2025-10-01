<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Api\Controller;

use Doctrine\DBAL\Connection;
use Shopware\Administration\Framework\Twig\ViteFileAccessorDecorator;
use Shopware\Core\Content\Flow\Api\FlowActionCollector;
use Shopware\Core\Framework\Api\ApiDefinition\DefinitionService;
use Shopware\Core\Framework\Api\ApiDefinition\Generator\EntitySchemaGenerator;
use Shopware\Core\Framework\Api\ApiDefinition\Generator\OpenApi3Generator;
use Shopware\Core\Framework\Api\ApiException;
use Shopware\Core\Framework\Api\Route\ApiRouteInfoResolver;
use Shopware\Core\Framework\Api\Route\RouteInfo;
use Shopware\Core\Framework\App\Exception\AppUrlChangeDetectedException;
use Shopware\Core\Framework\App\ShopId\ShopIdProvider;
use Shopware\Core\Framework\Bundle;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\BusinessEventCollector;
use Shopware\Core\Framework\Increment\Exception\IncrementGatewayNotFoundException;
use Shopware\Core\Framework\Increment\IncrementGatewayRegistry;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\MessageQueue\Stats\StatsService;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\Framework\Store\InAppPurchase;
use Shopware\Core\Kernel;
use Shopware\Core\Maintenance\Staging\Event\SetupStagingEvent;
use Shopware\Core\Maintenance\System\Service\AppUrlVerifier;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
#[Package('framework')]
class InfoController extends AbstractController
{
    /**
     * @internal
     */
    public function __construct(
        private readonly DefinitionService $definitionService,
        private readonly ParameterBagInterface $params,
        private readonly Kernel $kernel,
        private readonly BusinessEventCollector $eventCollector,
        private readonly IncrementGatewayRegistry $incrementGatewayRegistry,
        private readonly Connection $connection,
        private readonly AppUrlVerifier $appUrlVerifier,
        private readonly RouterInterface $router,
        private readonly FlowActionCollector $flowActionCollector,
        private readonly SystemConfigService $systemConfigService,
        private readonly ApiRouteInfoResolver $apiRouteInfoResolver,
        private readonly InAppPurchase $inAppPurchase,
        private readonly ?ViteFileAccessorDecorator $viteFileAccessorDecorator,
        private readonly Filesystem $filesystem,
        private readonly ShopIdProvider $shopIdProvider,
        private readonly StatsService $messageStatsService,
    ) {
    }

    #[Route(
        path: '/api/_info/openapi3.json',
        name: 'api.info.openapi3',
        defaults: ['auth_required' => '%shopware.api.api_browser.auth_required_str%'],
        methods: ['GET']
    )]
    public function info(Request $request): JsonResponse
    {
        $type = $request->query->getAlpha('type', DefinitionService::TYPE_JSON_API);

        $apiType = $this->definitionService->toApiType($type);
        if ($apiType === null) {
            throw ApiException::invalidApiType($type);
        }

        $data = $this->definitionService->generate(OpenApi3Generator::FORMAT, DefinitionService::API, $apiType);

        return new JsonResponse($data);
    }

    #[Route(path: '/api/_info/queue.json', name: 'api.info.queue', methods: ['GET'])]
    public function queue(): JsonResponse
    {
        try {
            $gateway = $this->incrementGatewayRegistry->get(IncrementGatewayRegistry::MESSAGE_QUEUE_POOL);
        } catch (IncrementGatewayNotFoundException) {
            // In case message_queue pool is disabled
            return new JsonResponse([]);
        }

        // Fetch unlimited message_queue_stats
        $entries = $gateway->list('message_queue_stats', -1);

        return new JsonResponse(array_map(static fn (array $entry) => [
            'name' => $entry['key'],
            'size' => (int) $entry['count'],
        ], array_values($entries)));
    }

    #[Route(path: '/api/_info/message-stats.json', name: 'api.info.message-stats', methods: ['GET'])]
    public function messageStats(): JsonResponse
    {
        $response = new JsonResponse();
        $response->setEncodingOptions($response->getEncodingOptions() | \JSON_PRESERVE_ZERO_FRACTION);
        $response->setData($this->messageStatsService->getStats());

        return $response;
    }

    #[Route(
        path: '/api/_info/open-api-schema.json',
        name: 'api.info.open-api-schema',
        defaults: ['auth_required' => '%shopware.api.api_browser.auth_required_str%'],
        methods: ['GET']
    )]
    public function openApiSchema(): JsonResponse
    {
        $data = $this->definitionService->getSchema(OpenApi3Generator::FORMAT);

        return new JsonResponse($data);
    }

    #[Route(path: '/api/_info/entity-schema.json', name: 'api.info.entity-schema', methods: ['GET'])]
    public function entitySchema(): JsonResponse
    {
        $data = $this->definitionService->getSchema(EntitySchemaGenerator::FORMAT);

        return new JsonResponse($data);
    }

    #[Route(path: '/api/_info/events.json', name: 'api.info.business-events', methods: ['GET'])]
    public function businessEvents(Context $context): JsonResponse
    {
        $events = $this->eventCollector->collect($context);

        return new JsonResponse($events);
    }

    #[Route(
        path: '/api/_info/stoplightio.html',
        name: 'api.info.stoplightio',
        defaults: ['auth_required' => '%shopware.api.api_browser.auth_required_str%'],
        methods: ['GET']
    )]
    public function stoplightIoInfoHtml(Request $request): Response
    {
        $nonce = $request->attributes->get(PlatformRequest::ATTRIBUTE_CSP_NONCE);
        $apiType = $request->query->getAlpha('type', DefinitionService::TYPE_JSON);
        $response = $this->render(
            '@Framework/stoplightio.html.twig',
            [
                'schemaUrl' => 'api.info.openapi3',
                'cspNonce' => $nonce,
                'apiType' => $apiType,
            ]
        );

        $cspTemplate = trim($this->params->get('shopware.security.csp_templates')['administration'] ?? '');
        if ($cspTemplate !== '') {
            $csp = str_replace(['%nonce%', "\n", "\r"], [$nonce, ' ', ' '], $cspTemplate);
            $response->headers->set('Content-Security-Policy', $csp);
        }

        return $response;
    }

    #[Route(path: '/api/_info/config', name: 'api.info.config', methods: ['GET'])]
    public function config(Context $context, Request $request): JsonResponse
    {
        return new JsonResponse([
            'version' => $this->getShopwareVersion(),
            'shopId' => $this->getShopId(),
            'versionRevision' => $this->params->get('kernel.shopware_version_revision'),
            'adminWorker' => [
                'enableAdminWorker' => $this->params->get('shopware.admin_worker.enable_admin_worker'),
                'enableQueueStatsWorker' => $this->params->get('shopware.admin_worker.enable_queue_stats_worker'),
                'enableNotificationWorker' => $this->params->get('shopware.admin_worker.enable_notification_worker'),
                'transports' => $this->params->get('shopware.admin_worker.transports'),
            ],
            'bundles' => $this->getBundles(),
            'settings' => [
                'enableUrlFeature' => $this->params->get('shopware.media.enable_url_upload_feature'),
                'appUrlReachable' => $this->appUrlVerifier->isAppUrlReachable($request),
                'appsRequireAppUrl' => $this->appUrlVerifier->hasAppsThatNeedAppUrl(),
                'private_allowed_extensions' => $this->params->get('shopware.filesystem.private_allowed_extensions'),
                'enableHtmlSanitizer' => $this->params->get('shopware.html_sanitizer.enabled'),
                'enableStagingMode' => $this->params->get('shopware.staging.administration.show_banner') && $this->systemConfigService->getBool(SetupStagingEvent::CONFIG_FLAG),
                'disableExtensionManagement' => !$this->params->get('shopware.deployment.runtime_extension_management'),
            ],
            'inAppPurchases' => $this->inAppPurchase->all(),
        ]);
    }

    #[Route(path: '/api/_info/version', name: 'api.info.shopware.version', methods: ['GET'])]
    #[Route(path: '/api/v1/_info/version', name: 'api.info.shopware.version_old_version', methods: ['GET'])]
    public function infoShopwareVersion(): JsonResponse
    {
        return new JsonResponse([
            'version' => $this->getShopwareVersion(),
        ]);
    }

    #[Route(path: '/api/_info/flow-actions.json', name: 'api.info.actions', methods: ['GET'])]
    public function flowActions(Context $context): JsonResponse
    {
        return new JsonResponse($this->flowActionCollector->collect($context));
    }

    #[Route(
        path: '/api/_info/routes',
        name: 'api.info.routes',
        defaults: ['auth_required' => '%shopware.api.api_browser.auth_required_str%'],
        methods: ['GET']
    )]
    public function getRoutes(): JsonResponse
    {
        $endpoints = array_map(
            static fn (RouteInfo $endpoint) => ['path' => $endpoint->path, 'methods' => $endpoint->methods],
            $this->apiRouteInfoResolver->getApiRoutes(ApiRouteScope::ID)
        );

        return new JsonResponse(['endpoints' => $endpoints]);
    }

    /**
     * @return array<string, array{
     *     type: 'plugin',
     *     css: list<string>,
     *     js: list<string>,
     *     baseUrl: ?string
     * }|array{
     *     type: 'app',
     *     name: string,
     *     active: bool,
     *     integrationId: string,
     *     baseUrl: string,
     *     version: string,
     *     permissions: array<string, list<string>>
     * }>
     */
    private function getBundles(): array
    {
        $assets = [];

        foreach ($this->kernel->getBundles() as $bundle) {
            if (!$bundle instanceof Bundle) {
                continue;
            }

            if (!$this->viteFileAccessorDecorator) {
                // Admin bundle is not there, admin assets are not available
                continue;
            }

            $viteEntryPoints = $this->viteFileAccessorDecorator->getBundleData($bundle);

            $technicalBundleName = $this->getTechnicalBundleName($bundle);
            $styles = $viteEntryPoints['entryPoints'][$technicalBundleName]['css'] ?? [];
            $scripts = $viteEntryPoints['entryPoints'][$technicalBundleName]['js'] ?? [];
            $baseUrl = $this->getBaseUrl($bundle);

            if (empty($styles) && empty($scripts) && $baseUrl === null) {
                continue;
            }

            $assets[$bundle->getName()] = [
                'css' => $styles,
                'js' => $scripts,
                'baseUrl' => $baseUrl,
                'type' => 'plugin',
            ];
        }

        foreach ($this->getActiveApps() as $app) {
            $assets[$app['name']] = [
                'active' => (bool) $app['active'],
                'integrationId' => $app['integrationId'],
                'type' => 'app',
                'baseUrl' => $app['baseUrl'],
                'permissions' => $app['privileges'],
                'version' => $app['version'],
                'name' => $app['name'],
            ];
        }

        return $assets;
    }

    private function getBaseUrl(Bundle $bundle): ?string
    {
        if ($bundle->getAdminBaseUrl()) {
            return $bundle->getAdminBaseUrl();
        }

        if (!$this->filesystem->exists($bundle->getPath() . '/Resources/public/meteor-app/index.html')) {
            return null;
        }

        // exception is possible as the administration is an optional dependency
        try {
            return $this->router->generate(
                'administration.plugin.index',
                [
                    /**
                     * Adopted from symfony, as they also strip the bundle suffix:
                     * https://github.com/symfony/symfony/blob/7.2/src/Symfony/Bundle/FrameworkBundle/Command/AssetsInstallCommand.php#L128
                     *
                     * @see Plugin\Util\AssetService::getTargetDirectory
                     */
                    'pluginName' => preg_replace('/bundle$/', '', mb_strtolower($bundle->getName())),
                ],
                UrlGeneratorInterface::ABSOLUTE_URL
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<array{name: string, active: int, integrationId: string, baseUrl: string, version: string, privileges: array<string, list<string>>}>
     */
    private function getActiveApps(): array
    {
        /** @var list<array{name: string, active: int, integrationId: string, baseUrl: string, version: string, privileges: ?string}> $apps */
        $apps = $this->connection->fetchAllAssociative('SELECT
    app.name,
    app.active,
    LOWER(HEX(app.integration_id)) as integrationId,
    app.base_app_url as baseUrl,
    app.version,
    ar.privileges as privileges
FROM app
LEFT JOIN acl_role ar on app.acl_role_id = ar.id
WHERE app.active = 1 AND app.base_app_url is not null');

        return array_map(static function (array $item) {
            $privileges = $item['privileges'] ? json_decode((string) $item['privileges'], true, 512, \JSON_THROW_ON_ERROR) : [];

            $item['privileges'] = [];

            foreach ($privileges as $privilege) {
                if (substr_count($privilege, ':') !== 1) {
                    $item['privileges']['additional'][] = $privilege;

                    continue;
                }

                [$entity, $key] = \explode(':', $privilege);
                $item['privileges'][$key][] = $entity;
            }

            return $item;
        }, $apps);
    }

    private function getShopwareVersion(): string
    {
        $shopwareVersion = $this->params->get('kernel.shopware_version');
        if ($shopwareVersion === Kernel::SHOPWARE_FALLBACK_VERSION) {
            $shopwareVersion = str_replace('.9999999-dev', '.9999999.9999999-dev', $shopwareVersion);
        }

        return $shopwareVersion;
    }

    private function getTechnicalBundleName(Bundle $bundle): string
    {
        return str_replace('_', '-', $bundle->getContainerPrefix());
    }

    private function getShopId(): string
    {
        try {
            return $this->shopIdProvider->getShopId();
        } catch (AppUrlChangeDetectedException $e) {
            return $e->getShopId()->id;
        }
    }
}
