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
    ) {
        parent::__construct($contextRestorer, $orderReturnRepository, $calculator, $amountCalculator, $percentageTaxRuleBuilder, $clock);
    }

    public function calculate(string $returnId, Context $context): void
    {
        $criteria = new Criteria([$returnId]);
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('order');

        $return = $this->orderReturnRepository->search($criteria, $context)->first();
        $taxRules = $return ? $this->orderShippingTaxRules($return->getOrder(), $returnId) : null;
        $shippingTotal = $return?->getShippingCosts()?->getTotalPrice() ?? 0.0;

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

    private function orderShippingTaxRules(?OrderEntity $order, string $returnId): ?TaxRuleCollection
    {
        $shippingCosts = $order?->getShippingCosts();

        if (!$shippingCosts) {
            return null;
        }

        if ($shippingCosts->getTaxRules()->count() > 0) {
            return $shippingCosts->getTaxRules();
        }

        if ($shippingCosts->getTotalPrice() > 0.0) {
            $this->logger->warning('Order has shipping costs but no shipping tax rules; keeping Shopware Auto tax for the return', [
                'orderId' => $order->getId(),
                'orderNumber' => $order->getOrderNumber(),
                'returnId' => $returnId,
            ]);
        }

        return null;
    }
}
