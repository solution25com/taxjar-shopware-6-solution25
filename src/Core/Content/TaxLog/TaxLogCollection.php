<?php
declare(strict_types=1);

namespace solu1TaxJar\Core\Content\TaxLog;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * @extends EntityCollection<TaxLogEntity>
 */
class TaxLogCollection extends EntityCollection
{
    public function delete(array $deletable, Context $context): void
    {

    }

    public function search(Criteria $criteria, Context $context)
    {
    }

    protected function getExpectedClass(): string
    {
        return TaxLogEntity::class;
    }
}