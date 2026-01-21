<?php

declare(strict_types=1);

namespace solu1TaxJar\Core\Rule;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Content\Rule\RuleCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria as DalCriteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class RuleMatcherService
{
    private SystemConfigService $systemConfigService;
    private EntityRepository $ruleRepository;
    public function __construct(SystemConfigService $systemConfigService, EntityRepository $ruleRepository)
    {
        $this->systemConfigService = $systemConfigService;
        $this->ruleRepository = $ruleRepository;
    }

    public function matchesAny(string $configKey, Cart $cart, SalesChannelContext $salesChannelContext): bool
    {
        $configured = $this->systemConfigService->get(
            sprintf('solu1TaxJar.setting.%s', $configKey),
            $salesChannelContext->getSalesChannelId()
        );

        if (!$configured) {
            return false;
        }

        $ruleIds = [];
        if (\is_string($configured)) {
            $ruleIds = [$configured];
        } elseif (\is_array($configured)) {
            $ruleIds = array_values($configured);
        }

        $ruleIds = array_filter($ruleIds, static fn ($id) => \is_string($id) && Uuid::isValid($id));
        if (empty($ruleIds)) {
            return false;
        }

        $criteria = new DalCriteria($ruleIds);
        $criteria->addAssociation('conditions');
        $criteria->addFilter(new EqualsAnyFilter('id', $ruleIds));

        /** @var RuleCollection $rules */
        $rules = $this->ruleRepository->search($criteria, $salesChannelContext->getContext())->getEntities();
        if ($rules->count() === 0) {
            return false;
        }

        $matching = $rules->filterMatchingRules($cart, $salesChannelContext);

        return $matching->count() > 0;
    }
}
