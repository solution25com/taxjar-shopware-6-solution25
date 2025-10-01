<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Store\Struct;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Struct\Struct;

/**
 * @codeCoverageIgnore
 */
#[Package('checkout')]
class StoreLicenseViolationTypeStruct extends Struct
{
    final public const LEVEL_VIOLATION = 'violation';
    final public const LEVEL_WARNING = 'warning';
    final public const LEVEL_INFO = 'info';

    protected string $name;

    protected string $label;

    protected string $level;

    public function getName(): string
    {
        return $this->name;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getApiAlias(): string
    {
        return 'store_license_violation_type';
    }
}
