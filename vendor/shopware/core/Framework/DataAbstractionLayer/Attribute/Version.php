<?php declare(strict_types=1);

namespace Shopware\Core\Framework\DataAbstractionLayer\Attribute;

use Shopware\Core\Framework\Log\Package;

#[Package('framework')]
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Version extends Field
{
    public const TYPE = 'version';

    public function __construct()
    {
        parent::__construct(type: self::TYPE, api: true);
    }
}
