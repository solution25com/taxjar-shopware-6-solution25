<?php declare(strict_types=1);

namespace Shopware\Core\Content\ImportExport\Processing\Pipe;

use Shopware\Core\Content\ImportExport\Aggregate\ImportExportLog\ImportExportLogEntity;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('fundamentals@after-sales')]
abstract class AbstractPipeFactory
{
    abstract public function create(ImportExportLogEntity $logEntity): AbstractPipe;

    abstract public function supports(ImportExportLogEntity $logEntity): bool;
}
