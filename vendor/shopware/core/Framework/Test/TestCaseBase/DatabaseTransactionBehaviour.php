<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Test\TestCaseBase;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\ExpectationFailedException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Use if your test should be wrapped in a transaction
 */
trait DatabaseTransactionBehaviour
{
    public static ?string $lastTestCase = null;

    #[Before]
    public function startTransactionBefore(): void
    {
        if (static::$lastTestCase !== null) {
            throw new ExpectationFailedException(
                'The previous test case\'s transaction was not closed properly.
            This may affect following Tests in an unpredictable manner!
            Previous Test case: ' . (new \ReflectionClass($this))->getName() . '::' . static::$lastTestCase
            );
        }

        static::getContainer()
            ->get(Connection::class)
            ->beginTransaction();

        static::$lastTestCase = $this->nameWithDataSet();
    }

    #[After]
    public function stopTransactionAfter(): void
    {
        $connection = static::getContainer()
            ->get(Connection::class);

        if ($connection->getTransactionNestingLevel() !== 1) {
            throw new ExpectationFailedException(
                'Too many Nesting Levels.
                Probably one transaction was not closed properly.
                This may affect following Tests in an unpredictable manner!
                Current nesting level: "' . $connection->getTransactionNestingLevel() . '".'
            );
        }

        $connection->rollBack();

        if (static::$lastTestCase === $this->nameWithDataSet()) {
            static::$lastTestCase = null;
        }
    }

    abstract protected static function getContainer(): ContainerInterface;
}
