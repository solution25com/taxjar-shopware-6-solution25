<?php declare(strict_types=1);

namespace Shopware\Core\Content\Flow\Exception;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\ShopwareHttpException;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 *
 * @deprecated tag:v6.8.0 - reason:remove-exception - Will be removed, use FlowException::customTriggerByNameNotFound() instead
 */
#[Package('after-sales')]
class CustomTriggerByNameNotFoundException extends ShopwareHttpException
{
    public function __construct(string $eventName)
    {
        parent::__construct(
            'The provided event name {{ eventName }} is invalid or uninstalled and no custom trigger could be found.',
            ['eventName' => $eventName]
        );
    }

    public function getErrorCode(): string
    {
        return 'ADMINISTRATION__CUSTOM_TRIGGER_BY_NAME_NOT_FOUND';
    }

    public function getStatusCode(): int
    {
        return Response::HTTP_NOT_FOUND;
    }
}
