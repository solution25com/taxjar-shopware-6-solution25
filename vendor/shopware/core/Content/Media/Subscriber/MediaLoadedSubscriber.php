<?php declare(strict_types=1);

namespace Shopware\Core\Content\Media\Subscriber;

use Shopware\Core\Content\Media\Aggregate\MediaThumbnail\MediaThumbnailCollection;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityLoadedEvent;
use Shopware\Core\Framework\Log\Package;

#[Package('discovery')]
class MediaLoadedSubscriber
{
    /**
     * @param EntityLoadedEvent<MediaEntity> $event
     */
    public function unserialize(EntityLoadedEvent $event): void
    {
        foreach ($event->getEntities() as $media) {
            if ($media->getMediaTypeRaw()) {
                $media->setMediaType(unserialize($media->getMediaTypeRaw()));
            }

            if ($media->getThumbnails() !== null) {
                continue;
            }

            $thumbnails = match (true) {
                $media->getThumbnailsRo() !== null => unserialize($media->getThumbnailsRo()),
                default => new MediaThumbnailCollection(),
            };

            $media->setThumbnails($thumbnails);
        }
    }
}
