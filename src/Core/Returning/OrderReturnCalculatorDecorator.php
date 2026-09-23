<?php declare(strict_types=1);

namespace solu1TaxJar\Core\Returning;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Shopware\Commercial\ReturnManagement\Domain\Returning\OrderReturnCalculator;
use Shopware\Commercial\ReturnManagement\Entity\OrderReturn\OrderReturnEntity;
use Shopware\Core\Checkout\Cart\Price\AmountCalculator;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\PriceCollection;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Tax\PercentageTaxRuleBuilder;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextRestorer;

class OrderReturnCalculatorDecorator extends OrderReturnCalculator
{
    public function __construct(
        private readonly OrderReturnCalculator $inner,
        private readonly SalesChannelContextRestorer $contextRestorer,
        private readonly EntityRepository $orderReturnRepository,
        private readonly QuantityPriceCalculator $calculator,
        private readonly AmountCalculator $amountCalculator,
        PercentageTaxRuleBuilder $percentageTaxRuleBuilder,
        ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly ShippingTaxRecovery $shippingTaxRecovery,
    ) {
        parent::__construct($contextRestorer, $orderReturnRepository, $calculator, $amountCalculator, $percentageTaxRuleBuilder, $clock);
    }

    public function calculate(string $returnId, Context $context): void
    {
        $criteria = new Criteria([$returnId]);
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('order');
        $criteria->addAssociation('order.lineItems');

        $return = $this->orderReturnRepository->search($criteria, $context)->first();

        if (!$return) {
            $this->inner->calculate($returnId, $context);

            return;
        }

        $shippingTotal = $return->getShippingCosts()?->getTotalPrice() ?? 0.0;
        $taxRules = $this->resolveShippingTaxRules($return->getOrder(), $returnId);

        $this->inner->calculate($returnId, $context);

        if (!$taxRules || !$return->getLineItems()) {
            return;
        }

        $salesChannelContext = $this->contextRestorer->restoreByOrder($return->getOrderId(), $context);

        $refundsAmount = new PriceCollection();
        foreach ($return->getLineItems() as $lineItem) {
            if ($lineItem->getPrice()) {
                $refundsAmount->add($this->calculator->calculate(
                    new QuantityPriceDefinition($lineItem->getRefundAmount(), $lineItem->getPrice()->getTaxRules()),
                    $salesChannelContext
                ));
            }
        }

        $shippingCosts = $this->calculator->calculate(new QuantityPriceDefinition($shippingTotal, $taxRules, 1), $salesChannelContext);
        $returnPrice = $this->amountCalculator->calculate($refundsAmount, new PriceCollection([$shippingCosts]), $salesChannelContext);

        $this->orderReturnRepository->upsert([[
            'id' => $returnId,
            'amountTotal' => $returnPrice->getTotalPrice(),
            'amountNet' => $returnPrice->getNetPrice(),
            'price' => $returnPrice,
            'shippingCosts' => $shippingCosts,
        ]], $context);
    }

    private function resolveShippingTaxRules(?OrderEntity $order, string $returnId): ?TaxRuleCollection
    {
        $shippingCosts = $order?->getShippingCosts();

        if (!$shippingCosts) {
            return null;
        }

        $storedRules = $shippingCosts->getTaxRules();
        $isTaxed = $storedRules->filter(static fn (TaxRule $rule) => $rule->getTaxRate() > 0.0)->count() > 0;

        if ($isTaxed || $shippingCosts->getTotalPrice() <= 0.0) {
            return $storedRules;
        }

        $recovered = $this->shippingTaxRecovery->recover($order);

        if (!$recovered) {
            $this->logger->error('Order records untaxed shipping and its line item tax cannot be verified; keeping the Shopware return tax calculation', [
                'orderId' => $order->getId(),
                'orderNumber' => $order->getOrderNumber(),
                'returnId' => $returnId,
            ]);

            return null;
        }

        if ($recovered->getTaxRate() <= 0.0) {
            return $storedRules;
        }

        $this->logger->warning('Order stores shipping tax on a line item instead of the delivery; recovering the original rate for the return', [
            'orderId' => $order->getId(),
            'orderNumber' => $order->getOrderNumber(),
            'returnId' => $returnId,
            'taxRate' => $recovered->getTaxRate(),
        ]);

        return new TaxRuleCollection([$recovered]);
    }

}
