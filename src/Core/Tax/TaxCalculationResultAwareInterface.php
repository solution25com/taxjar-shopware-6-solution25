<?php

declare(strict_types=1);

namespace solu1TaxJar\Core\Tax;

interface TaxCalculationResultAwareInterface
{
    public function getLastCalculation(): ?array;
}
