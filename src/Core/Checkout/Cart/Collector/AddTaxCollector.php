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
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Psr\Log\LoggerInterface;
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

    private LoggerInterface $logger;

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
        RuleMatcherService    $ruleMatcher,
        LoggerInterface       $logger
    ) {
        $this->taxRepository = $taxRepository;
        $this->taxProviderRepository = $taxProviderRepository;
        $this->taxCalculatorRegistry = $taxCalculatorRegistry;
        $this->systemConfigService = $systemConfigService;
        $this->ruleRepository = $ruleRepository;
        $this->ruleMatcher = $ruleMatcher;
        $this->logger = $logger;
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
        $taxIds = array_values(array_unique(array_filter($taxIds)));

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
        $providerShippingTax = null;

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
                $this->logger->error('TaxJar tax calculation threw; falling back to native Shopware tax', [
                    'salesChannelId' => $context->getSalesChannelId(),
                    'taxId' => $taxId,
                    'exceptionClass' => \get_class($e),
                    'exceptionMessage' => $e->getMessage(),
                ]);

                $shopwareShippingExemptMatched = $shopwareShippingExemptMatched
                    || $this->ruleMatcher->matchesAny('shopwareShippingTaxExemptRuleIds', $toCalculate, $context);
                continue;
            }

            if (empty($lineItemsTax)) {
                $this->logger->warning('TaxJar returned no tax result; falling back to native Shopware tax', [
                    'salesChannelId' => $context->getSalesChannelId(),
                    'taxId' => $taxId,
                ]);

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

            if (!empty($lineItemsTax)) {
                foreach ($products as $product) {
                    $productId = $product->getReferencedId();
                    if (!empty($lineItemsTax[$productId])) {
                        $calculatedTaxes = $product->getPrice()->getCalculatedTaxes();
                        $providerRate = isset($lineItemsTax['rate']) ? (float) $lineItemsTax['rate'] * 100 : 0;
                        $product->getPrice()->assign([
                            'taxRules' => new TaxRuleCollection([
                                new TaxRule($providerRate),
                            ]),
                        ]);

                        foreach ($calculatedTaxes as $calculatedTax) {
                            $calculatedTax->setTax((float) $lineItemsTax[$productId]);

                            if ($providerRate > 0) {
                                $calculatedTax->assign([
                                    'taxRate' => $providerRate,
                                ]);
                            }
                        }
                    }
                }

                if (array_key_exists('shippingTax', $lineItemsTax)) {
                    $providerShippingTax = [
                        'tax' => (float) $lineItemsTax['shippingTax'],
                        'rate' => (float) ($lineItemsTax['shippingTaxRate'] ?? 0),
                    ];
                }
            }
        }

        if ($providerShippingTax !== null) {
            $this->applyProviderShippingTax($providerShippingTax, $toCalculate);
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

    private function applyProviderShippingTax(array $providerShippingTax, Cart $toCalculate): void
    {
        $deliveries = $toCalculate->getDeliveries();
        $totalShipping = $deliveries->getShippingCosts()->getTotalPriceAmount();
        $rate = $providerShippingTax['rate'];
        $taxRules = new TaxRuleCollection([new TaxRule($rate)]);
        $remaining = $providerShippingTax['tax'];
        $last = $deliveries->count() - 1;
        $index = 0;

        foreach ($deliveries as $delivery) {
            $costs = $delivery->getShippingCosts();
            $share = $index === $last || $totalShipping <= 0.0
                ? $remaining
                : round($providerShippingTax['tax'] * ($costs->getTotalPrice() / $totalShipping), 2);
            $remaining -= $share;

            $delivery->setShippingCosts(new CalculatedPrice(
                $costs->getUnitPrice(),
                $costs->getTotalPrice(),
                new CalculatedTaxCollection([new CalculatedTax($share, $rate, $costs->getTotalPrice())]),
                $taxRules,
                $costs->getQuantity()
            ));
            ++$index;
        }

        $toCalculate->markModified();
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
