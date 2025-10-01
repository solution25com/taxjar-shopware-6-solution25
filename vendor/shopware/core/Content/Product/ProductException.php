<?php declare(strict_types=1);

namespace Shopware\Core\Content\Product;

use Shopware\Core\Content\Product\Exception\ProductNotFoundException;
use Shopware\Core\Content\Product\Exception\ReviewNotActiveExeption;
use Shopware\Core\Content\Product\Exception\VariantNotFoundException;
use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\HttpException;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Routing\RoutingException;
use Shopware\Core\System\Currency\CurrencyEntity;
use Symfony\Component\HttpFoundation\Response;

#[Package('inventory')]
class ProductException extends HttpException
{
    public const PRODUCT_INVALID_CHEAPEST_PRICE_FACADE = 'PRODUCT_INVALID_CHEAPEST_PRICE_FACADE';
    public const PRODUCT_PROXY_MANIPULATION_NOT_ALLOWED_CODE = 'PRODUCT_PROXY_MANIPULATION_NOT_ALLOWED';
    public const PRODUCT_INVALID_PRICE_DEFINITION_CODE = 'PRODUCT_INVALID_PRICE_DEFINITION';
    public const PRODUCT_NOT_FOUND = 'PRODUCT_PRODUCT_NOT_FOUND';
    public const PRODUCT_VARIANT_NOT_FOUND = 'CONTENT__PRODUCT_VARIANT_NOT_FOUND';
    public const CATEGORY_NOT_FOUND = 'PRODUCT__CATEGORY_NOT_FOUND';
    public const SORTING_NOT_FOUND = 'PRODUCT_SORTING_NOT_FOUND';
    public const PRODUCT_CONFIGURATION_OPTION_ALREADY_EXISTS = 'PRODUCT_CONFIGURATION_OPTION_EXISTS_ALREADY';
    public const PRODUCT_INVALID_OPTIONS_PARAMETER = 'PRODUCT_INVALID_OPTIONS_PARAMETER';
    final public const PRODUCT_REVIEW_NOT_ACTIVE = 'PRODUCT__REVIEW_NOT_ACTIVE';
    final public const PRODUCT_ORIGINAL_ID_NOT_FOUND = 'PRODUCT__ORIGINAL_ID_NOT_FOUND';
    public const MISSING_REQUEST_PARAMETER_CODE = 'PRODUCT__MISSING_REQUEST_PARAMETER_CODE';

    public static function invalidCheapestPriceFacade(string $id): self
    {
        return new self(
            Response::HTTP_INTERNAL_SERVER_ERROR,
            self::PRODUCT_INVALID_CHEAPEST_PRICE_FACADE,
            'Cheapest price facade for product {{ id }} is invalid',
            ['id' => $id]
        );
    }

    public static function sortingNotFoundException(string $key): self
    {
        return new self(
            Response::HTTP_NOT_FOUND,
            self::SORTING_NOT_FOUND,
            self::$couldNotFindMessage,
            ['entity' => 'sorting', 'field' => 'key', 'value' => $key]
        );
    }

    public static function invalidPriceDefinition(): self
    {
        return new self(
            Response::HTTP_CONFLICT,
            self::PRODUCT_INVALID_PRICE_DEFINITION_CODE,
            'Provided price definition is invalid.'
        );
    }

    public static function proxyManipulationNotAllowed(mixed $property): self
    {
        return new self(
            Response::HTTP_INTERNAL_SERVER_ERROR,
            self::PRODUCT_PROXY_MANIPULATION_NOT_ALLOWED_CODE,
            'Manipulation of pricing proxy field {{ property }} is not allowed',
            ['property' => (string) $property]
        );
    }

    public static function categoryNotFound(string $categoryId): self
    {
        return new self(
            Response::HTTP_NOT_FOUND,
            self::CATEGORY_NOT_FOUND,
            self::$couldNotFindMessage,
            ['entity' => 'category', 'field' => 'id', 'value' => $categoryId]
        );
    }

    public static function configurationOptionAlreadyExists(): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::PRODUCT_CONFIGURATION_OPTION_ALREADY_EXISTS,
            'Configuration option already exists'
        );
    }

    public static function invalidOptionsParameter(): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::PRODUCT_INVALID_OPTIONS_PARAMETER,
            'The parameter options is invalid.'
        );
    }

    /**
     * @deprecated tag:v6.8.0 - reason:return-type-change - Will only return `self` in the future
     */
    public static function reviewNotActive(): self|ReviewNotActiveExeption
    {
        if (!Feature::isActive('v6.8.0.0')) {
            return new ReviewNotActiveExeption();
        }

        return new self(
            Response::HTTP_FORBIDDEN,
            self::PRODUCT_REVIEW_NOT_ACTIVE,
            'Reviews not activated'
        );
    }

    public static function originalIdNotFound(string $originalId): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::PRODUCT_ORIGINAL_ID_NOT_FOUND,
            'Cannot find originalId {{ originalId }} in listing mapping',
            ['originalId' => $originalId]
        );
    }

    public static function noPriceForCurrency(CurrencyEntity $currency): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            'PRODUCT__NO_PRICE_FOR_CURRENCY',
            'No price found for currency "{{ currency }}"',
            ['currency' => $currency->getName() ?? $currency->getShortName() ?? $currency->getIsoCode()]
        );
    }

    public static function productNotFound(string $productId): ProductNotFoundException
    {
        return new ProductNotFoundException($productId);
    }

    /**
     * @param array<string> $options
     */
    public static function variantNotFound(string $productId, array $options): VariantNotFoundException
    {
        return new VariantNotFoundException($productId, $options);
    }

    /**
     * @deprecated tag:v6.8.0 - reason:return-type-change - Will only return `self` in the future
     */
    public static function missingRequestParameter(string $name): self|RoutingException
    {
        if (!Feature::isActive('v6.8.0.0')) {
            return RoutingException::missingRequestParameter($name);
        }

        return new self(
            Response::HTTP_BAD_REQUEST,
            self::MISSING_REQUEST_PARAMETER_CODE,
            'Parameter "{{ parameterName }}" is missing.',
            ['parameterName' => $name]
        );
    }
}
