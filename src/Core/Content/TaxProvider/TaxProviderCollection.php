<?php
/**
 * Copyright ©2021 ITG Commerce Ltd., Inc. All rights reserved.
 * See COPYING.txt for license details.

 */
declare(strict_types=1);

namespace solu1TaxJar\Core\Content\TaxProvider;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

class TaxProviderCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return TaxProviderEntity::class;
    }
}
