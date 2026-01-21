<?php

declare(strict_types=1);

namespace solu1TaxJar\Core\Checkout\Cart\Collector;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use solu1TaxJar\Core\Content\Extension\TaxExtensionEntity;
use solu1TaxJar\Core\Content\TaxProvider\TaxProviderEntity;
use solu1TaxJar\Core\Rule\RuleMatcherService;
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

    private SystemConfigService $systemConfigService;

    private EntityRepository $ruleRepository;

    private RuleMatcherService $ruleMatcher;

    /**
     * @param EntityRepository $taxRepository
     * @param EntityRepository $taxProviderRepository
     * @param TaxCalculatorRegistry $taxCalculatorRegistry
     * @param SystemConfigService $systemConfigService
     * @param EntityRepository $ruleRepository
     * @param RuleMatcherService $ruleMatcher
     */
    public function __construct(
        EntityRepository      $taxRepository,
        EntityRepository      $taxProviderRepository,
        TaxCalculatorRegistry $taxCalculatorRegistry,
        SystemConfigService   $systemConfigService,
        EntityRepository      $ruleRepository,
        RuleMatcherService    $ruleMatcher
    ) {
        $this->taxRepository = $taxRepository;
        $this->taxProviderRepository = $taxProviderRepository;
        $this->taxCalculatorRegistry = $taxCalculatorRegistry;
        $this->systemConfigService = $systemConfigService;
        $this->ruleRepository = $ruleRepository;
        $this->ruleMatcher = $ruleMatcher;
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
        $totalDiscount = 0;
        $totalProductAmount = 0;

        foreach ($toCalculate->getLineItems() as $lineItem) {
            $type = $lineItem->getType();
            if ($type === LineItem::PROMOTION_LINE_ITEM_TYPE || $type === LineItem::CREDIT_LINE_ITEM_TYPE) {
                $discountAmount = abs($lineItem->getPrice()->getTotalPrice());
                $totalDiscount += $discountAmount;
            }
        }

        foreach ($toCalculate->getLineItems() as $lineItem) {
            $type = $lineItem->getType();
            $price = $lineItem->getPrice();

            if ($price && $price->getTotalPrice() < 0 &&
                $type !== LineItem::PRODUCT_LINE_ITEM_TYPE &&
                $type !== LineItem::PROMOTION_LINE_ITEM_TYPE &&
                $type !== LineItem::CREDIT_LINE_ITEM_TYPE &&
                $type !== LineItem::CONTAINER_LINE_ITEM) {

                $discountAmount = abs($price->getTotalPrice());
                $totalDiscount += $discountAmount;
            }
        }

        foreach ($products as $product) {
            $totalProductAmount += $product->getPrice()->getTotalPrice();
        }

        $taxIds = [];
        foreach ($products as $product) {
            $taxId = $product->getPayloadValue('taxId');
            if ($taxId) {
                $taxIds[] = $taxId;

                $productAmount = $product->getPrice()->getTotalPrice();
                $proportionalDiscount = 0;

                if ($totalDiscount > 0 && $totalProductAmount > 0) {
                    $proportionalDiscount = ($productAmount / $totalProductAmount) * $totalDiscount;
                }

                $taxProviderMapping[$taxId][] = [
                    'id' => $product->getId(),
                    'product_id' => $product->getReferencedId(),
                    'quantity' => $product->getPrice()->getQuantity(),
                    'unit_price' => $product->getPrice()->getUnitPrice(),
                    'discount' => $proportionalDiscount > 0 ? round($proportionalDiscount, 2) : 0,
                ];
            }
        }

        if (empty($taxIds)) {
            return;
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

        $bypassMatched = $this->ruleMatcher->matchesAny('bypassTaxJarRuleIds', $toCalculate, $context);
        $shopwareShippingExemptMatched = false;

        foreach ($taxProviderMapping as $taxId => $requestDetails) {
            $taxProviderClass = $this->getTaxProviderClass($taxId, $taxRules, $taxProviders);
            if (!$taxProviderClass) {
                continue;
            }

            if ($bypassMatched) {
                $shopwareShippingExemptMatched = $shopwareShippingExemptMatched
                    || $this->ruleMatcher->matchesAny('shopwareShippingTaxExemptRuleIds', $toCalculate, $context);
                continue;
            }

            $lineItems = array_values($requestDetails);

            try {
                $lineItemsTax = $taxProviderClass->calculate($lineItems, $context, $original);
            } catch (\Throwable $e) {
                $shopwareShippingExemptMatched = $shopwareShippingExemptMatched
                    || $this->ruleMatcher->matchesAny('shopwareShippingTaxExemptRuleIds', $toCalculate, $context);
                continue;
            }

            if (empty($lineItemsTax)) {
                $shopwareShippingExemptMatched = $shopwareShippingExemptMatched
                    || $this->ruleMatcher->matchesAny('shopwareShippingTaxExemptRuleIds', $toCalculate, $context);
                continue;
            }

            if (!empty($lineItemsTax['taxjar_address_mismatch'])) {
                $toCalculate->addExtension('taxjar_address_mismatch', new ArrayEntity([
                    'taxjar_address_mismatch' => true,
                ]));

                foreach ($toCalculate->getLineItems() as $lineItem) {
                    $lineItem->setPayloadValue('taxjar_address_mismatch', true);
                }

                continue;
            }

            $this->addRateToCart($lineItemsTax, $toCalculate);

            foreach ($products as $product) {
                $productId = $product->getId();
                if (!array_key_exists($productId, $lineItemsTax)) {
                    continue;
                }

                $taxAmount = (float) $lineItemsTax[$productId];
                $calculatedTaxes = $product->getPrice()->getCalculatedTaxes();

                foreach ($calculatedTaxes as $calculatedTax) {
                    $calculatedTax->setTax($taxAmount);

                    if (isset($lineItemsTax['rate'])) {
                        $calculatedTax->assign([
                            'taxRate' => (float) number_format((float) $lineItemsTax['rate'] * 100, 2, '.', ''),
                        ]);
                    }
                }
            }

            $hasShippingTax = array_key_exists('shippingTax', $lineItemsTax);
            if ($hasShippingTax) {
                $shippingTaxFromServiceProvider = (float) $lineItemsTax['shippingTax'];

                $shippingCosts = $toCalculate->getShippingCosts();
                $shippingCalculatedTaxes = $shippingCosts->getCalculatedTaxes();

                if (count($shippingCalculatedTaxes) === 0) {
                    continue;
                }

                $lockedShippingTaxes = new CalculatedTaxCollection([
                    new CalculatedTax($shippingTaxFromServiceProvider, 0.0, $shippingCosts->getTotalPrice()),
                ]);

                $lockedShippingCosts = new CalculatedPrice(
                    $shippingCosts->getUnitPrice(),
                    $shippingCosts->getTotalPrice(),
                    $lockedShippingTaxes,
                    new TaxRuleCollection(),
                    $shippingCosts->getQuantity()
                );

                $deliveries = $toCalculate->getDeliveries();
                foreach ($deliveries as $delivery) {
                    $delivery->setShippingCosts($lockedShippingCosts);
                }

                $toCalculate->markModified();
            }
        }

        if ($bypassMatched || $shopwareShippingExemptMatched) {
            $shopwareShippingExemptMatched = $shopwareShippingExemptMatched
                || $this->ruleMatcher->matchesAny('shopwareShippingTaxExemptRuleIds', $toCalculate, $context);

            if ($shopwareShippingExemptMatched) {
                $shippingCosts = $toCalculate->getShippingCosts();

                $deliveries = $toCalculate->getDeliveries();
                if ($deliveries->count() === 0) {
                    return;
                }

                foreach ($deliveries as $delivery) {
                    $taxRules = $shippingCosts->getTaxRules() ?? new TaxRuleCollection();
                    $newShippingCosts = new CalculatedPrice(
                        $shippingCosts->getUnitPrice(),
                        $shippingCosts->getTotalPrice(),
                        new CalculatedTaxCollection([new CalculatedTax(0, 0, 0)]),
                        $taxRules,
                        $shippingCosts->getQuantity()
                    );

                    $delivery->setShippingCosts($newShippingCosts);
                }
                $toCalculate->markModified();
            }
        }
    }

    /**
     * @param $lineItemsTax
     * @param Cart $toCalculate
     * @return void
     */
    private function addRateToCart($lineItemsTax, Cart $toCalculate): void
    {
        if (isset($lineItemsTax['rate']) && $lineItemsTax['rate']) {
            foreach ($toCalculate->getLineItems() as $lineItem) {
                $lineItem->setPayloadValue('taxJarRate', $lineItemsTax['rate']);
            }
        }
    }
}
