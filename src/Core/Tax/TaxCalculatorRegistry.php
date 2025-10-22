<?php

namespace solu1TaxJar\Core\Tax;

class TaxCalculatorRegistry
{
    /** @var iterable<TaxCalculatorInterface> */
    private iterable $calculators;

    /**
     * @param iterable<TaxCalculatorInterface> $calculators
     */
    public function __construct(iterable $calculators)
    {
        $this->calculators = $calculators;
    }

    /**
     * @param class-string<TaxCalculatorInterface> $baseClass
     */
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