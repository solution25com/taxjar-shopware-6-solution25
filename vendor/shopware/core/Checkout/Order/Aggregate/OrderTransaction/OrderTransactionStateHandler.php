<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Order\Aggregate\OrderTransaction;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;
use Shopware\Core\System\StateMachine\StateMachineException;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;

#[Package('checkout')]
class OrderTransactionStateHandler
{
    /**
     * @internal
     */
    public function __construct(private readonly StateMachineRegistry $stateMachineRegistry)
    {
    }

    /**
     * @throws InconsistentCriteriaIdsException
     * @throws StateMachineException
     * @throws IllegalTransitionException
     */
    public function reopen(string $transactionId, Context $context): void
    {
        $this->stateMachineRegistry->transition(
            new Transition(
                OrderTransactionDefinition::ENTITY_NAME,
                $transactionId,
                StateMachineTransitionActions::ACTION_REOPEN,
                'stateId'
            ),
            $context
        );
    }

    /**
     * @throws InconsistentCriteriaIdsException
     * @throws StateMachineException
     * @throws IllegalTransitionException
     */
    public function fail(string $transactionId, Context $context): void
    {
        $this->stateMachineRegistry->transition(
            new Transition(
                OrderTransactionDefinition::ENTITY_NAME,
                $transactionId,
                StateMachineTransitionActions::ACTION_FAIL,
                'stateId'
            ),
            $context
        );
    }

    /**
     * @throws InconsistentCriteriaIdsException
     * @throws StateMachineException
     * @throws IllegalTransitionException
     */
    public function process(string $transactionId, Context $context): void
    {
        $this->stateMachineRegistry->transition(
            new Transition(
                OrderTransactionDefinition::ENTITY_NAME,
                $transactionId,
                StateMachineTransitionActions::ACTION_PROCESS,
                'stateId'
            ),
            $context
        );
    }

    /**
     * @throws InconsistentCriteriaIdsException
     * @throws StateMachineException
     * @throws IllegalTransitionException
     */
    public function processUnconfirmed(string $transactionId, Context $context): void
    {
        $this->stateMachineRegistry->transition(
            new Transition(
                OrderTransactionDefinition::ENTITY_NAME,
                $transactionId,
                StateMachineTransitionActions::ACTION_PROCESS_UNCONFIRMED,
                'stateId'
            ),
            $context
        );
    }

    /**
     * @throws InconsistentCriteriaIdsException
     * @throws StateMachineException
     * @throws IllegalTransitionException
     */
    public function paid(string $transactionId, Context $context): void
    {
        $this->stateMachineRegistry->transition(
            new Transition(
                OrderTransactionDefinition::ENTITY_NAME,
                $transactionId,
                StateMachineTransitionActions::ACTION_PAID,
                'stateId'
            ),
            $context
        );
    }

    /**
     * @deprecated tag:v6.8.0 - Will be removed. Use OrderTransactionStateHandler::paidPartially instead
     *
     * @throws InconsistentCriteriaIdsException
     * @throws StateMachineException
     * @throws IllegalTransitionException
     */
    public function payPartially(string $transactionId, Context $context): void
    {
        Feature::triggerDeprecationOrThrow(
            'v6.8.0.0',
            Feature::deprecatedMethodMessage(self::class, __METHOD__, 'v6.8.0.0', 'OrderTransactionStateHandler::paidPartially')
        );

        $this->paidPartially($transactionId, $context);
    }

    /**
     * @throws InconsistentCriteriaIdsException
     * @throws StateMachineException
     * @throws IllegalTransitionException
     */
    public function paidPartially(string $transactionId, Context $context): void
    {
        $this->stateMachineRegistry->transition(
            new Transition(
                OrderTransactionDefinition::ENTITY_NAME,
                $transactionId,
                StateMachineTransitionActions::ACTION_PAID_PARTIALLY,
                'stateId'
            ),
            $context
        );
    }

    /**
     * @throws InconsistentCriteriaIdsException
     * @throws StateMachineException
     * @throws IllegalTransitionException
     */
    public function refund(string $transactionId, Context $context): void
    {
        $this->stateMachineRegistry->transition(
            new Transition(
                OrderTransactionDefinition::ENTITY_NAME,
                $transactionId,
                StateMachineTransitionActions::ACTION_REFUND,
                'stateId'
            ),
            $context
        );
    }

    /**
     * @throws InconsistentCriteriaIdsException
     * @throws StateMachineException
     * @throws IllegalTransitionException
     */
    public function refundPartially(string $transactionId, Context $context): void
    {
        $this->stateMachineRegistry->transition(
            new Transition(
                OrderTransactionDefinition::ENTITY_NAME,
                $transactionId,
                StateMachineTransitionActions::ACTION_REFUND_PARTIALLY,
                'stateId'
            ),
            $context
        );
    }

    /**
     * @throws InconsistentCriteriaIdsException
     * @throws StateMachineException
     * @throws IllegalTransitionException
     */
    public function cancel(string $transactionId, Context $context): void
    {
        $this->stateMachineRegistry->transition(
            new Transition(
                OrderTransactionDefinition::ENTITY_NAME,
                $transactionId,
                StateMachineTransitionActions::ACTION_CANCEL,
                'stateId'
            ),
            $context
        );
    }

    /**
     * @throws InconsistentCriteriaIdsException
     * @throws StateMachineException
     * @throws IllegalTransitionException
     */
    public function remind(string $transactionId, Context $context): void
    {
        $this->stateMachineRegistry->transition(
            new Transition(
                OrderTransactionDefinition::ENTITY_NAME,
                $transactionId,
                StateMachineTransitionActions::ACTION_REMIND,
                'stateId'
            ),
            $context
        );
    }

    /**
     * @throws InconsistentCriteriaIdsException
     * @throws StateMachineException
     * @throws IllegalTransitionException
     */
    public function authorize(string $transactionId, Context $context): void
    {
        $this->stateMachineRegistry->transition(
            new Transition(
                OrderTransactionDefinition::ENTITY_NAME,
                $transactionId,
                StateMachineTransitionActions::ACTION_AUTHORIZE,
                'stateId'
            ),
            $context
        );
    }

    /**
     * @throws InconsistentCriteriaIdsException
     * @throws StateMachineException
     * @throws IllegalTransitionException
     */
    public function chargeback(string $transactionId, Context $context): void
    {
        $this->stateMachineRegistry->transition(
            new Transition(
                OrderTransactionDefinition::ENTITY_NAME,
                $transactionId,
                StateMachineTransitionActions::ACTION_CHARGEBACK,
                'stateId'
            ),
            $context
        );
    }
}
