<?php
declare(strict_types=1);

namespace solu1TaxJar\Core\Content\TaxLog;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

class TaxLogCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return TaxLogEntity::class;
    }
}
