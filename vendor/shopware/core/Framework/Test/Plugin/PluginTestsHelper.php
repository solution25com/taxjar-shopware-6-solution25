<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Test\Plugin;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\KernelPluginCollection;
use Shopware\Core\Framework\Plugin\KernelPluginLoader\KernelPluginLoader;
use Shopware\Core\Framework\Plugin\PluginCollection;
use Shopware\Core\Framework\Plugin\PluginService;
use Shopware\Core\Framework\Plugin\Util\PluginFinder;
use Shopware\Core\Framework\Plugin\Util\VersionSanitizer;
use Shopware\Core\System\Language\LanguageCollection;
use SwagTestPlugin\SwagTestPlugin;
use Symfony\Component\DependencyInjection\ContainerInterface;

trait PluginTestsHelper
{
    /**
     * @param EntityRepository<PluginCollection> $pluginRepo
     * @param EntityRepository<LanguageCollection> $languageRepo
     */
    protected function createPluginService(
        string $pluginDir,
        string $projectDir,
        EntityRepository $pluginRepo,
        EntityRepository $languageRepo,
        PluginFinder $pluginFinder
    ): PluginService {
        return new PluginService(
            $pluginDir,
            $projectDir,
            $pluginRepo,
            $languageRepo,
            $pluginFinder,
            new VersionSanitizer()
        );
    }

    /**
     * @param EntityRepository<PluginCollection> $pluginRepo
     */
    protected function createPlugin(
        EntityRepository $pluginRepo,
        Context $context,
        string $version = SwagTestPlugin::PLUGIN_VERSION,
        ?string $installedAt = null
    ): void {
        $pluginRepo->create(
            [
                [
                    'baseClass' => SwagTestPlugin::class,
                    'name' => 'SwagTestPlugin',
                    'version' => $version,
                    'label' => SwagTestPlugin::PLUGIN_LABEL,
                    'installedAt' => $installedAt,
                    'active' => false,
                    'autoload' => [],
                ],
            ],
            $context
        );
    }

    abstract protected static function getContainer(): ContainerInterface;

    private function addTestPluginToKernel(string $testPluginBaseDir, string $pluginName, bool $active = false): void
    {
        require_once $testPluginBaseDir . '/src/' . $pluginName . '.php';

        $class = '\\' . $pluginName . '\\' . $pluginName;
        $plugin = new $class($active, $testPluginBaseDir);
        static::assertInstanceOf(Plugin::class, $plugin);
        static::getContainer()->get(KernelPluginCollection::class)->add($plugin);

        static::getContainer()->get(KernelPluginLoader::class)->getPluginInstances()->add($plugin);
    }
}
