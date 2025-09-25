<?php


declare(strict_types=1);

namespace ITGCoTax\Core\Checkout\Cart\Extension;

use Shopware\Core\Framework\Struct\Struct;

class TaxExtensionStruct extends Struct
{
    private ?string $providerId = null;

    public function getProviderId(): ?string
    {
        return $this->providerId;
    }

    public function setProviderId(?string $providerId): void
    {
        $this->providerId = $providerId;
    }
}
