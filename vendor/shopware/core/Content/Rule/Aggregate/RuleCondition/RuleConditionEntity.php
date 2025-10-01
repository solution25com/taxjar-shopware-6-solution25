<?php declare(strict_types=1);

namespace Shopware\Core\Content\Rule\Aggregate\RuleCondition;

use Shopware\Core\Content\Rule\RuleEntity;
use Shopware\Core\Framework\App\Aggregate\AppScriptCondition\AppScriptConditionEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Contract\IdAware;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCustomFieldsTrait;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Shopware\Core\Framework\Log\Package;

#[Package('fundamentals@after-sales')]
class RuleConditionEntity extends Entity implements IdAware
{
    use EntityCustomFieldsTrait;
    use EntityIdTrait;

    protected string $type;

    protected string $ruleId;

    protected ?string $scriptId = null;

    protected ?string $parentId = null;

    /**
     * @var array<string, mixed>|null
     */
    protected ?array $value = null;

    protected ?RuleEntity $rule = null;

    protected ?AppScriptConditionEntity $appScriptCondition = null;

    protected ?RuleConditionCollection $children = null;

    protected ?RuleConditionEntity $parent = null;

    protected int $position;

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): void
    {
        $this->type = $type;
    }

    public function getRuleId(): string
    {
        return $this->ruleId;
    }

    public function setRuleId(string $ruleId): void
    {
        $this->ruleId = $ruleId;
    }

    public function getScriptId(): ?string
    {
        return $this->scriptId;
    }

    public function setScriptId(?string $scriptId): void
    {
        $this->scriptId = $scriptId;
    }

    public function getParentId(): ?string
    {
        return $this->parentId;
    }

    public function setParentId(?string $parentId): void
    {
        $this->parentId = $parentId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getValue(): ?array
    {
        return $this->value;
    }

    /**
     * @param array<string, mixed>|null $value
     */
    public function setValue(?array $value): void
    {
        $this->value = $value;
    }

    public function getRule(): ?RuleEntity
    {
        return $this->rule;
    }

    public function setRule(?RuleEntity $rule): void
    {
        $this->rule = $rule;
    }

    public function getAppScriptCondition(): ?AppScriptConditionEntity
    {
        return $this->appScriptCondition;
    }

    public function setAppScriptCondition(?AppScriptConditionEntity $appScriptCondition): void
    {
        $this->appScriptCondition = $appScriptCondition;
    }

    public function getChildren(): ?RuleConditionCollection
    {
        return $this->children;
    }

    public function setChildren(RuleConditionCollection $children): void
    {
        $this->children = $children;
    }

    public function getParent(): ?RuleConditionEntity
    {
        return $this->parent;
    }

    public function setParent(?RuleConditionEntity $parent): void
    {
        $this->parent = $parent;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }
}
