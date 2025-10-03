<?php

declare(strict_types=1);

namespace solu1TaxJar\Core\Checkout\Cart\Collector;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use solu1TaxJar\Core\Content\Extension\TaxExtensionEntity;
use solu1TaxJar\Core\Content\TaxProvider\TaxProviderEntity;
use solu1TaxJar\Core\Tax\TaxCalculatorRegistry;

class AddTaxCollector implements CartProcessorInterface
{
    /**
     * @var EntityRepository
     */
    private $taxRepository;

    /**
     * @var EntityRepository
     */
    private $taxProviderRepository;

    private TaxCalculatorRegistry $taxCalculatorRegistry;


    /**
     * @param EntityRepository $taxRepository
     * @param EntityRepository $taxProviderRepository
     * @param TaxCalculatorRegistry $taxCalculatorRegistry
     */
    public function __construct(
        EntityRepository        $taxRepository,
        EntityRepository        $taxProviderRepository,
        TaxCalculatorRegistry   $taxCalculatorRegistry
    )
    {
        $this->taxRepository = $taxRepository;
        $this->taxProviderRepository = $taxProviderRepository;
        $this->taxCalculatorRegistry = $taxCalculatorRegistry;
    }

    private function getTaxProviderClass(string $taxRuleId, array $taxRules, array $taxProviders)
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

        if (!$taxProvider instanceof TaxProviderEntity || !$taxProvider->getBaseClass()) {
            return false;
        }

        return $this->taxCalculatorRegistry->getCalculatorFor($taxProvider->getBaseClass());
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
                $taxProviderMapping[$taxId][] = [
                    "id" => $product->getReferencedId(),
                    "quantity" => $product->getPrice()->getQuantity(),
                    "unit_price" => $product->getPrice()->getUnitPrice(),
                    "discount" => 0
                ];
            }
        }

        $taxCriteria = new Criteria(array_unique($taxIds));
        $taxRules = $this->taxRepository->search($taxCriteria, $context->getContext())->getElements();

        $providerIds = [];
        foreach ($taxRules as $taxRule) {
          $extension = $taxRule->getExtension('taxExtension');
          if ($extension instanceof TaxExtensionEntity && $extension->getProviderId()) {
            $providerIds[] = $extension->getProviderId();
          }
        }

        $taxProviders = [];

        if (!empty($providerIds)) {
          $providerCriteria = new Criteria(array_unique($providerIds));
          $taxProviders = $this->taxProviderRepository->search($providerCriteria, $context->getContext())->getElements();
        }

        foreach ($taxProviderMapping as $taxId => $requestDetails) {
            $taxProviderClass = $this->getTaxProviderClass($taxId, $taxRules, $taxProviders);
            if ($taxProviderClass) {
                $lineItems = array_values($requestDetails);
                $lineItemsTax = $taxProviderClass->calculate($lineItems, $context, $original);
                $this->addRateToCart($lineItemsTax, $toCalculate);

                if (!empty($lineItemsTax)) {
                    $shippingTaxFromServiceProvider = 0;
                    $methodTaxAmount = 0;

                    if (!empty($lineItemsTax['shippingTax'])) {
                        $shippingTaxFromServiceProvider = $lineItemsTax['shippingTax'];
                    }

                    $shippingMethodCalculatedTax = $original->getShippingCosts()->getCalculatedTaxes();
                    foreach ($shippingMethodCalculatedTax as $methodCalculatedTax) {
                        $methodTaxAmount += $methodCalculatedTax->getTax();
                    }

                    foreach ($products as $product) {
                        $productId = $product->getReferencedId();
                        if (!empty($lineItemsTax[$productId])) {
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
                                        'taxRate' => (float)number_format(
                                            (float)$lineItemsTax['rate'] * 100,
                                            2,
                                            '.',
                                            ''
                                        )
                                    ]);
                                }
                            }
                        }
                    }

                    if (!empty($lineItemsTax['shippingTax'])) {
                        $shippingCalculatedTaxes = $original->getShippingCosts()->getCalculatedTaxes();
                        foreach ($shippingCalculatedTaxes as $shippingCalculatedTax) {
                            $shippingCalculatedTax->setTax((float)0);
                        }
                    }
                }
            }
        }
    }

    private function addRateToCart($lineItemsTax, Cart $toCalculate)
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
