<?php declare(strict_types=1);

namespace Shopware\Core\Framework\App;

use Shopware\Core\Framework\App\Lifecycle\AbstractAppLifecycle;
use Shopware\Core\Framework\App\Lifecycle\AppLifecycleIterator;
use Shopware\Core\Framework\App\Lifecycle\Parameters\AppInstallParameters;
use Shopware\Core\Framework\App\Lifecycle\RefreshableAppDryRun;
use Shopware\Core\Framework\App\Manifest\Manifest;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal only for use by the app-system
 */
#[Package('framework')]
class AppService
{
    public function __construct(
        private readonly AppLifecycleIterator $appLifecycleIterator,
        private readonly AbstractAppLifecycle $appLifecycle
    ) {
    }

    /**
     * @param array<string> $installAppNames - Apps that should be installed
     *
     * @return list<array{manifest: Manifest, exception: \Exception}>
     */
    public function doRefreshApps(
        AppInstallParameters $parameters,
        Context $context,
        array $installAppNames = []
    ): array {
        return $this->appLifecycleIterator->iterateOverApps(
            $this->appLifecycle,
            $parameters,
            $context,
            $installAppNames
        );
    }

    public function getRefreshableAppInfo(Context $context): RefreshableAppDryRun
    {
        $appInfo = new RefreshableAppDryRun();

        $this->appLifecycleIterator->iterateOverApps(
            $appInfo,
            new AppInstallParameters(
                activate: false,
                acceptPermissions: false
            ),
            $context
        );

        return $appInfo;
    }
}
