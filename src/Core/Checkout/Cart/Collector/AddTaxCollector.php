<?php

declare(strict_types=1);

namespace ITGCoTax\Core\Checkout\Cart\Collector;

use AllowDynamicProperties;
use ITGCoTax\Core\Checkout\Cart\Extension\TaxExtensionStruct;
use Psr\Cache\CacheItemPoolInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

#[AllowDynamicProperties]
class AddTaxCollector implements CartProcessorInterface
{
    /**
     * @var EntityRepository
     */


    /**
     * @var QuantityPriceCalculator
     */

    /**
     * @var EntityRepository
     */
    private $taxRepository;

    /**
     * @var EntityRepository
     */
    private $taxProviderRepository;

    /**
     * @var SystemConfigService
     */
    private $systemConfigService;

    /**
     * @var EntityRepository
     */
    private $taxJarLogRepository;

    /**
     * @var EntityRepository
     */
    private $productRepository;

    /**
     * @var CacheItemPoolInterface
     */
    private $cache;

    /**
     * @param EntityRepository $taxRepository
     * @param EntityRepository $taxProviderRepository
     * @param EntityRepository $taxJarLogRepository
     * @param EntityRepository $productRepository
     * @param SystemConfigService $systemConfigService
     */
    public function __construct(
        EntityRepository        $taxRepository,
        EntityRepository        $taxProviderRepository,
        EntityRepository        $taxJarLogRepository,
        EntityRepository        $productRepository,
        SystemConfigService     $systemConfigService,
        CacheItemPoolInterface  $cache
    )
    {
        $this->taxRepository = $taxRepository;
        $this->taxProviderRepository = $taxProviderRepository;
        $this->systemConfigService = $systemConfigService;
        $this->taxJarLogRepository = $taxJarLogRepository;
        $this->productRepository = $productRepository;
        $this->cache = $cache;
    }

    private function getTaxProviderClass($taxRuleId, SalesChannelContext $context)
    {
        if ($taxRuleId) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsAnyFilter('id', [0 => $taxRuleId]));
            $taxRules = $this->taxRepository->search($criteria, $context->getContext());
            foreach ($taxRules as $taxRule) {
                /** @var TaxExtensionStruct|null $extension */
                $extension = $taxRule->getExtension('taxExtension');
                if ($extension && $extension->getProviderId()) {
                    $criteria = new Criteria([$extension->getProviderId()]);
                    $taxProviders = $this->taxProviderRepository->search($criteria, $context->getContext());
                    foreach ($taxProviders as $taxProvider) {
                        $taxCalculatorClass = $taxProvider->get('baseClass');
                        if ($taxCalculatorClass) {
                            return new $taxCalculatorClass(
                                $this->systemConfigService,
                                $this->taxJarLogRepository,
                                $this->productRepository,
                                $this->cache
                            );
                        }
                    }
                }
            }
        }
        return false;
    }

    public function process(CartDataCollection $data, Cart $original, Cart $toCalculate, SalesChannelContext $context, CartBehavior $behavior): void
    {
        // get all product line items
        $products = $toCalculate->getLineItems()->filterType(LineItem::PRODUCT_LINE_ITEM_TYPE);
        $taxProviderMapping = [];
        $lineItemsTax = [];
        foreach ($products as $product) {
            $taxId = $product->getPayloadValue('taxId');
            $taxProviderMapping[$taxId][] = [
                "id" => $product->getReferencedId(),
                "quantity" => $product->getPrice()->getQuantity(),
                "unit_price" => $product->getPrice()->getUnitPrice(),
                "discount" => 0
            ];
        }
        foreach ($taxProviderMapping as $taxId => $requestDetails) {
            $taxProviderClass = $this->getTaxProviderClass($taxId, $context);
            if ($taxProviderClass) {
                $lineItems = [];
                foreach ($requestDetails as $key => $lineItem) {
                    $lineItems[] = $lineItem;
                }
                $lineItemsTax = $taxProviderClass->calculate($lineItems, $context, $original);
                $this->addRateToCart($lineItemsTax, $toCalculate);
                if (!empty($lineItemsTax)) {
                    $shippingTaxFromServiceProvider = 0;
                    $methodTaxAmount = 0;
                    if (isset($lineItemsTax['shippingTax']) && $lineItemsTax['shippingTax']) {
                        $shippingTaxFromServiceProvider = $lineItemsTax['shippingTax'];
                    }
                    $shippingMethodCalculatedTax = $original->getShippingCosts()->getCalculatedTaxes();
                    foreach ($shippingMethodCalculatedTax as $methodCalculatedTax) {
                        $methodTaxAmount = $methodTaxAmount + $methodCalculatedTax->getTax();
                    }
                    foreach ($products as $product) {
                        if (isset($lineItemsTax[$product->getReferencedId()]) && $lineItemsTax[$product->getReferencedId()]) {
                            $calculatedTaxes = $product->getPrice()->getCalculatedTaxes();
                            foreach ($calculatedTaxes as $calculatedTax) {
                                $taxAmount = $lineItemsTax[$product->getReferencedId()];
                                if ($shippingTaxFromServiceProvider) {
                                    $taxAmount = $taxAmount + $shippingTaxFromServiceProvider - $methodTaxAmount;
                                    $shippingTaxFromServiceProvider = 0;
                                }
                                $calculatedTax->setTax($taxAmount);
                                if (isset($lineItemsTax['rate'])) {
                                    $calculatedTax->assign(
                                        [
                                            'taxRate' => (float)number_format(
                                                (float)$lineItemsTax['rate'] * 100,
                                                2,
                                                '.',
                                                '')
                                        ]
                                    );
                                }
                            }
                        }
                    }
                    if (isset($lineItemsTax['shippingTax']) && $lineItemsTax['shippingTax']) {
                        $shippingCalculatedTaxes = $original->getShippingCosts()->getCalculatedTaxes();
                        foreach ($shippingCalculatedTaxes as $shippingCalculatedTax) {
                            //As Shipping Cost is already included in Tax Calculation
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
