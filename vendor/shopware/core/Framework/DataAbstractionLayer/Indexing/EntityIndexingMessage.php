<?php declare(strict_types=1);

namespace Shopware\Core\Framework\DataAbstractionLayer\Indexing;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;
use Shopware\Core\Framework\MessageQueue\DeduplicatableMessageInterface;
use Shopware\Core\Framework\Util\Hasher;

#[Package('framework')]
class EntityIndexingMessage implements AsyncMessageInterface, DeduplicatableMessageInterface
{
    protected string $indexer;

    private readonly Context $context;

    /**
     * @var array<string>
     */
    private array $skip = [];

    /**
     * @param array<string>|string $data
     * @param array{offset: int|null}|null $offset
     */
    public function __construct(
        protected array|string $data,
        protected ?array $offset = null,
        ?Context $context = null,
        public bool $forceQueue = false,
        public bool $isFullIndexing = false
    ) {
        $this->context = $context ?? Context::createDefaultContext();
    }

    /**
     * @return array<string>|string
     */
    public function getData(): array|string
    {
        return $this->data;
    }

    /**
     * @return array{offset: int|null}|null
     */
    public function getOffset(): ?array
    {
        return $this->offset;
    }

    /**
     * @internal This property is called by the indexer registry. The indexer name is stored in this message to identify the message handler in the queue worker
     */
    public function getIndexer(): string
    {
        return $this->indexer;
    }

    /**
     * @internal This property is called by the indexer registry. The indexer name is stored in this message to identify the message handler in the queue worker
     */
    public function setIndexer(string $indexer): void
    {
        $this->indexer = $indexer;
    }

    public function getContext(): Context
    {
        return $this->context;
    }

    public function forceQueue(): bool
    {
        return $this->forceQueue;
    }

    /**
     * @return array<string>
     */
    public function getSkip(): array
    {
        return $this->skip;
    }

    /**
     * @param array<string> $skip
     */
    public function setSkip(array $skip): void
    {
        $this->skip = \array_unique(\array_values($skip));
    }

    public function addSkip(string ...$skip): void
    {
        $this->skip = \array_unique(\array_merge($this->skip, \array_values($skip)));
    }

    public function allow(string $name): bool
    {
        return !\in_array($name, $this->getSkip(), true);
    }

    /**
     * @experimental stableVersion:v6.8.0 feature:DEDUPLICATABLE_MESSAGES
     */
    public function deduplicationId(): ?string
    {
        $data = $this->data;
        if (\is_array($data)) {
            sort($data);
        }

        $sortedSkip = $this->skip;
        sort($sortedSkip);

        $data = serialize([
            $this->indexer,
            $sortedSkip,
            $data,
            $this->offset,
            $this->context, // relying on __serialize() to skip extensions
            $this->forceQueue,
            $this->isFullIndexing,
        ]);

        return Hasher::hash($data);
    }
}
