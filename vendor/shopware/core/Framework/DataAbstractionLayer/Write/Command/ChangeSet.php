<?php declare(strict_types=1);

namespace Shopware\Core\Framework\DataAbstractionLayer\Write\Command;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Struct\Struct;

#[Package('framework')]
class ChangeSet extends Struct
{
    /**
     * @var array<string, mixed>
     */
    protected array $state = [];

    /**
     * @var array<string, mixed>
     */
    protected array $after = [];

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $payload
     */
    public function __construct(
        array $state,
        array $payload,
        protected bool $isDelete
    ) {
        $this->state = $state;

        // calculate changes
        $changes = array_intersect_key($payload, $state);

        // validate data types
        foreach ($changes as $property => $after) {
            $before = (string) $state[$property];
            $string = (string) $after;
            if ($string === $before) {
                continue;
            }
            $this->after[$property] = $after;
        }
    }

    /**
     * @return array|mixed|string|null
     */
    public function getBefore(?string $property)
    {
        if ($property) {
            return $this->state[$property] ?? null;
        }

        return $this->state;
    }

    /**
     * @return array|mixed|string|null
     */
    public function getAfter(?string $property)
    {
        if ($property) {
            return $this->after[$property] ?? null;
        }

        return $this->after;
    }

    public function hasChanged(string $property): bool
    {
        return \array_key_exists($property, $this->after) || $this->isDelete;
    }

    public function merge(ChangeSet $changeSet): void
    {
        $this->after = array_merge($this->after, $changeSet->after);
        $this->state = array_merge($this->state, $changeSet->state);
        $this->isDelete = $this->isDelete || $changeSet->isDelete;
    }

    public function getApiAlias(): string
    {
        return 'dal_change_set';
    }
}
