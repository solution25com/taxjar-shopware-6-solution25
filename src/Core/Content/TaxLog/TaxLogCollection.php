<?php
/**
 * Copyright ©2021 ITG Commerce Ltd., Inc. All rights reserved.
 * See COPYING.txt for license details.

 */
declare(strict_types=1);

namespace ITGCoTax\Core\Content\TaxLog;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

class TaxLogCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return TaxLogEntity::class;
    }
}
