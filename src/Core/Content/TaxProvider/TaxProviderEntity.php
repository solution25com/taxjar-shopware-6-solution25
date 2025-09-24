<?php
declare(strict_types=1);

namespace solu1TaxJar\Core\Content\TaxProvider;

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
