<?php
/**
 * Copyright ©2021 ITG Commerce Ltd., Inc. All rights reserved.
 * See COPYING.txt for license details.

 */
declare(strict_types=1);

namespace ITGCoTax\Core\Content\TaxProvider;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCustomFieldsTrait;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class TaxProviderEntity extends Entity
{
    use EntityIdTrait;
    use EntityCustomFieldsTrait;

    protected $name;
    protected $baseClass;

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getBaseClass(): string
    {
        return (string) $this->baseClass;
    }

    public function setBaseClass(string $baseClass): void
    {
        $this->baseClass = $baseClass;
    }
}
