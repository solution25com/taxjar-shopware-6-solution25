<?php
/**
 * Copyright ©2021 ITG Commerce Ltd., Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace solu1TaxJar\Core\Content\Extension;
use Shopware\Core\System\Tax\TaxDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
class TaxExtension extends EntityExtension
{
    /**
     * @param FieldCollection $collection
     * @return void
     */
    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(
            new OneToOneAssociationField(
                'taxExtension',
                'id',
                'tax_id',
                TaxExtensionDefinition::class, true
            )
        );
    }

    public function getDefinitionClass(): string
    {
        return TaxDefinition::class;
    }
}
