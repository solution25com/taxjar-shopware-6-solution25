<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Customer\SalesChannel;

use Shopware\Core\Checkout\Customer\CustomerCollection;
use Shopware\Core\Checkout\Customer\CustomerException;
use Shopware\Core\Checkout\Customer\Event\CustomerLoginEvent;
use Shopware\Core\Checkout\Customer\Event\CustomerRegisterEvent;
use Shopware\Core\Checkout\Customer\Event\GuestCustomerRegisterEvent;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Routing\StoreApiRouteScope;
use Shopware\Core\Framework\Util\Hasher;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\Validation\DataValidationDefinition;
use Shopware\Core\Framework\Validation\DataValidator;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceInterface;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceParameters;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\EqualTo;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StoreApiRouteScope::ID]])]
#[Package('checkout')]
class RegisterConfirmRoute extends AbstractRegisterConfirmRoute
{
    /**
     * @internal
     *
     * @param EntityRepository<CustomerCollection> $customerRepository
     */
    public function __construct(
        private readonly EntityRepository $customerRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly DataValidator $validator,
        private readonly SalesChannelContextPersister $contextPersister,
        private readonly SalesChannelContextServiceInterface $contextService
    ) {
    }

    public function getDecorated(): AbstractRegisterConfirmRoute
    {
        throw new DecorationPatternException(self::class);
    }

    #[Route(path: '/store-api/account/register-confirm', name: 'store-api.account.register.confirm', methods: ['POST'])]
    public function confirm(RequestDataBag $dataBag, SalesChannelContext $context): CustomerResponse
    {
        if (!$dataBag->has('hash')) {
            throw CustomerException::noHashProvided();
        }

        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('hash', $dataBag->get('hash')))
            ->addAssociations(['addresses', 'salutation'])
            ->setLimit(1);

        $customer = $this->customerRepository->search($criteria, $context->getContext())->getEntities()->first();
        if (!$customer) {
            throw CustomerException::customerNotFoundByHash($dataBag->get('hash'));
        }

        $this->validator->validate(
            [
                'em' => $dataBag->get('em'),
                'doubleOptInRegistration' => $customer->getDoubleOptInRegistration(),
            ],
            $this->getBeforeConfirmValidation(Hasher::hash($customer->getEmail(), 'sha1'))
        );

        if ($customer->getDoubleOptInConfirmDate() !== null) {
            throw CustomerException::customerAlreadyConfirmed($customer->getId());
        }

        $customerUpdate = [
            'id' => $customer->getId(),
            'doubleOptInConfirmDate' => new \DateTimeImmutable(),
        ];
        $this->customerRepository->update([$customerUpdate], $context->getContext());

        $newToken = $this->contextPersister->replace($context->getToken(), $context);

        $this->contextPersister->save(
            $newToken,
            [
                'customerId' => $customer->getId(),
                'billingAddressId' => null,
                'shippingAddressId' => null,
            ],
            $context->getSalesChannelId(),
            $customer->getId()
        );

        $new = $this->contextService->get(
            new SalesChannelContextServiceParameters(
                $context->getSalesChannelId(),
                $newToken,
                $context->getLanguageId(),
                $context->getCurrencyId(),
                $context->getDomainId(),
                $context->getContext(),
                $customer->getId()
            )
        );

        $new->addState(...$context->getStates());

        if ($customer->getGuest()) {
            $this->eventDispatcher->dispatch(new GuestCustomerRegisterEvent($new, $customer));
        } else {
            $this->eventDispatcher->dispatch(new CustomerRegisterEvent($new, $customer));
        }

        $criteria = (new Criteria([$customer->getId()]))
            ->addAssociations(['addresses', 'salutation'])
            ->setLimit(1);

        $customer = $this->customerRepository->search($criteria, $new->getContext())->getEntities()->first();
        \assert($customer !== null);

        $response = new CustomerResponse($customer);

        $event = new CustomerLoginEvent($new, $customer, $newToken);
        $this->eventDispatcher->dispatch($event);

        $response->headers->set(PlatformRequest::HEADER_CONTEXT_TOKEN, $newToken);

        return $response;
    }

    private function getBeforeConfirmValidation(string $emHash): DataValidationDefinition
    {
        $definition = new DataValidationDefinition('registration.opt_in_before');
        $definition->add('em', new EqualTo(value: $emHash));
        $definition->add('doubleOptInRegistration', new IsTrue());

        return $definition;
    }
}
