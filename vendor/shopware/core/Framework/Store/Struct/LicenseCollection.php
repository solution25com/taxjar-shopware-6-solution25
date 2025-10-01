<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Store\Struct;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Struct\Collection;

/**
 * @codeCoverageIgnore
 *
 * @extends Collection<LicenseStruct>
 */
#[Package('checkout')]
class LicenseCollection extends Collection
{
    protected int $total = 0;

    public function getTotal(): int
    {
        return $this->total;
    }

    public function setTotal(int $total): void
    {
        $this->total = $total;
    }

    protected function getExpectedClass(): ?string
    {
        return LicenseStruct::class;
    }
}
