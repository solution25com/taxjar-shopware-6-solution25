<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Customer;

use Shopware\Core\Checkout\Customer\Exception\AddressNotFoundException;
use Shopware\Core\Checkout\Customer\Exception\BadCredentialsException;
use Shopware\Core\Checkout\Customer\Exception\CannotDeleteDefaultAddressException;
use Shopware\Core\Checkout\Customer\Exception\CustomerAlreadyConfirmedException;
use Shopware\Core\Checkout\Customer\Exception\CustomerAuthThrottledException;
use Shopware\Core\Checkout\Customer\Exception\CustomerNotFoundByHashException;
use Shopware\Core\Checkout\Customer\Exception\CustomerNotFoundByIdException;
use Shopware\Core\Checkout\Customer\Exception\CustomerNotFoundException;
use Shopware\Core\Checkout\Customer\Exception\CustomerOptinNotCompletedException;
use Shopware\Core\Checkout\Customer\Exception\CustomerRecoveryHashExpiredException;
use Shopware\Core\Checkout\Customer\Exception\CustomerWishlistNotFoundException;
use Shopware\Core\Checkout\Customer\Exception\DuplicateWishlistProductException;
use Shopware\Core\Checkout\Customer\Exception\InvalidImitateCustomerTokenException;
use Shopware\Core\Checkout\Customer\Exception\PasswordPoliciesUpdatedException;
use Shopware\Core\Checkout\Customer\Validation\Constraint\CustomerEmailUnique;
use Shopware\Core\Content\Product\Exception\ProductNotFoundException;
use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\HttpException;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Rule\Exception\UnsupportedOperatorException;
use Shopware\Core\Framework\Rule\Exception\UnsupportedValueException;
use Shopware\Core\Framework\ShopwareHttpException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Exception\MissingOptionsException;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

#[Package('checkout')]
class CustomerException extends HttpException
{
    public const CUSTOMERS_NOT_FOUND = 'CHECKOUT__CUSTOMERS_NOT_FOUND';
    public const CUSTOMER_NOT_FOUND = 'CHECKOUT__CUSTOMER_NOT_FOUND';
    public const CUSTOMER_GROUP_NOT_FOUND = 'CHECKOUT__CUSTOMER_GROUP_NOT_FOUND';
    public const CUSTOMER_GROUP_REQUEST_NOT_FOUND = 'CHECKOUT__CUSTOMER_GROUP_REQUEST_NOT_FOUND';
    public const CUSTOMER_NOT_LOGGED_IN = 'CHECKOUT__CUSTOMER_NOT_LOGGED_IN';
    public const LINE_ITEM_DOWNLOAD_FILE_NOT_FOUND = 'CHECKOUT__LINE_ITEM_DOWNLOAD_FILE_NOT_FOUND';
    public const CUSTOMER_IDS_PARAMETER_IS_MISSING = 'CHECKOUT__CUSTOMER_IDS_PARAMETER_IS_MISSING';
    public const PRODUCT_IDS_PARAMETER_IS_MISSING = 'CHECKOUT__PRODUCT_IDS_PARAMETER_IS_MISSING';
    public const CUSTOMER_ADDRESS_NOT_FOUND = 'CHECKOUT__CUSTOMER_ADDRESS_NOT_FOUND';
    public const CUSTOMER_AUTH_BAD_CREDENTIALS = 'CHECKOUT__CUSTOMER_AUTH_BAD_CREDENTIALS';
    /**
     * @deprecated tag:v6.8.0 - Will be removed without replacement
     */
    public const CUSTOMER_ADDRESS_IS_ACTIVE = 'CHECKOUT__CUSTOMER_ADDRESS_IS_ACTIVE';
    public const CUSTOMER_ADDRESS_IS_DEFAULT = 'CHECKOUT__CUSTOMER_ADDRESS_IS_DEFAULT';
    public const CUSTOMER_IS_ALREADY_CONFIRMED = 'CHECKOUT__CUSTOMER_IS_ALREADY_CONFIRMED';
    public const CUSTOMER_GROUP_REGISTRATION_NOT_FOUND = 'CHECKOUT__CUSTOMER_GROUP_REGISTRATION_NOT_FOUND';
    public const CUSTOMER_NOT_FOUND_BY_HASH = 'CHECKOUT__CUSTOMER_NOT_FOUND_BY_HASH';
    public const CUSTOMER_NOT_FOUND_BY_ID = 'CHECKOUT__CUSTOMER_NOT_FOUND_BY_ID';
    public const CUSTOMER_RECOVERY_HASH_EXPIRED = 'CHECKOUT__CUSTOMER_RECOVERY_HASH_EXPIRED';
    public const WISHLIST_IS_NOT_ACTIVATED = 'CHECKOUT__WISHLIST_IS_NOT_ACTIVATED';
    public const WISHLIST_NOT_FOUND = 'CHECKOUT__WISHLIST_NOT_FOUND';
    public const COUNTRY_NOT_FOUND = 'CHECKOUT__CUSTOMER_COUNTRY_NOT_FOUND';
    public const DUPLICATE_WISHLIST_PRODUCT = 'CHECKOUT__DUPLICATE_WISHLIST_PRODUCT';
    public const LEGACY_PASSWORD_ENCODER_NOT_FOUND = 'CHECKOUT__LEGACY_PASSWORD_ENCODER_NOT_FOUND';
    public const NO_HASH_PROVIDED = 'CHECKOUT__NO_HASH_PROVIDED';
    public const WISHLIST_PRODUCT_NOT_FOUND = 'CHECKOUT__WISHLIST_PRODUCT_NOT_FOUND';
    public const CUSTOMER_AUTH_THROTTLED = 'CHECKOUT__CUSTOMER_AUTH_THROTTLED';
    public const CUSTOMER_OPTIN_NOT_COMPLETED = 'CHECKOUT__CUSTOMER_OPTIN_NOT_COMPLETED';
    public const CUSTOMER_CHANGE_PAYMENT_ERROR = 'CHECKOUT__CUSTOMER_CHANGE_PAYMENT_METHOD_NOT_FOUND';
    public const CUSTOMER_GUEST_AUTH_INVALID = 'CHECKOUT__CUSTOMER_AUTH_INVALID';
    public const IMITATE_CUSTOMER_INVALID_TOKEN = 'CHECKOUT__IMITATE_CUSTOMER_INVALID_TOKEN';
    public const MISSING_ROUTE_ANNOTATION = 'CHECKOUT__MISSING_ROUTE_ANNOTATION';
    public const MISSING_ROUTE_SALES_CHANNEL = 'CHECKOUT__MISSING_ROUTE_SALES_CHANNEL';
    public const OPERATOR_NOT_SUPPORTED = 'CHECKOUT__CUSTOMER_RULE_OPERATOR_NOT_SUPPORTED';
    public const VALUE_NOT_SUPPORTED = 'CONTENT__RULE_VALUE_NOT_SUPPORTED';
    public const MISSING_REQUEST_PARAMETER_CODE = 'CONTENT__MISSING_REQUEST_PARAMETER_CODE';
    /**
     * @deprecated tag:v6.8.0 - Use MISSING_OPTION instead
     */
    public const MISSING_OPTIONS = 'CONTENT__MISSING_OPTIONS';
    public const UNEXPECTED_TYPE = 'CHECKOUT__UNEXPECTED_TYPE';
    public const MISSING_OPTION = 'CONTENT__MISSING_OPTION';
    public const INVALID_OPTION = 'CONTENT__INVALID_OPTION';
    public const REGISTERED_CUSTOMER_CANNOT_BE_CONVERTED = 'CHECKOUT__REGISTERED_CUSTOMER_CANNOT_BE_CONVERTED';

    public static function customerGroupNotFound(string $id): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::CUSTOMER_GROUP_NOT_FOUND,
            self::$couldNotFindMessage,
            ['entity' => 'customer group', 'field' => 'id', 'value' => $id]
        );
    }

    public static function groupRequestNotFound(string $id): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::CUSTOMER_GROUP_REQUEST_NOT_FOUND,
            'Group request for customer "{{ id }}" is not found',
            ['id' => $id]
        );
    }

    /**
     * @param string[] $ids
     */
    public static function customersNotFound(array $ids): self
    {
        return new self(
            Response::HTTP_NOT_FOUND,
            self::CUSTOMERS_NOT_FOUND,
            'These customers "{{ ids }}" are not found',
            ['ids' => implode(', ', $ids)]
        );
    }

    public static function customerNotLoggedIn(): self
    {
        return new self(
            Response::HTTP_FORBIDDEN,
            self::CUSTOMER_NOT_LOGGED_IN,
            'Customer is not logged in.',
        );
    }

    public static function downloadFileNotFound(string $downloadId): ShopwareHttpException
    {
        return new self(
            Response::HTTP_NOT_FOUND,
            self::LINE_ITEM_DOWNLOAD_FILE_NOT_FOUND,
            'Line item download file with id "{{ downloadId }}" not found.',
            ['downloadId' => $downloadId]
        );
    }

    public static function customerIdsParameterIsMissing(): ShopwareHttpException
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::CUSTOMER_IDS_PARAMETER_IS_MISSING,
            'Parameter "customerIds" is missing.',
        );
    }

    public static function unknownPaymentMethod(string $paymentMethodId): ShopwareHttpException
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::CUSTOMER_CHANGE_PAYMENT_ERROR,
            'Change Payment to method {{ paymentMethodId }} not possible.',
            ['paymentMethodId' => $paymentMethodId]
        );
    }

    public static function productIdsParameterIsMissing(): ShopwareHttpException
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::PRODUCT_IDS_PARAMETER_IS_MISSING,
            'Parameter "productIds" is missing.',
        );
    }

    public static function addressNotFound(string $id): AddressNotFoundException
    {
        return new AddressNotFoundException($id);
    }

    public static function countryNotFound(string $countryId): HttpException
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::COUNTRY_NOT_FOUND,
            'Country with id "{{ countryId }}" not found.',
            ['countryId' => $countryId]
        );
    }

    public static function badCredentials(): BadCredentialsException
    {
        return new BadCredentialsException();
    }

    /**
     * @deprecated tag:v6.8.0 - Will be removed without replacement
     */
    public static function cannotDeleteActiveAddress(string $id): ShopwareHttpException
    {
        Feature::triggerDeprecationOrThrow('v6.8.0.0', Feature::deprecatedMethodMessage(self::class, __FUNCTION__, 'v6.8.0.0'));

        return new self(
            Response::HTTP_BAD_REQUEST,
            self::CUSTOMER_ADDRESS_IS_ACTIVE,
            'Customer address with id "{{ addressId }}" is an active address and cannot be deleted.',
            ['addressId' => $id]
        );
    }

    public static function cannotDeleteDefaultAddress(string $id): CannotDeleteDefaultAddressException
    {
        return new CannotDeleteDefaultAddressException($id);
    }

    public static function customerAlreadyConfirmed(string $id): CustomerAlreadyConfirmedException
    {
        return new CustomerAlreadyConfirmedException($id);
    }

    public static function customerGroupRegistrationConfigurationNotFound(string $customerGroupId): ShopwareHttpException
    {
        return new self(
            Response::HTTP_NOT_FOUND,
            self::CUSTOMER_GROUP_REGISTRATION_NOT_FOUND,
            'Customer group registration for id {{ customerGroupId }} not found.',
            ['customerGroupId' => $customerGroupId]
        );
    }

    public static function customerNotFoundByHash(string $hash): CustomerNotFoundByHashException
    {
        return new CustomerNotFoundByHashException($hash);
    }

    public static function customerNotFoundByIdException(string $id): CustomerNotFoundByIdException
    {
        return new CustomerNotFoundByIdException($id);
    }

    public static function customerNotFound(string $email): CustomerNotFoundException
    {
        return new CustomerNotFoundException($email);
    }

    public static function customerRecoveryHashExpired(string $hash): CustomerRecoveryHashExpiredException
    {
        return new CustomerRecoveryHashExpiredException($hash);
    }

    public static function customerWishlistNotActivated(): ShopwareHttpException
    {
        return new self(
            Response::HTTP_FORBIDDEN,
            self::WISHLIST_IS_NOT_ACTIVATED,
            'Wishlist is not activated!'
        );
    }

    public static function customerWishlistNotFound(): CustomerWishlistNotFoundException
    {
        return new CustomerWishlistNotFoundException();
    }

    public static function duplicateWishlistProduct(): DuplicateWishlistProductException
    {
        return new DuplicateWishlistProductException();
    }

    public static function legacyPasswordEncoderNotFound(string $encoder): ShopwareHttpException
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::LEGACY_PASSWORD_ENCODER_NOT_FOUND,
            self::$couldNotFindMessage,
            ['entity' => 'encoder', 'field' => 'name', 'value' => $encoder]
        );
    }

    public static function noHashProvided(): ShopwareHttpException
    {
        return new self(
            Response::HTTP_NOT_FOUND,
            self::NO_HASH_PROVIDED,
            'The given hash is empty.'
        );
    }

    public static function wishlistProductNotFound(string $productId): ShopwareHttpException
    {
        return new self(
            Response::HTTP_NOT_FOUND,
            self::WISHLIST_PRODUCT_NOT_FOUND,
            self::$couldNotFindMessage,
            ['entity' => 'wishlist product', 'field' => 'id', 'value' => $productId]
        );
    }

    public static function customerOptinNotCompleted(string $id): CustomerOptinNotCompletedException
    {
        return new CustomerOptinNotCompletedException($id);
    }

    public static function customerAuthThrottledException(int $waitTime, ?\Throwable $e = null): CustomerAuthThrottledException
    {
        return new CustomerAuthThrottledException(
            $waitTime,
            $e
        );
    }

    public static function guestAccountInvalidAuth(): ShopwareHttpException
    {
        return new self(
            Response::HTTP_FORBIDDEN,
            self::CUSTOMER_GUEST_AUTH_INVALID,
            'Guest account is not allowed to login'
        );
    }

    public static function passwordPoliciesUpdated(): PasswordPoliciesUpdatedException
    {
        return new PasswordPoliciesUpdatedException();
    }

    public static function invalidImitationToken(string $token): InvalidImitateCustomerTokenException
    {
        return new InvalidImitateCustomerTokenException($token);
    }

    public static function missingRouteAnnotation(string $annotation, string $route): self
    {
        return new self(
            Response::HTTP_INTERNAL_SERVER_ERROR,
            self::MISSING_ROUTE_ANNOTATION,
            'Missing @{{ annotation }} annotation for route: {{ route }}',
            ['annotation' => $annotation, 'route' => $route]
        );
    }

    public static function missingRouteSalesChannel(string $route): self
    {
        return new self(
            Response::HTTP_INTERNAL_SERVER_ERROR,
            self::MISSING_ROUTE_SALES_CHANNEL,
            'Missing sales channel context for route {{ route }}',
            ['route' => $route]
        );
    }

    /**
     * @deprecated tag:v6.8.0 - reason:return-type-change - Will return self
     */
    public static function unsupportedOperator(string $operator, string $class): self|UnsupportedOperatorException
    {
        if (!Feature::isActive('v6.8.0.0')) {
            return new UnsupportedOperatorException($operator, $class);
        }

        return new self(
            Response::HTTP_BAD_REQUEST,
            self::OPERATOR_NOT_SUPPORTED,
            'Unsupported operator {{ operator }} in {{ class }}',
            ['operator' => $operator, 'class' => $class]
        );
    }

    /**
     * @deprecated tag:v6.8.0 - reason:return-type-change - Will return self
     */
    public static function unsupportedValue(string $type, string $class): self|UnsupportedValueException
    {
        if (!Feature::isActive('v6.8.0.0')) {
            return new UnsupportedValueException($type, $class);
        }

        return new self(
            Response::HTTP_BAD_REQUEST,
            self::VALUE_NOT_SUPPORTED,
            'Unsupported value of type {{ type }} in {{ class }}',
            ['type' => $type, 'class' => $class]
        );
    }

    public static function missingRequestParameter(string $name): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::MISSING_REQUEST_PARAMETER_CODE,
            'Parameter "{{ parameterName }}" is missing.',
            ['parameterName' => $name]
        );
    }

    /**
     * @deprecated tag:v6.8.0 - reason:return-type-change - Will return self
     */
    public static function productNotFound(string $productId): self|ProductNotFoundException
    {
        if (!Feature::isActive('v6.8.0.0')) {
            return new ProductNotFoundException($productId);
        }

        return new self(
            Response::HTTP_BAD_REQUEST,
            self::MISSING_REQUEST_PARAMETER_CODE,
            'Product for id {{ productId }} not found.',
            ['productId' => $productId]
        );
    }

    /**
     * @deprecated tag:v6.8.0 - reason:return-type-change - Will return self
     */
    public static function missingOption(string $option, string $constraint): self|MissingOptionsException
    {
        if (!Feature::isActive('v6.8.0.0')) {
            return new MissingOptionsException(\sprintf('Option "%s" must be given for constraint %s', $option, $constraint), ['context']);
        }

        return new self(
            Response::HTTP_BAD_REQUEST,
            self::MISSING_OPTION,
            'Option "{{ option }}" must be given for constraint {{ constraint }}',
            ['option' => $option, 'constraint' => $constraint]
        );
    }

    /**
     * @deprecated tag:v6.8.0 - reason:return-type-change - Will return self
     */
    public static function unexpectedType(Constraint $constraint, string $class): self|UnexpectedTypeException
    {
        if (!Feature::isActive('v6.8.0.0')) {
            return new UnexpectedTypeException($constraint, CustomerEmailUnique::class);
        }

        return new self(
            Response::HTTP_INTERNAL_SERVER_ERROR,
            self::UNEXPECTED_TYPE,
            'Expected argument of type "{{ expectedType }}", "{{ givenType }}" given',
            ['expectedType' => $class, 'givenType' => get_debug_type($constraint)]
        );
    }

    /**
     * @deprecated tag:v6.8.0 - reason:return-type-change - Will return self
     */
    public static function invalidOption(string $option, string $type, string $constraint): self|\InvalidArgumentException
    {
        if (!Feature::isActive('v6.8.0.0')) {
            return new \InvalidArgumentException(\sprintf('Option "%s" must be of type "%s" for constraint %s', $option, $type, $constraint));
        }

        return new self(
            Response::HTTP_BAD_REQUEST,
            self::INVALID_OPTION,
            'Option "{{ option }}" must be of type "{{ type }}" for constraint {{ constraint }}',
            ['option' => $option, 'type' => $type, 'constraint' => $constraint]
        );
    }

    public static function registeredCustomerCannotBeConverted(string $customerId): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::REGISTERED_CUSTOMER_CANNOT_BE_CONVERTED,
            'Customer with id "{{ customerId }}" is not a guest',
            ['customerId' => $customerId],
        );
    }
}
