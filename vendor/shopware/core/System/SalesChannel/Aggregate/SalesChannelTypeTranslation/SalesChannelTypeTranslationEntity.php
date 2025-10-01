<?php declare(strict_types=1);

namespace Shopware\Core\System\SalesChannel\Aggregate\SalesChannelTypeTranslation;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCustomFieldsTrait;
use Shopware\Core\Framework\DataAbstractionLayer\TranslationEntity;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelType\SalesChannelTypeEntity;

#[Package('discovery')]
class SalesChannelTypeTranslationEntity extends TranslationEntity
{
    use EntityCustomFieldsTrait;

    protected string $salesChannelTypeId;

    protected ?string $name = null;

    protected ?string $manufacturer = null;

    protected ?string $description = null;

    protected ?string $descriptionLong = null;

    protected ?SalesChannelTypeEntity $salesChannelType = null;

    public function getSalesChannelTypeId(): string
    {
        return $this->salesChannelTypeId;
    }

    public function setSalesChannelTypeId(string $salesChannelTypeId): void
    {
        $this->salesChannelTypeId = $salesChannelTypeId;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    public function getManufacturer(): ?string
    {
        return $this->manufacturer;
    }

    public function setManufacturer(?string $manufacturer): void
    {
        $this->manufacturer = $manufacturer;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getDescriptionLong(): ?string
    {
        return $this->descriptionLong;
    }

    public function setDescriptionLong(?string $descriptionLong): void
    {
        $this->descriptionLong = $descriptionLong;
    }

    public function getSalesChannelType(): ?SalesChannelTypeEntity
    {
        return $this->salesChannelType;
    }

    public function setSalesChannelType(SalesChannelTypeEntity $salesChannelType): void
    {
        $this->salesChannelType = $salesChannelType;
    }
}
