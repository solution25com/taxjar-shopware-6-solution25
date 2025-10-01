<?php declare(strict_types=1);

namespace Shopware\Core\System\SalesChannel;

use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerGroup\CustomerGroupEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Content\MeasurementSystem\MeasurementUnits;
use Shopware\Core\Content\MeasurementSystem\MeasurementUnitTypeEnum;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CashRoundingConfig;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Struct\StateAwareTrait;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\Context\LanguageInfo;
use Shopware\Core\System\Tax\TaxCollection;
use Symfony\Component\Lock\LockInterface;

#[Package('framework')]
class SalesChannelContext extends Struct
{
    use StateAwareTrait;

    /**
     * @var array<string, bool>
     */
    protected array $permissions = [];

    protected bool $permisionsLocked = false;

    protected ?string $imitatingUserId = null;

    protected MeasurementUnits $measurementSystem;

    /**
     * @internal
     */
    protected ?LockInterface $cartLock = null;

    /**
     * @internal
     *
     * @param array<string, array<string>> $areaRuleIds
     */
    public function __construct(
        protected Context $context,
        protected string $token,
        private ?string $domainId,
        protected SalesChannelEntity $salesChannel,
        protected CurrencyEntity $currency,
        protected CustomerGroupEntity $currentCustomerGroup,
        protected TaxCollection $taxRules,
        protected PaymentMethodEntity $paymentMethod,
        protected ShippingMethodEntity $shippingMethod,
        protected ShippingLocation $shippingLocation,
        protected ?CustomerEntity $customer,
        protected CashRoundingConfig $itemRounding,
        protected CashRoundingConfig $totalRounding,
        protected LanguageInfo $languageInfo,
        protected array $areaRuleIds = [],
        ?MeasurementUnits $measurementSystem = null,
    ) {
        $this->measurementSystem = $measurementSystem ?? new MeasurementUnits(
            MeasurementUnits::DEFAULT_MEASUREMENT_SYSTEM,
            [
                MeasurementUnitTypeEnum::LENGTH->value => MeasurementUnits::DEFAULT_LENGTH_UNIT,
                MeasurementUnitTypeEnum::WEIGHT->value => MeasurementUnits::DEFAULT_WEIGHT_UNIT,
            ]
        );
    }

    public function getCurrentCustomerGroup(): CustomerGroupEntity
    {
        return $this->currentCustomerGroup;
    }

    public function getCurrency(): CurrencyEntity
    {
        return $this->currency;
    }

    public function getSalesChannel(): SalesChannelEntity
    {
        return $this->salesChannel;
    }

    public function getTaxRules(): TaxCollection
    {
        return $this->taxRules;
    }

    /**
     * Get the tax rules depend on the customer billing address
     * respectively the shippingLocation if there is no customer
     */
    public function buildTaxRules(string $taxId): TaxRuleCollection
    {
        $tax = $this->taxRules->get($taxId);

        if ($tax?->getRules() === null) {
            throw SalesChannelException::taxNotFound($taxId);
        }

        $firstTaxRule = $tax->getRules()->first();

        if ($firstTaxRule) {
            // @codeCoverageIgnoreStart - This is covered randomly
            return new TaxRuleCollection([
                new TaxRule($firstTaxRule->getTaxRate(), 100),
            ]);
            // @codeCoverageIgnoreEnd
        }

        return new TaxRuleCollection([
            new TaxRule($tax->getTaxRate(), 100),
        ]);
    }

    public function getCustomer(): ?CustomerEntity
    {
        return $this->customer;
    }

    public function getPaymentMethod(): PaymentMethodEntity
    {
        return $this->paymentMethod;
    }

    public function getShippingMethod(): ShippingMethodEntity
    {
        return $this->shippingMethod;
    }

    public function getShippingLocation(): ShippingLocation
    {
        return $this->shippingLocation;
    }

    public function getContext(): Context
    {
        return $this->context;
    }

    /**
     * @return array<string>
     */
    public function getRuleIds(): array
    {
        return $this->context->getRuleIds();
    }

    /**
     * @param array<string> $ruleIds
     */
    public function setRuleIds(array $ruleIds): void
    {
        $this->context->setRuleIds($ruleIds);
    }

    /**
     * @internal
     *
     * @return array<string, array<string>>
     */
    public function getAreaRuleIds(): array
    {
        return $this->areaRuleIds;
    }

    /**
     * @internal
     *
     * @param array<string> $areas
     *
     * @return array<string>
     */
    public function getRuleIdsByAreas(array $areas): array
    {
        $ruleIds = [];

        foreach ($areas as $area) {
            if (empty($this->areaRuleIds[$area])) {
                continue;
            }

            $ruleIds = array_unique(array_merge($ruleIds, $this->areaRuleIds[$area]));
        }

        return array_values($ruleIds);
    }

    /**
     * @internal
     *
     * @param array<string, array<string>> $areaRuleIds
     */
    public function setAreaRuleIds(array $areaRuleIds): void
    {
        $this->areaRuleIds = $areaRuleIds;
    }

    public function lockRules(): void
    {
        $this->context->lockRules();
    }

    public function lockPermissions(): void
    {
        $this->permisionsLocked = true;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getTaxState(): string
    {
        return $this->context->getTaxState();
    }

    public function setTaxState(string $taxState): void
    {
        $this->context->setTaxState($taxState);
    }

    public function getTaxCalculationType(): string
    {
        return $this->salesChannel->getTaxCalculationType();
    }

    /**
     * @return array<string, bool>
     */
    public function getPermissions(): array
    {
        return $this->permissions;
    }

    /**
     * @param array<string, bool> $permissions
     */
    public function setPermissions(array $permissions): void
    {
        if ($this->permisionsLocked) {
            throw SalesChannelException::contextPermissionsLocked();
        }

        $this->permissions = array_filter($permissions);
    }

    public function getApiAlias(): string
    {
        return 'sales_channel_context';
    }

    public function hasPermission(string $permission): bool
    {
        return \array_key_exists($permission, $this->permissions) && $this->permissions[$permission];
    }

    public function getSalesChannelId(): string
    {
        return $this->salesChannel->getId();
    }

    public function addState(string ...$states): void
    {
        $this->context->addState(...$states);
    }

    public function removeState(string $state): void
    {
        $this->context->removeState($state);
    }

    public function hasState(string ...$states): bool
    {
        return $this->context->hasState(...$states);
    }

    /**
     * @return array<string>
     */
    public function getStates(): array
    {
        return $this->context->getStates();
    }

    public function getDomainId(): ?string
    {
        return $this->domainId;
    }

    public function setDomainId(?string $domainId): void
    {
        $this->domainId = $domainId;
    }

    /**
     * @return non-empty-list<string>
     */
    public function getLanguageIdChain(): array
    {
        return $this->context->getLanguageIdChain();
    }

    public function getLanguageId(): string
    {
        return $this->context->getLanguageId();
    }

    public function getVersionId(): string
    {
        return $this->context->getVersionId();
    }

    public function considerInheritance(): bool
    {
        return $this->context->considerInheritance();
    }

    public function getTotalRounding(): CashRoundingConfig
    {
        return $this->totalRounding;
    }

    public function setTotalRounding(CashRoundingConfig $totalRounding): void
    {
        $this->totalRounding = $totalRounding;
    }

    public function getItemRounding(): CashRoundingConfig
    {
        return $this->itemRounding;
    }

    public function setItemRounding(CashRoundingConfig $itemRounding): void
    {
        $this->itemRounding = $itemRounding;
    }

    public function getCurrencyId(): string
    {
        return $this->currency->getId();
    }

    public function ensureLoggedIn(bool $allowGuest = true): void
    {
        if ($this->customer === null) {
            throw SalesChannelException::customerNotLoggedIn();
        }

        if (!$allowGuest && $this->customer->getGuest()) {
            throw SalesChannelException::customerNotLoggedIn();
        }
    }

    public function getCustomerId(): ?string
    {
        return $this->customer?->getId();
    }

    public function getImitatingUserId(): ?string
    {
        return $this->imitatingUserId;
    }

    public function setImitatingUserId(?string $imitatingUserId): void
    {
        $this->imitatingUserId = $imitatingUserId;
    }

    /**
     * @template TReturn of mixed
     *
     * @param callable(SalesChannelContext): TReturn $callback
     *
     * @return TReturn the return value of the provided callback function
     */
    public function live(callable $callback): mixed
    {
        $before = $this->context;

        $this->context = $this->context->createWithVersionId(Defaults::LIVE_VERSION);

        $result = $callback($this);

        $this->context = $before;

        return $result;
    }

    /**
     * Executed the callback function with the given permissions set in the SalesChannelContext. If the
     * permissions are locked, the callback is called with the original permissions of the SalesChannelContext.
     *
     * @template TReturn of mixed
     *
     * @param array<string, bool> $permissions
     * @param callable(SalesChannelContext): TReturn $callback
     *
     * @return TReturn the return value of the provided callback function
     */
    public function withPermissions(array $permissions, callable $callback): mixed
    {
        if ($this->permisionsLocked) {
            return $callback($this);
        }

        $originalPermissions = $this->getPermissions();
        $permissions = array_merge($originalPermissions, $permissions);

        $this->setPermissions($permissions);

        $result = $callback($this);

        $this->setPermissions($originalPermissions);

        return $result;
    }

    public function getCountryId(): string
    {
        return $this->shippingLocation->getCountry()->getId();
    }

    public function getCustomerGroupId(): string
    {
        return $this->currentCustomerGroup->getId();
    }

    public function getLanguageInfo(): LanguageInfo
    {
        return $this->languageInfo;
    }

    public function setLanguageInfo(LanguageInfo $languageInfo): void
    {
        $this->languageInfo = $languageInfo;
    }

    public function getMeasurementSystem(): MeasurementUnits
    {
        return $this->measurementSystem;
    }

    public function setMeasurementSystem(MeasurementUnits $measurementSystem): void
    {
        $this->measurementSystem = $measurementSystem;
    }

    /**
     * @internal
     */
    public function getCartLock(): ?LockInterface
    {
        return $this->cartLock;
    }

    /**
     * @internal
     */
    public function setCartLock(?LockInterface $cartLock): void
    {
        $this->cartLock = $cartLock;
    }
}
