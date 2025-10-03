<?php

namespace solu1TaxJar\Core\Tax;

class TaxCalculatorRegistry
{
    private iterable $calculators;

    public function __construct(iterable $calculators)
    {
        $this->calculators = $calculators;
    }

    public function getCalculatorFor(string $baseClass): ?TaxCalculatorInterface
    {
        foreach ($this->calculators as $calculator) {
            if ($calculator instanceof $baseClass) {
                return $calculator;
            }
        }

        return null;
    }
}
