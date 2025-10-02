<?php

declare(strict_types=1);

namespace solu1TaxJar\Core\Checkout\Cart\Collector;

use Psr\Cache\CacheItemPoolInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\System\Tax\TaxCollection;
use Shopware\Core\System\TaxProvider\TaxProviderCollection;
use solu1TaxJar\Core\Checkout\Cart\Extension\TaxExtensionStruct;
use solu1TaxJar\Core\Content\TaxLog\TaxLogCollection;

class AddTaxCollector implements CartProcessorInterface
{
    /**
     * @var EntityRepository<TaxCollection>
     */
    private $taxRepository;

    /**
     * @var EntityRepository<TaxProviderCollection>
     */
    private $taxProviderRepository;

    /**
     * @var SystemConfigService
     */
    private $systemConfigService;

    /**
     * @var EntityRepository<TaxLogCollection>
     */
    private $taxJarLogRepository;

    /**
     * @var EntityRepository<ProductCollection>
     */
    private $productRepository;

    /**
     * @var CacheItemPoolInterface
     */
    private $cache;

    /**
     * @param EntityRepository<TaxCollection> $taxRepository
     * @param EntityRepository<TaxProviderCollection> $taxProviderRepository
     * @param EntityRepository<TaxLogCollection> $taxJarLogRepository
     * @param EntityRepository<ProductCollection> $productRepository
     * @param SystemConfigService $systemConfigService
     */
    public function __construct(
        EntityRepository        $taxRepository,
        EntityRepository        $taxProviderRepository,
        EntityRepository        $taxJarLogRepository,
        EntityRepository        $productRepository,
        SystemConfigService     $systemConfigService,
        CacheItemPoolInterface  $cache
    ) {
        $this->taxRepository = $taxRepository;
        $this->taxProviderRepository = $taxProviderRepository;
        $this->systemConfigService = $systemConfigService;
        $this->taxJarLogRepository = $taxJarLogRepository;
        $this->productRepository = $productRepository;
        $this->cache = $cache;
    }

    /**
     * @param string $taxRuleId
     * @return object|false
     */
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
                        if ($taxCalculatorClass && class_exists($taxCalculatorClass)) {
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
            $price = $product->getPrice();
            if (!$price instanceof CalculatedPrice) {
                continue;
            }

            $taxId = $product->getPayloadValue('taxId');
            $taxProviderMapping[$taxId][] = [
                "id" => $product->getReferencedId(),
                "quantity" => $price->getQuantity(),
                "unit_price" => $price->getUnitPrice(),
                "discount" => 0
            ];
        }

        foreach ($taxProviderMapping as $taxId => $requestDetails) {
            $taxProvider = $this->getTaxProviderClass($taxId, $context);
            if ($taxProvider && method_exists($taxProvider, 'calculate')) {
                $lineItems = [];
                foreach ($requestDetails as $lineItem) {
                    $lineItems[] = $lineItem;
                }

                $lineItemsTax = $taxProvider->calculate($lineItems, $context, $original);
                $this->addRateToCart($lineItemsTax, $toCalculate);

                if (!empty($lineItemsTax)) {
                    $shippingTaxFromServiceProvider = 0;
                    $methodTaxAmount = 0;

                    if (isset($lineItemsTax['shippingTax']) && $lineItemsTax['shippingTax']) {
                        $shippingTaxFromServiceProvider = $lineItemsTax['shippingTax'];
                    }


                    $shippingCosts = $original->getShippingCosts();
                    $shippingMethodCalculatedTax = $shippingCosts->getCalculatedTaxes();
                    foreach ($shippingMethodCalculatedTax as $methodCalculatedTax) {
                        $methodTaxAmount += $methodCalculatedTax->getTax();
                    }

                    foreach ($products as $product) {
                        $price = $product->getPrice();
                        if (!$price instanceof CalculatedPrice) {
                            continue;
                        }

                        if (isset($lineItemsTax[$product->getReferencedId()]) && $lineItemsTax[$product->getReferencedId()]) {
                            $calculatedTaxes = $price->getCalculatedTaxes();
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
                                                ''
                                            )
                                        ]
                                    );
                                }
                            }
                        }
                    }


                    if (isset($lineItemsTax['shippingTax']) && $lineItemsTax['shippingTax']) {
                        $shippingCosts = $original->getShippingCosts();
                        $shippingCalculatedTaxes = $shippingCosts->getCalculatedTaxes();
                        foreach ($shippingCalculatedTaxes as $shippingCalculatedTax) {
                            // As Shipping Cost is already included in Tax Calculation
                            $shippingCalculatedTax->setTax((float)0);
                        }
                    }
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $lineItemsTax
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