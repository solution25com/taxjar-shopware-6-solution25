<?php declare(strict_types=1);

namespace Shopware\Core\Content\Media\MediaType;

use Shopware\Core\Framework\Log\Package;

#[Package('discovery')]
class DocumentType extends MediaType
{
    protected string $name = 'DOCUMENT';
}
