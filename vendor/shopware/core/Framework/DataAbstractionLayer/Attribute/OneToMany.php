<?php declare(strict_types=1);

namespace Shopware\Core\Framework\DataAbstractionLayer\Attribute;

use Shopware\Core\Framework\Log\Package;

#[Package('framework')]
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class OneToMany extends Field
{
    public const TYPE = 'one-to-many';

    public function __construct(
        public string $entity,
        public string $ref,
        public OnDelete $onDelete = OnDelete::NO_ACTION,
        public bool|array $api = false
    ) {
        parent::__construct(type: self::TYPE, api: $api);
    }
}
