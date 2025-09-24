<?php
declare(strict_types=1);

namespace solu1TaxJar\Core\Content\Extension;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCustomFieldsTrait;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class TaxExtensionEntity extends Entity
{
    use EntityIdTrait;
    use EntityCustomFieldsTrait;

    protected $providerId;
    protected $taxId;

    public function getProviderId(): string
    {
        return $this->providerId;
    }

    public function setProviderId($providerId)
    {
        $this->providerId = $providerId;
    }

    public function getTaxId(): string
    {
        return $this->taxId;
    }

    public function setTaxId($taxId)
    {
        $this->taxId = $taxId;
    }
}
