<?php
declare(strict_types=1);

namespace solu1TaxJar\Core\Content\Extension;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCustomFieldsTrait;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Shopware\Core\System\Tax\TaxEntity;
use solu1TaxJar\Core\Content\TaxProvider\TaxProviderEntity;

class TaxExtensionEntity extends Entity
{
    use EntityIdTrait;
    use EntityCustomFieldsTrait;

    protected ?string $providerId = null;
    protected ?string $taxId = null;

    protected ?TaxEntity $tax = null;
    protected ?TaxProviderEntity $taxProvider = null;

    public function getProviderId(): ?string
    {
        return $this->providerId;
    }

    public function setProviderId(?string $providerId): void
    {
        $this->providerId = $providerId;
    }

    public function getTaxId(): ?string
    {
        return $this->taxId;
    }

    public function setTaxId(?string $taxId): void
    {
        $this->taxId = $taxId;
    }
    public function getTax(): ?TaxEntity
    {
        return $this->tax;
    }

    public function setTax(?TaxEntity $tax): void
    {
        $this->tax = $tax;
    }

    public function getTaxProvider(): ?TaxProviderEntity
    {
        return $this->taxProvider;
    }

    public function setTaxProvider(?TaxProviderEntity $taxProvider): void
    {
        $this->taxProvider = $taxProvider;
    }
}
