<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Plugin\Aggregate\PluginTranslation;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCustomFieldsTrait;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Shopware\Core\Framework\DataAbstractionLayer\TranslationEntity;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Plugin\PluginEntity;

#[Package('framework')]
class PluginTranslationEntity extends TranslationEntity
{
    use EntityCustomFieldsTrait;
    use EntityIdTrait;

    protected string $pluginId;

    protected ?string $label = null;

    protected ?string $description = null;

    protected ?string $manufacturerLink = null;

    protected ?string $supportLink = null;

    protected ?PluginEntity $plugin = null;

    public function getPluginId(): string
    {
        return $this->pluginId;
    }

    public function setPluginId(string $pluginId): void
    {
        $this->pluginId = $pluginId;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    public function getManufacturerLink(): ?string
    {
        return $this->manufacturerLink;
    }

    public function setManufacturerLink(string $manufacturerLink): void
    {
        $this->manufacturerLink = $manufacturerLink;
    }

    public function getSupportLink(): ?string
    {
        return $this->supportLink;
    }

    public function setSupportLink(string $supportLink): void
    {
        $this->supportLink = $supportLink;
    }

    public function getPlugin(): ?PluginEntity
    {
        return $this->plugin;
    }

    public function setPlugin(PluginEntity $plugin): void
    {
        $this->plugin = $plugin;
    }
}
