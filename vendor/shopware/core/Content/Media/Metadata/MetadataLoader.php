<?php declare(strict_types=1);

namespace Shopware\Core\Content\Media\Metadata;

use Shopware\Core\Content\Media\File\MediaFile;
use Shopware\Core\Content\Media\MediaType\MediaType;
use Shopware\Core\Content\Media\Metadata\MetadataLoader\MetadataLoaderInterface;
use Shopware\Core\Framework\Log\Package;

#[Package('discovery')]
class MetadataLoader
{
    /**
     * @internal
     *
     * @param MetadataLoaderInterface[] $metadataLoader
     */
    public function __construct(private readonly iterable $metadataLoader)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadFromFile(MediaFile $mediaFile, MediaType $mediaType): ?array
    {
        $metaData = [];
        foreach ($this->metadataLoader as $loader) {
            if ($loader->supports($mediaType)) {
                $metaData = $loader->extractMetadata($mediaFile->getFileName());
                break;
            }
        }

        if ($mediaFile->getHash()) {
            $metaData['hash'] = $mediaFile->getHash();
        }

        return $metaData ?: null;
    }
}
