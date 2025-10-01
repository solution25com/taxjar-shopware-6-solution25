<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Payment;

use Shopware\Core\Checkout\Customer\CustomerCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Payment\Aggregate\PaymentMethodTranslation\PaymentMethodTranslationCollection;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Content\Rule\RuleEntity;
use Shopware\Core\Framework\App\Aggregate\AppPaymentMethod\AppPaymentMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Contract\IdAware;
use Shopware\Core\Framework\DataAbstractionLayer\Contract\RuleIdAware;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCustomFieldsTrait;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Plugin\PluginEntity;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;

#[Package('checkout')]
class PaymentMethodEntity extends Entity implements IdAware, RuleIdAware
{
    use EntityCustomFieldsTrait;
    use EntityIdTrait;

    protected ?string $pluginId = null;

    protected string $handlerIdentifier;

    protected ?string $name = null;

    protected ?string $distinguishableName = null;

    protected ?string $description = null;

    protected int $position;

    protected bool $active;

    protected bool $afterOrderEnabled;

    protected ?PluginEntity $plugin = null;

    protected ?PaymentMethodTranslationCollection $translations = null;

    protected ?OrderTransactionCollection $orderTransactions = null;

    protected ?CustomerCollection $customers = null;

    protected ?SalesChannelCollection $salesChannelDefaultAssignments = null;

    protected ?SalesChannelCollection $salesChannels = null;

    protected ?RuleEntity $availabilityRule = null;

    protected ?string $availabilityRuleId = null;

    protected ?string $mediaId = null;

    protected ?MediaEntity $media = null;

    protected string $formattedHandlerIdentifier;

    protected ?string $shortName = null;

    protected string $technicalName;

    protected ?AppPaymentMethodEntity $appPaymentMethod = null;

    public function getPluginId(): ?string
    {
        return $this->pluginId;
    }

    public function setPluginId(?string $pluginId): void
    {
        $this->pluginId = $pluginId;
    }

    public function getHandlerIdentifier(): string
    {
        return $this->handlerIdentifier;
    }

    public function setHandlerIdentifier(string $handlerIdentifier): void
    {
        $this->handlerIdentifier = $handlerIdentifier;
    }

    public function setFormattedHandlerIdentifier(string $formattedHandlerIdentifier): void
    {
        $this->formattedHandlerIdentifier = $formattedHandlerIdentifier;
    }

    public function getFormattedHandlerIdentifier(): string
    {
        return $this->formattedHandlerIdentifier;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getDistinguishableName(): ?string
    {
        return $this->distinguishableName;
    }

    public function setDistinguishableName(?string $distinguishableName): void
    {
        $this->distinguishableName = $distinguishableName;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    public function getActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function getPlugin(): ?PluginEntity
    {
        return $this->plugin;
    }

    public function setPlugin(PluginEntity $plugin): void
    {
        $this->plugin = $plugin;
    }

    public function getTranslations(): ?PaymentMethodTranslationCollection
    {
        return $this->translations;
    }

    public function setTranslations(PaymentMethodTranslationCollection $translations): void
    {
        $this->translations = $translations;
    }

    public function getOrderTransactions(): ?OrderTransactionCollection
    {
        return $this->orderTransactions;
    }

    public function setOrderTransactions(OrderTransactionCollection $orderTransactions): void
    {
        $this->orderTransactions = $orderTransactions;
    }

    public function getCustomers(): ?CustomerCollection
    {
        return $this->customers;
    }

    public function setCustomers(CustomerCollection $customers): void
    {
        $this->customers = $customers;
    }

    public function getSalesChannelDefaultAssignments(): ?SalesChannelCollection
    {
        return $this->salesChannelDefaultAssignments;
    }

    public function setSalesChannelDefaultAssignments(SalesChannelCollection $salesChannelDefaultAssignments): void
    {
        $this->salesChannelDefaultAssignments = $salesChannelDefaultAssignments;
    }

    public function getSalesChannels(): ?SalesChannelCollection
    {
        return $this->salesChannels;
    }

    public function setSalesChannels(SalesChannelCollection $salesChannels): void
    {
        $this->salesChannels = $salesChannels;
    }

    public function getAvailabilityRule(): ?RuleEntity
    {
        return $this->availabilityRule;
    }

    public function setAvailabilityRule(?RuleEntity $availabilityRule): void
    {
        $this->availabilityRule = $availabilityRule;
    }

    public function getAvailabilityRuleId(): ?string
    {
        return $this->availabilityRuleId;
    }

    public function setAvailabilityRuleId(?string $availabilityRuleId): void
    {
        $this->availabilityRuleId = $availabilityRuleId;
    }

    public function getMediaId(): ?string
    {
        return $this->mediaId;
    }

    public function setMediaId(?string $mediaId): void
    {
        $this->mediaId = $mediaId;
    }

    public function getMedia(): ?MediaEntity
    {
        return $this->media;
    }

    public function setMedia(?MediaEntity $media): void
    {
        $this->media = $media;
    }

    public function getAfterOrderEnabled(): bool
    {
        return $this->afterOrderEnabled;
    }

    public function setAfterOrderEnabled(bool $afterOrderEnabled): void
    {
        $this->afterOrderEnabled = $afterOrderEnabled;
    }

    public function getShortName(): ?string
    {
        return $this->shortName;
    }

    public function setShortName(?string $shortName): void
    {
        $this->shortName = $shortName;
    }

    public function getTechnicalName(): string
    {
        return $this->technicalName;
    }

    public function setTechnicalName(string $technicalName): void
    {
        $this->technicalName = $technicalName;
    }

    public function getAppPaymentMethod(): ?AppPaymentMethodEntity
    {
        return $this->appPaymentMethod;
    }

    public function setAppPaymentMethod(?AppPaymentMethodEntity $appPaymentMethod): void
    {
        $this->appPaymentMethod = $appPaymentMethod;
    }
}
