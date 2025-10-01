<?php declare(strict_types=1);

namespace Shopware\Core\System\SalesChannel\Entity;

use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\Exception\SalesChannelRepositoryNotFoundException;
use Symfony\Component\DependencyInjection\ContainerInterface;

#[Package('discovery')]
class SalesChannelDefinitionInstanceRegistry extends DefinitionInstanceRegistry
{
    /**
     * @internal
     *
     * @param array<string, string|class-string<EntityDefinition>> $definitionMap
     * @param array<string, string> $repositoryMap
     */
    public function __construct(
        private readonly string $prefix,
        ContainerInterface $container,
        array $definitionMap,
        array $repositoryMap
    ) {
        parent::__construct($container, $definitionMap, $repositoryMap);
    }

    /**
     * @throws SalesChannelRepositoryNotFoundException
     *
     * @return SalesChannelRepository<covariant EntityCollection<covariant Entity>>
     */
    public function getSalesChannelRepository(string $entityName): SalesChannelRepository
    {
        $salesChannelRepositoryClass = $this->getSalesChannelRepositoryClassByEntityName($entityName);

        $salesChannelRepository = $this->container->get($salesChannelRepositoryClass);
        \assert($salesChannelRepository instanceof SalesChannelRepository);

        return $salesChannelRepository;
    }

    public function get(string $class): EntityDefinition
    {
        if (!str_starts_with($class, $this->prefix)) {
            $class = $this->prefix . $class;
        }

        return parent::get($class);
    }

    /**
     * @return array<SalesChannelDefinitionInterface>
     */
    public function getSalesChannelDefinitions(): array
    {
        return array_filter($this->getDefinitions(), static fn ($definition): bool => $definition instanceof SalesChannelDefinitionInterface);
    }

    public function register(EntityDefinition $definition, ?string $serviceId = null): void
    {
        if (!$serviceId) {
            $serviceId = $this->prefix . $definition::class;
        }

        parent::register($definition, $serviceId);
    }

    /**
     * @throws SalesChannelRepositoryNotFoundException
     */
    private function getSalesChannelRepositoryClassByEntityName(string $entityName): string
    {
        if (!isset($this->repositoryMap[$entityName])) {
            throw new SalesChannelRepositoryNotFoundException($entityName);
        }

        return $this->repositoryMap[$entityName];
    }
}
