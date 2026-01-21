<?php

declare(strict_types=1);

namespace solu1TaxJar\Core\Rule;

use Shopware\Core\Checkout\Cart\Rule\CartRuleScope;
use Shopware\Core\Framework\Rule\Rule;
use Shopware\Core\Framework\Rule\RuleScope;
use Shopware\Core\System\Country\CountryEntity;

class UsStateRule extends Rule
{
    /**
     * @var array<string>
     */
    protected array $stateCodes = [];

    protected bool $matchShipping = true;

    protected bool $matchBilling = true;

    public function getName(): string
    {
        return 'solu1_taxjar_us_state';
    }

    /**
     * @param array<string,mixed> $options
     */
    public function assign(array $options): void
    {
        parent::assign($options);

        if (\array_key_exists('stateCodes', $options)) {
            $this->stateCodes = \array_filter(\array_map('strtoupper', (array) $options['stateCodes']));
        }

        if (\array_key_exists('matchShipping', $options)) {
            $this->matchShipping = (bool) $options['matchShipping'];
        }

        if (\array_key_exists('matchBilling', $options)) {
            $this->matchBilling = (bool) $options['matchBilling'];
        }
    }

    public function match(RuleScope $scope): bool
    {
        if (!$scope instanceof CartRuleScope) {
            return false;
        }

        $context = $scope->getSalesChannelContext();
        $customer = $context->getCustomer();

        if (empty($this->stateCodes)) {
            return false;
        }

        $allowedStates = \array_map('strtoupper', $this->stateCodes);

        if ($this->matchShipping) {
            $shippingLocation = $context->getShippingLocation();
            $country = $shippingLocation->getCountry();
            $stateCode = $shippingLocation->getState() ? (string) $shippingLocation->getState()->getShortCode() : null;

            if ($this->isUsStateAllowed($country, $stateCode, $allowedStates)) {
                return true;
            }
        }

        if ($this->matchBilling && $customer !== null) {
            $billingAddress = $customer->getActiveBillingAddress();
            if ($billingAddress !== null) {
                /** @var CountryEntity|null $country */
                $country = $billingAddress->getCountry();
                $state = $billingAddress->getCountryState();
                $stateCode = $state ? (string) $state->getShortCode() : null;

                if ($this->isUsStateAllowed($country, $stateCode, $allowedStates)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string> $allowedStates
     */
    private function isUsStateAllowed(?CountryEntity $country, ?string $shortCode, array $allowedStates): bool
    {
        if ($country === null || $shortCode === null || $shortCode === '') {
            return false;
        }

        $countryIso = \strtoupper((string) $country->getIso());
        if ($countryIso !== 'US') {
            return false;
        }

        $parts = \explode('-', $shortCode);
        $stateCode = \strtoupper(\end($parts) ?: '');

        if ($stateCode === '') {
            return false;
        }

        return \in_array($stateCode, $allowedStates, true);
    }

    public function getConstraints(): array
    {
        return [
            'stateCodes' => ['type' => 'array'],
            'matchShipping' => ['type' => 'bool'],
            'matchBilling' => ['type' => 'bool'],
        ];
    }
}
