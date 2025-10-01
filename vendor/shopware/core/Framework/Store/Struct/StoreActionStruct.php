<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Store\Struct;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Struct\Struct;

/**
 * @codeCoverageIgnore
 */
#[Package('checkout')]
class StoreActionStruct extends Struct
{
    protected string $label;

    protected string $externalLink;

    public function getApiAlias(): string
    {
        return 'store_action';
    }
}
