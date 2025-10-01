<?php declare(strict_types=1);

namespace Shopware\Core\Profiling\Subscriber;

use Shopware\Core\Framework\Adapter\Cache\CacheTagCollector;
use Shopware\Core\Framework\Adapter\Cache\Event\AddCacheTagEvent;
use Shopware\Core\Framework\Log\Package;
use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * @internal
 */
#[Package('framework')]
class CacheTagCollectorSubscriber extends AbstractDataCollector implements EventSubscriberInterface, LateDataCollectorInterface
{
    /**
     * [uri => [tag => [caller => count]]]
     *
     * @var array<string, array<string, array<string, int>>>
     */
    public static array $tags = [];

    public function __construct(
        private readonly RequestStack $stack,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AddCacheTagEvent::class => 'add',
        ];
    }

    public function reset(): void
    {
    }

    /**
     * @return array<string, array<string, array<string, int>>>
     */
    public function getData(): array
    {
        \assert(\is_array($this->data));

        return $this->data;
    }

    public function getTotal(): int
    {
        return array_sum(array_map('count', $this->getData()));
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
    }

    public function lateCollect(): void
    {
        $this->data = $this->buildTags();
    }

    public static function getTemplate(): string
    {
        return '@Profiling/Collector/http_cache_tags.html.twig';
    }

    public function add(AddCacheTagEvent $event): void
    {
        $caller = $this->getCaller();

        $uri = CacheTagCollector::uri($this->stack->getCurrentRequest());

        if (!isset(self::$tags[$uri])) {
            self::$tags[$uri] = [];
        }

        foreach ($event->tags as $tag) {
            if (!isset(self::$tags[$uri][$tag])) {
                self::$tags[$uri][$tag] = [];
            }

            if (!isset(self::$tags[$tag][$caller])) {
                self::$tags[$uri][$tag][$caller] = 0;
            }

            ++self::$tags[$uri][$tag][$caller];
        }
    }

    private function getCaller(): string
    {
        $source = debug_backtrace();

        // remove this function, listener function and wrapped listener
        array_shift($source);
        array_shift($source);
        array_shift($source);
        foreach ($source as $index => $element) {
            $class = $element['class'] ?? '';
            \assert(class_exists($class));

            if ($class === CacheTagCollector::class) {
                continue;
            }

            $instance = new \ReflectionClass($class);
            // skip dispatcher chain
            if ($instance->implementsInterface(EventDispatcherInterface::class)) {
                continue;
            }

            $before = $source[$index + 1];

            return $this->implode($element) . ' | ' . $this->implode($before);
        }

        return CacheTagCollector::INVALID_URI;
    }

    /**
     * @param array<string, mixed> $caller
     */
    private function implode(array $caller): string
    {
        if (!\array_key_exists('class', $caller)) {
            return CacheTagCollector::INVALID_URI;
        }
        if (!\array_key_exists('function', $caller)) {
            return CacheTagCollector::INVALID_URI;
        }
        $class = explode('\\', $caller['class']);
        $class = array_pop($class);

        return $class . '::' . $caller['function'];
    }

    /**
     * @return array<string, array<string, array<string, int>>>
     */
    private function buildTags(): array
    {
        $tags = self::$tags;

        if (!isset($tags[CacheTagCollector::INVALID_URI])) {
            return $tags;
        }

        $uris = array_keys($tags);

        if (\count($uris) <= 1) {
            return $tags;
        }

        $tagsWithoutValidUri = $tags[CacheTagCollector::INVALID_URI];
        unset($tags[CacheTagCollector::INVALID_URI]);

        $firstValidUri = $uris[1];

        foreach ($tagsWithoutValidUri as $tag => $callerCountArray) {
            foreach ($callerCountArray as $caller => $count) {
                if (!isset($tags[$firstValidUri][$tag][$caller])) {
                    $tags[$firstValidUri][$tag][$caller] = 0;
                }

                $tags[$firstValidUri][$tag][$caller] += $count;
            }
        }

        return $tags;
    }
}
