<?php
declare(strict_types=1);

namespace solu1TaxJar\Core\Content\Extension;

use Shopware\Core\System\Tax\TaxDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class TaxExtension extends EntityExtension
{
    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(
            new OneToOneAssociationField(
                'taxExtension',
                'id',
                'tax_id',
                TaxExtensionDefinition::class,
                true
            )
        );
    }

    public function getDefinitionClass(): string
    {
        return TaxDefinition::class;
    }

    public function getEntityName(): string
    {
        return TaxDefinition::ENTITY_NAME;
    }
}