<?php declare(strict_types=1);

namespace Shopware\Core\System\SalesChannel\Entity;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityLoadedEvent;
use Shopware\Core\Framework\Event\ShopwareSalesChannelEvent;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * @template TEntity of Entity
 *
 * @extends EntityLoadedEvent<TEntity>
 */
#[Package('discovery')]
class SalesChannelEntityLoadedEvent extends EntityLoadedEvent implements ShopwareSalesChannelEvent
{
    private readonly SalesChannelContext $salesChannelContext;

    /**
     * @param TEntity[] $entities
     */
    public function __construct(
        EntityDefinition $definition,
        array $entities,
        SalesChannelContext $context
    ) {
        parent::__construct($definition, $entities, $context->getContext());
        $this->salesChannelContext = $context;
    }

    public function getName(): string
    {
        return 'sales_channel.' . parent::getName();
    }

    public function getSalesChannelContext(): SalesChannelContext
    {
        return $this->salesChannelContext;
    }
}
