<?php
declare(strict_types=1);

namespace solu1TaxJar\Core\Content\TaxProvider;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<TaxProviderEntity>
 */
class TaxProviderCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return TaxProviderEntity::class;
    }
}