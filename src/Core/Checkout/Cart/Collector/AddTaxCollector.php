<?php

declare(strict_types=1);

namespace solu1TaxJar\Core\Checkout\Cart\Collector;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\Tax\TaxCollection;
use Shopware\Core\System\Tax\TaxEntity;
use solu1TaxJar\Core\Content\Extension\TaxExtensionEntity;
use solu1TaxJar\Core\Content\TaxProvider\TaxProviderEntity;
use solu1TaxJar\Core\Tax\TaxCalculatorInterface;
use solu1TaxJar\Core\Tax\TaxCalculatorRegistry;

class AddTaxCollector implements CartProcessorInterface
{
    /**
     * @var EntityRepository<TaxCollection>
     */
    private $taxRepository;

    /**
     * @var EntityRepository<EntityCollection<TaxProviderEntity>>
     */
    private $taxProviderRepository;

    private TaxCalculatorRegistry $taxCalculatorRegistry;

    /**
     * @param EntityRepository<TaxCollection> $taxRepository
     * @param EntityRepository<EntityCollection<TaxProviderEntity>> $taxProviderRepository
     */
    public function __construct(
        EntityRepository      $taxRepository,
        EntityRepository      $taxProviderRepository,
        TaxCalculatorRegistry $taxCalculatorRegistry
    ) {
        $this->taxRepository = $taxRepository;
        $this->taxProviderRepository = $taxProviderRepository;
        $this->taxCalculatorRegistry = $taxCalculatorRegistry;
    }

    /**
     * @param array<string, TaxEntity> $taxRules
     * @param array<string, TaxProviderEntity> $taxProviders
     * @return TaxCalculatorInterface|false
     */
    private function getTaxProviderClass(string $taxRuleId, array $taxRules, array $taxProviders): TaxCalculatorInterface|false
    {
        if (!$taxRuleId || !isset($taxRules[$taxRuleId])) {
            return false;
        }

        $taxRule = $taxRules[$taxRuleId];
        $extension = $taxRule->getExtension('taxExtension');

        if (!$extension instanceof TaxExtensionEntity || !$extension->getProviderId()) {
            return false;
        }

        $providerId = $extension->getProviderId();

        if (!isset($taxProviders[$providerId])) {
            return false;
        }

        $taxProvider = $taxProviders[$providerId];

        /** @phpstan-ignore-next-line  */
        if (!$taxProvider instanceof TaxProviderEntity || !$taxProvider->getBaseClass()) {
            return false;
        }

        /** @var class-string<TaxCalculatorInterface> $baseClass */
        $baseClass = $taxProvider->getBaseClass();

        return $this->taxCalculatorRegistry->getCalculatorFor($baseClass) ?? false;
    }

    public function process(CartDataCollection $data, Cart $original, Cart $toCalculate, SalesChannelContext $context, CartBehavior $behavior): void
    {
        $products = $toCalculate->getLineItems()->filterType(LineItem::PRODUCT_LINE_ITEM_TYPE);
        $taxProviderMapping = [];

        $taxIds = [];
        foreach ($products as $product) {
            $taxId = $product->getPayloadValue('taxId');
            if ($taxId) {
                $taxIds[] = $taxId;
                /** @phpstan-ignore-next-line   */
                $quantity = $product->getPrice()->getQuantity();
                /** @phpstan-ignore-next-line  */
                $unitPrice = $product->getPrice()->getUnitPrice();

                $taxProviderMapping[$taxId][] = [
                    'id' => $product->getReferencedId(),
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount' => 0,
                ];
            }
        }

        $taxCriteria = new Criteria(array_unique($taxIds));
        /** @var array<string, TaxEntity> $taxRules */
        $taxRules = $this->taxRepository->search($taxCriteria, $context->getContext())->getElements();

        $providerIds = [];
        foreach ($taxRules as $taxRule) {
            $extension = $taxRule->getExtension('taxExtension');
            if ($extension instanceof TaxExtensionEntity && $extension->getProviderId()) {
                $providerIds[] = $extension->getProviderId();
            }
        }

        /** @var array<string, TaxProviderEntity> $taxProviders */
        $taxProviders = [];
        if (!empty($providerIds)) {
            $providerCriteria = new Criteria(array_unique($providerIds));
            $taxProviders = $this->taxProviderRepository->search($providerCriteria, $context->getContext())->getElements();
        }

        foreach ($taxProviderMapping as $taxId => $requestDetails) {
            $taxProviderClass = $this->getTaxProviderClass($taxId, $taxRules, $taxProviders);
            if ($taxProviderClass) {
                $lineItems = $requestDetails;

                $lineItemsTax = $taxProviderClass->calculate($lineItems, $context, $original);

                /** @var array<string,mixed> $lineItemsTax */
                $this->addRateToCart($lineItemsTax, $toCalculate);

                if (!empty($lineItemsTax)) {
                    $shippingTaxFromServiceProvider = 0;
                    $methodTaxAmount = 0;

                    if (!empty($lineItemsTax['shippingTax'])) {
                        $shippingTaxFromServiceProvider = $lineItemsTax['shippingTax'];
                    }

                    /** @var CalculatedPrice $shippingCosts */
                    $shippingCosts = $original->getShippingCosts();
                    $shippingMethodCalculatedTax = $shippingCosts->getCalculatedTaxes();
                    foreach ($shippingMethodCalculatedTax as $methodCalculatedTax) {
                        $methodTaxAmount += $methodCalculatedTax->getTax();
                    }

                    foreach ($products as $product) {
                        $productId = $product->getReferencedId();
                        if (!empty($lineItemsTax[$productId])) {
                            /** @phpstan-ignore-next-line */
                            $calculatedTaxes = $product->getPrice()->getCalculatedTaxes();
                            foreach ($calculatedTaxes as $calculatedTax) {
                                $taxAmount = $lineItemsTax[$productId];

                                if ($shippingTaxFromServiceProvider) {
                                    $taxAmount += $shippingTaxFromServiceProvider - $methodTaxAmount;
                                    $shippingTaxFromServiceProvider = 0;
                                }

                                $calculatedTax->setTax($taxAmount);

                                if (isset($lineItemsTax['rate'])) {
                                    $calculatedTax->assign([
                                        'taxRate' => (float) number_format(
                                            (float) $lineItemsTax['rate'] * 100,
                                            2,
                                            '.',
                                            ''
                                        ),
                                    ]);
                                }
                            }
                        }
                    }

                    if (!empty($lineItemsTax['shippingTax'])) {
                        /** @var CalculatedPrice $shippingCosts2 */
                        $shippingCosts2 = $original->getShippingCosts();
                        $shippingCalculatedTaxes = $shippingCosts2->getCalculatedTaxes();
                        foreach ($shippingCalculatedTaxes as $shippingCalculatedTax) {
                            $shippingCalculatedTax->setTax((float) 0);
                        }
                    }
                }
            }
        }
    }

    /**
     * @param array<string,mixed> $lineItemsTax
     */
    private function addRateToCart(array $lineItemsTax, Cart $toCalculate): void
    {
        if (isset($lineItemsTax['rate'])) {
            $rate = $lineItemsTax['rate'];
            if ($rate) {
                foreach ($toCalculate->getLineItems() as $lineItem) {
                    $lineItem->setPayloadValue('taxJarRate', $rate);
                }
            }
        }
    }
}
