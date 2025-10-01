<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Order;

use Shopware\Core\Checkout\Customer\Exception\CustomerAuthThrottledException;
use Shopware\Core\Checkout\Order\Exception\GuestNotAuthenticatedException;
use Shopware\Core\Checkout\Order\Exception\WrongGuestCredentialsException;
use Shopware\Core\Content\Flow\Exception\CustomerDeletedException;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\AssociationNotFoundException;
use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\HttpException;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ShopwareHttpException;
use Symfony\Component\HttpFoundation\Response;

#[Package('checkout')]
class OrderException extends HttpException
{
    final public const ORDER_MISSING_ORDER_ASSOCIATION_CODE = 'CHECKOUT__ORDER_MISSING_ORDER_ASSOCIATION';
    final public const ORDER_ORDER_DELIVERY_NOT_FOUND_CODE = 'CHECKOUT__ORDER_ORDER_DELIVERY_NOT_FOUND';
    final public const ORDER_ORDER_CANCELLED_CODE = 'CHECKOUT__ORDER_ORDER_CANCELLED';
    final public const ORDER_ORDER_NOT_FOUND_CODE = 'CHECKOUT__ORDER_ORDER_NOT_FOUND';
    final public const ORDER_MISSING_ORDER_NUMBER_CODE = 'CHECKOUT__ORDER_MISSING_ORDER_NUMBER';
    final public const ORDER_MISSING_TRANSACTIONS_CODE = 'CHECKOUT__ORDER_MISSING_TRANSACTIONS';
    final public const ORDER_ORDER_TRANSACTION_NOT_FOUND_CODE = 'CHECKOUT__ORDER_ORDER_TRANSACTION_NOT_FOUND';
    final public const ORDER_PAYMENT_METHOD_UNAVAILABLE = 'CHECKOUT__ORDER_PAYMENT_METHOD_NOT_AVAILABLE';
    final public const ORDER_ORDER_ALREADY_PAID_CODE = 'CHECKOUT__ORDER_ORDER_ALREADY_PAID';
    final public const ORDER_CAN_NOT_RECALCULATE_LIVE_VERSION_CODE = 'CHECKOUT__ORDER_CAN_NOT_RECALCULATE_LIVE_VERSION';
    final public const ORDER_PAYMENT_METHOD_NOT_CHANGEABLE_CODE = 'CHECKOUT__ORDER_PAYMENT_METHOD_NOT_CHANGEABLE';
    final public const ORDER_CUSTOMER_NOT_LOGGED_IN = 'CHECKOUT__ORDER_CUSTOMER_NOT_LOGGED_IN';
    final public const ORDER_CUSTOMER_ADDRESS_NOT_FOUND = 'CHECKOUT__ORDER_CUSTOMER_ADDRESS_NOT_FOUND';
    final public const ORDER_INVALID_ORDER_ADDRESS_MAPPING = 'CHECKOUT__INVALID_ORDER_ADDRESS_MAPPING';
    final public const ORDER_DELIVERY_WITHOUT_ADDRESS = 'CHECKOUT__DELIVERY_WITHOUT_ADDRESS';
    final public const CHECKOUT_GUEST_NOT_AUTHENTICATED = 'CHECKOUT__GUEST_NOT_AUTHENTICATED';
    final public const CHECKOUT_GUEST_WRONG_CREDENTIALS = 'CHECKOUT__GUEST_WRONG_CREDENTIALS';
    final public const CHECKOUT_INVALID_UUID = 'CHECKOUT__INVALID_UUID';
    final public const ASSOCIATION_NOT_FOUND = 'CHECKOUT__ORDER_ASSOCIATION_NOT_FOUND';
    final public const INVALID_REQUEST_PARAMETER_CODE = 'FRAMEWORK__INVALID_REQUEST_PARAMETER';
    final public const STATE_MACHINE_STATE_NOT_FOUND = 'SYSTEM__STATE_MACHINE_STATE_NOT_FOUND';

    public static function missingAssociation(string $association): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::ORDER_MISSING_ORDER_ASSOCIATION_CODE,
            'The required association "{{ association }}" is missing .',
            ['association' => $association]
        );
    }

    public static function orderDeliveryNotFound(string $id): self
    {
        return new self(
            Response::HTTP_NOT_FOUND,
            self::ORDER_ORDER_DELIVERY_NOT_FOUND_CODE,
            self::$couldNotFindMessage,
            ['entity' => 'order delivery', 'field' => 'id', 'value' => $id]
        );
    }

    public static function canNotRecalculateLiveVersion(string $orderId): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::ORDER_CAN_NOT_RECALCULATE_LIVE_VERSION_CODE,
            'Order with id {{ orderId }} can not be recalculated because it is in the live version. Please create a new version',
            ['orderId' => $orderId]
        );
    }

    public static function orderTransactionNotFound(string $id): self
    {
        return new self(
            Response::HTTP_NOT_FOUND,
            self::ORDER_ORDER_TRANSACTION_NOT_FOUND_CODE,
            self::$couldNotFindMessage,
            ['entity' => 'order transaction', 'field' => 'id', 'value' => $id]
        );
    }

    public static function paymentMethodNotAvailable(string $id): self
    {
        return new self(
            Response::HTTP_NOT_FOUND,
            self::ORDER_PAYMENT_METHOD_UNAVAILABLE,
            'The payment method with id {{ id }} is not available.',
            ['id' => $id]
        );
    }

    public static function orderAlreadyPaid(string $orderId): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::ORDER_ORDER_ALREADY_PAID_CODE,
            'Order with id "{{ orderId }}" was already paid and cannot be edited afterwards.',
            ['orderId' => $orderId]
        );
    }

    public static function paymentMethodNotChangeable(): self
    {
        return new self(
            Response::HTTP_FORBIDDEN,
            self::ORDER_PAYMENT_METHOD_NOT_CHANGEABLE_CODE,
            'Payment methods of order with current payment transaction type can not be changed.'
        );
    }

    public static function orderNotFound(string $orderId): self
    {
        return new self(
            Response::HTTP_NOT_FOUND,
            self::ORDER_ORDER_NOT_FOUND_CODE,
            self::$couldNotFindMessage,
            ['entity' => 'order', 'field' => 'id', 'value' => $orderId]
        );
    }

    public static function missingTransactions(string $orderId): self
    {
        return new self(
            Response::HTTP_NOT_FOUND,
            self::ORDER_MISSING_TRANSACTIONS_CODE,
            'Order with id {{ orderId }} has no transactions.',
            ['orderId' => $orderId]
        );
    }

    public static function missingOrderNumber(string $orderId): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::ORDER_MISSING_ORDER_NUMBER_CODE,
            'Order with id {{ orderId }} has no order number.',
            ['orderId' => $orderId]
        );
    }

    public static function customerNotLoggedIn(): self
    {
        return new self(
            Response::HTTP_FORBIDDEN,
            self::ORDER_CUSTOMER_NOT_LOGGED_IN,
            'Customer is not logged in.',
        );
    }

    public static function customerAuthThrottledException(int $waitTime, ?\Throwable $e = null): ShopwareHttpException
    {
        return new CustomerAuthThrottledException(
            $waitTime,
            $e
        );
    }

    public static function customerAddressNotFound(string $customerAddressId): self
    {
        return new self(
            Response::HTTP_NOT_FOUND,
            self::ORDER_CUSTOMER_ADDRESS_NOT_FOUND,
            'Customer address with id {{ customerAddressId }} not found.',
            ['customerAddressId' => $customerAddressId]
        );
    }

    public static function invalidOrderAddressMapping(string $reason = ''): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::ORDER_INVALID_ORDER_ADDRESS_MAPPING,
            'Invalid order address mapping provided. ' . $reason,
        );
    }

    public static function deliveryWithoutAddress(): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::ORDER_DELIVERY_WITHOUT_ADDRESS,
            'Delivery contains no shipping address',
        );
    }

    public static function orderCancelled(string $orderId): self
    {
        return new self(
            Response::HTTP_FORBIDDEN,
            self::ORDER_ORDER_CANCELLED_CODE,
            'Order with id "{{ orderId }}" was cancelled and cannot be edited afterwards.',
            ['orderId' => $orderId]
        );
    }

    /**
     * The {@see CustomerDeletedException} is a flow exception and should not be converted to a real domain exception
     */
    public static function orderCustomerDeleted(string $orderId): CustomerDeletedException
    {
        return new CustomerDeletedException($orderId);
    }

    public static function guestNotAuthenticated(): self
    {
        return new GuestNotAuthenticatedException();
    }

    public static function wrongGuestCredentials(): self
    {
        return new WrongGuestCredentialsException();
    }

    public static function invalidUuid(string $uuid): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::CHECKOUT_INVALID_UUID,
            'Invalid UUID provided: {{ uuid }}',
            ['uuid' => $uuid]
        );
    }

    /**
     * @deprecated tag:v6.8.0 - reason:return-type-change - Will return self
     */
    public static function associationNotFound(string $association): self|AssociationNotFoundException
    {
        if (!Feature::isActive('v6.8.0.0')) {
            return new AssociationNotFoundException($association);
        }

        return new self(
            Response::HTTP_NOT_FOUND,
            self::ASSOCIATION_NOT_FOUND,
            'Can not find association by name {{ association }}',
            ['association' => $association]
        );
    }

    public static function invalidRequestParameter(string $name): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::INVALID_REQUEST_PARAMETER_CODE,
            'The parameter "{{ parameter }}" is invalid.',
            ['parameter' => $name]
        );
    }

    public static function stateMachineStateNotFound(string $stateMachineName, string $technicalPlaceName): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::STATE_MACHINE_STATE_NOT_FOUND,
            'The place "{{ place }}" for state machine named "{{ stateMachine }}" was not found.',
            [
                'place' => $technicalPlaceName,
                'stateMachine' => $stateMachineName,
            ]
        );
    }
}
