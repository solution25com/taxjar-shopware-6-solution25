<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Gateway\Command\Handler;

use Shopware\Core\Checkout\Gateway\CheckoutGatewayException;
use Shopware\Core\Checkout\Gateway\CheckoutGatewayResponse;
use Shopware\Core\Checkout\Gateway\Command\AbstractCheckoutGatewayCommand;
use Shopware\Core\Checkout\Gateway\Command\AddShippingMethodCommand;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Log\ExceptionLogger;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

#[Package('checkout')]
class AddShippingMethodCommandHandler extends AbstractCheckoutGatewayCommandHandler
{
    /**
     * @internal
     *
     * @param EntityRepository<ShippingMethodCollection> $shippingMethodRepository
     */
    public function __construct(
        private readonly EntityRepository $shippingMethodRepository,
        private readonly ExceptionLogger $logger,
    ) {
    }

    public static function supportedCommands(): array
    {
        return [
            AddShippingMethodCommand::class,
        ];
    }

    /**
     * @param AddShippingMethodCommand $command
     */
    public function handle(AbstractCheckoutGatewayCommand $command, CheckoutGatewayResponse $response, SalesChannelContext $context): void
    {
        $technicalName = $command->shippingMethodTechnicalName;
        $methods = $response->getAvailableShippingMethods();

        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('technicalName', $technicalName))
            ->addAssociation('appShippingMethod.app');

        $shippingMethod = $this->shippingMethodRepository->search($criteria, $context->getContext())->getEntities()->first();
        if (!$shippingMethod) {
            $this->logger->logOrThrowException(
                CheckoutGatewayException::handlerException('Shipping method "{{ technicalName }}" not found', ['technicalName' => $technicalName])
            );

            return;
        }

        $methods->add($shippingMethod);
    }
}
