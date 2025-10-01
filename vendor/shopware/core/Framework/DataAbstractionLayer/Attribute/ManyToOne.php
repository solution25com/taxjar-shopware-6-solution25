<?php declare(strict_types=1);

namespace Shopware\Core\Framework\DataAbstractionLayer\Attribute;

use Shopware\Core\Framework\Log\Package;

#[Package('framework')]
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class ManyToOne extends Field
{
    public const TYPE = 'many-to-one';

    public function __construct(
        public string $entity,
        public OnDelete $onDelete = OnDelete::NO_ACTION,
        public string $ref = 'id',
        public bool|array $api = false,
        public ?string $column = null,
    ) {
        parent::__construct(type: self::TYPE, api: $api, column: $column);
    }
}
