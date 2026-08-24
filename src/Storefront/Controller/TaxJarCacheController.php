<?php declare(strict_types=1);

namespace solu1TaxJar\Storefront\Controller;

use Psr\Cache\CacheItemPoolInterface;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class TaxJarCacheController
{
    private const CACHE_TAG_ALL = 'taxjar';
    private const CACHE_TAG_CUSTOMER_PREFIX = 'taxjar_customer_';

    public function __construct(private readonly CacheItemPoolInterface $cache)
    {
    }

    #[Route(
        path: '/api/_action/tax-jar/cache/clear',
        name: 'api.action.taxjar.cache.clear',
        defaults: ['_acl' => ['system.plugin_maintain']],
        methods: ['POST']
    )]
    public function clearAll(): JsonResponse
    {
        return new JsonResponse([
            'success' => $this->invalidateTags([self::CACHE_TAG_ALL]),
        ]);
    }

    #[Route(
        path: '/api/_action/tax-jar/cache/clear/{customerId}',
        name: 'api.action.taxjar.cache.clear_customer',
        defaults: ['_acl' => ['customer.editor']],
        methods: ['POST']
    )]
    public function clearCustomer(string $customerId): JsonResponse
    {
        if (!Uuid::isValid($customerId)) {
            return new JsonResponse(
                [
                    'success' => false,
                    'error' => 'Invalid customerId.',
                ],
                Response::HTTP_BAD_REQUEST
            );
        }

        return new JsonResponse([
            'success' => $this->invalidateTags([self::CACHE_TAG_CUSTOMER_PREFIX . $customerId]),
        ]);
    }

    private function invalidateTags(array $tags): bool
    {
        if (!$this->cache instanceof TagAwareAdapterInterface) {
            return false;
        }

        return $this->cache->invalidateTags($tags);
    }
}
