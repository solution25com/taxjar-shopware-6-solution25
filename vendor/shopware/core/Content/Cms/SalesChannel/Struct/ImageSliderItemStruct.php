<?php declare(strict_types=1);

namespace Shopware\Core\Content\Cms\SalesChannel\Struct;

use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Struct\Struct;

#[Package('discovery')]
class ImageSliderItemStruct extends Struct
{
    protected ?string $url = null;

    protected ?string $ariaLabel = null;

    protected ?bool $newTab = null;

    protected ?MediaEntity $media = null;

    public function getMedia(): ?MediaEntity
    {
        return $this->media;
    }

    public function setMedia(?MediaEntity $media): void
    {
        $this->media = $media;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): void
    {
        $this->url = $url;
    }

    public function getAriaLabel(): ?string
    {
        return $this->ariaLabel;
    }

    public function setAriaLabel(?string $ariaLabel): void
    {
        $this->ariaLabel = $ariaLabel;
    }

    public function getNewTab(): ?bool
    {
        return $this->newTab;
    }

    public function setNewTab(?bool $newTab): void
    {
        $this->newTab = $newTab;
    }

    public function getApiAlias(): string
    {
        return 'cms_image_slider_item';
    }
}
