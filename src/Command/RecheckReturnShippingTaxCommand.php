<?php declare(strict_types=1);

namespace solu1TaxJar\Command;

use Shopware\Commercial\ReturnManagement\Domain\Returning\OrderReturnCalculator;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Util\FloatComparator;
use solu1TaxJar\Core\Returning\ShippingTaxRecovery;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'taxjar:returns:recheck-shipping-tax',
    description: 'Lists returns whose shipping tax was calculated from an order that stored no shipping tax rule, and optionally recalculates them.'
)]
class RecheckReturnShippingTaxCommand extends Command
{
    private const TAX_TOLERANCE = 0.01;

    private const STATUS_OK = 'ok';
    private const STATUS_REPAIRABLE = 'repairable';
    private const STATUS_EDITED = 'shipping edited';
    private const STATUS_REVIEW = 'needs review';

    public function __construct(
        private readonly EntityRepository $orderReturnRepository,
        private readonly ShippingTaxRecovery $shippingTaxRecovery,
        private readonly OrderReturnCalculator $returnCalculator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('write', null, InputOption::VALUE_NONE, 'Recalculate the returns marked repairable');
        $this->addOption('return-number', null, InputOption::VALUE_REQUIRED, 'Limit to a single return');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum returns to inspect', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $context = Context::createDefaultContext();
        $write = (bool) $input->getOption('write');

        $criteria = new Criteria();
        $criteria->addAssociation('order.lineItems');
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::ASCENDING));
        $criteria->setLimit((int) $input->getOption('limit'));

        if ($returnNumber = $input->getOption('return-number')) {
            $criteria->addFilter(new EqualsFilter('returnNumber', $returnNumber));
        }

        $returns = $this->orderReturnRepository->search($criteria, $context)->getEntities();

        $rows = [];
        $repairable = [];

        foreach ($returns as $return) {
            $order = $return->getOrder();
            $returnShipping = $return->getShippingCosts();

            if (!$order || !$returnShipping || $returnShipping->getTotalPrice() <= 0.0) {
                continue;
            }

            $orderShipping = $order->getShippingCosts();
            $orderTaxed = $orderShipping->getTaxRules()
                ->filter(static fn ($rule) => $rule->getTaxRate() > 0.0)->count() > 0;

            if ($orderTaxed) {
                continue;
            }

            $recovered = $this->shippingTaxRecovery->recover($order);
            $carriesTax = FloatComparator::greaterThan($returnShipping->getCalculatedTaxes()->getAmount(), self::TAX_TOLERANCE);
            $shouldCarryTax = $recovered !== null && $recovered->getTaxRate() > 0.0;

            $status = match (true) {
                $recovered === null => self::STATUS_REVIEW,
                $carriesTax === $shouldCarryTax => self::STATUS_OK,
                !FloatComparator::equals($returnShipping->getTotalPrice(), $orderShipping->getTotalPrice()) => self::STATUS_EDITED,
                default => self::STATUS_REPAIRABLE,
            };

            if ($status === self::STATUS_REPAIRABLE) {
                $repairable[] = $return->getId();
            }

            $rows[] = [
                $return->getReturnNumber(),
                $order->getOrderNumber(),
                number_format($orderShipping->getTotalPrice(), 2),
                number_format($returnShipping->getTotalPrice(), 2),
                number_format($returnShipping->getCalculatedTaxes()->getAmount(), 2),
                $recovered ? number_format($recovered->getTaxRate(), 2) . '%' : '-',
                $status,
            ];
        }

        if ($rows === []) {
            $io->success('No returns found whose order stored an untaxed shipping rule.');

            return Command::SUCCESS;
        }

        $io->table(['Return', 'Order', 'Order ship', 'Return ship', 'Return tax', 'Recovered', 'Status'], $rows);

        if (!$write) {
            $io->note(sprintf('%d repairable. Re-run with --write to recalculate them.', \count($repairable)));

            return Command::SUCCESS;
        }

        foreach ($repairable as $returnId) {
            $this->returnCalculator->calculate($returnId, $context);
        }

        $io->success(sprintf('Recalculated %d return(s).', \count($repairable)));

        return Command::SUCCESS;
    }
}
