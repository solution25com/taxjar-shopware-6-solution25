<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Plugin;

use Composer\InstalledVersions;
use Composer\IO\NullIO;
use Composer\Semver\Comparator;
use Psr\Cache\CacheItemPoolInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Migration\MigrationCollection;
use Shopware\Core\Framework\Migration\MigrationCollectionLoader;
use Shopware\Core\Framework\Migration\MigrationSource;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Composer\CommandExecutor;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Event\PluginPostActivateEvent;
use Shopware\Core\Framework\Plugin\Event\PluginPostDeactivateEvent;
use Shopware\Core\Framework\Plugin\Event\PluginPostDeactivationFailedEvent;
use Shopware\Core\Framework\Plugin\Event\PluginPostInstallEvent;
use Shopware\Core\Framework\Plugin\Event\PluginPostUninstallEvent;
use Shopware\Core\Framework\Plugin\Event\PluginPostUpdateEvent;
use Shopware\Core\Framework\Plugin\Event\PluginPreActivateEvent;
use Shopware\Core\Framework\Plugin\Event\PluginPreDeactivateEvent;
use Shopware\Core\Framework\Plugin\Event\PluginPreInstallEvent;
use Shopware\Core\Framework\Plugin\Event\PluginPreUninstallEvent;
use Shopware\Core\Framework\Plugin\Event\PluginPreUpdateEvent;
use Shopware\Core\Framework\Plugin\Exception\PluginHasActiveDependantsException;
use Shopware\Core\Framework\Plugin\Exception\PluginNotActivatedException;
use Shopware\Core\Framework\Plugin\Exception\PluginNotInstalledException;
use Shopware\Core\Framework\Plugin\KernelPluginLoader\KernelPluginLoader;
use Shopware\Core\Framework\Plugin\KernelPluginLoader\StaticKernelPluginLoader;
use Shopware\Core\Framework\Plugin\Requirement\Exception\RequirementStackException;
use Shopware\Core\Framework\Plugin\Requirement\RequirementsValidator;
use Shopware\Core\Framework\Plugin\Util\AssetService;
use Shopware\Core\Framework\Plugin\Util\VersionSanitizer;
use Shopware\Core\System\CustomEntity\Schema\CustomEntityPersister;
use Shopware\Core\System\CustomEntity\Schema\CustomEntitySchemaUpdater;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\EventListener\StopWorkerOnRestartSignalListener;

/**
 * @internal
 */
#[Package('framework')]
class PluginLifecycleService
{
    final public const STATE_SKIP_ASSET_BUILDING = 'skip-asset-building';

    /**
     * @var array{plugin: PluginEntity, context: Context}|null
     */
    private static ?array $pluginToBeDeleted = null;

    private static bool $registeredListener = false;

    /**
     * @param EntityRepository<PluginCollection> $pluginRepo
     */
    public function __construct(
        private readonly EntityRepository $pluginRepo,
        private EventDispatcherInterface $eventDispatcher,
        private readonly KernelPluginCollection $pluginCollection,
        private ContainerInterface $container,
        private readonly MigrationCollectionLoader $migrationLoader,
        private readonly AssetService $assetInstaller,
        private readonly CommandExecutor $executor,
        private readonly RequirementsValidator $requirementValidator,
        private readonly CacheItemPoolInterface $restartSignalCachePool,
        private readonly string $shopwareVersion,
        private readonly SystemConfigService $systemConfigService,
        private readonly CustomEntityPersister $customEntityPersister,
        private readonly CustomEntitySchemaUpdater $customEntitySchemaUpdater,
        private readonly PluginService $pluginService,
        private readonly VersionSanitizer $versionSanitizer,
        private readonly DefinitionInstanceRegistry $definitionRegistry,
    ) {
    }

    /**
     * @throws RequirementStackException
     */
    public function installPlugin(PluginEntity $plugin, Context $shopwareContext): InstallContext
    {
        $pluginData = [];
        $pluginBaseClass = $this->getPluginBaseClass($plugin->getBaseClass());
        $pluginVersion = $plugin->getVersion();

        $installContext = new InstallContext(
            $pluginBaseClass,
            $shopwareContext,
            $this->shopwareVersion,
            $pluginVersion,
            $this->createMigrationCollection($pluginBaseClass)
        );

        if ($plugin->getInstalledAt()) {
            return $installContext;
        }

        $didRunComposerRequire = false;

        if ($pluginBaseClass->executeComposerCommands()) {
            $didRunComposerRequire = $this->executeComposerRequireWhenNeeded($plugin, $pluginBaseClass, $pluginVersion, $shopwareContext);
        } else {
            $this->requirementValidator->validateRequirements($plugin, $shopwareContext, 'install');
        }

        try {
            $pluginData['id'] = $plugin->getId();

            // Makes sure the version is updated in the db after a re-installation
            $updateVersion = $plugin->getUpgradeVersion();
            if ($updateVersion !== null && $this->hasPluginUpdate($updateVersion, $pluginVersion)) {
                $pluginData['version'] = $updateVersion;
                $plugin->setVersion($updateVersion);
                $pluginData['upgradeVersion'] = null;
                $plugin->setUpgradeVersion(null);
                $upgradeDate = new \DateTime();
                $pluginData['upgradedAt'] = $upgradeDate->format(Defaults::STORAGE_DATE_TIME_FORMAT);
                $plugin->setUpgradedAt($upgradeDate);
            }

            $this->eventDispatcher->dispatch(new PluginPreInstallEvent($plugin, $installContext));

            $this->systemConfigService->savePluginConfiguration($pluginBaseClass, true);

            $pluginBaseClass->install($installContext);

            $this->runMigrations($installContext);

            $installDate = new \DateTime();
            $pluginData['installedAt'] = $installDate->format(Defaults::STORAGE_DATE_TIME_FORMAT);
            $plugin->setInstalledAt($installDate);

            $this->updatePluginData($pluginData, $shopwareContext);

            $pluginBaseClass->postInstall($installContext);

            $this->eventDispatcher->dispatch(new PluginPostInstallEvent($plugin, $installContext));
        } catch (\Throwable $e) {
            if ($didRunComposerRequire && $plugin->getComposerName() && !$this->container->getParameter('shopware.deployment.cluster_setup')) {
                $this->executor->remove($plugin->getComposerName(), $plugin->getName());
            }

            throw $e;
        }

        return $installContext;
    }

    /**
     * @throws PluginNotInstalledException
     */
    public function uninstallPlugin(
        PluginEntity $plugin,
        Context $shopwareContext,
        bool $keepUserData = false
    ): UninstallContext {
        if ($plugin->getInstalledAt() === null) {
            throw PluginException::notInstalled($plugin->getName());
        }

        if ($plugin->getActive()) {
            $this->deactivatePlugin($plugin, $shopwareContext);
        }

        $pluginBaseClassString = $plugin->getBaseClass();
        $pluginBaseClass = $this->getPluginBaseClass($pluginBaseClassString);

        $uninstallContext = new UninstallContext(
            $pluginBaseClass,
            $shopwareContext,
            $this->shopwareVersion,
            $plugin->getVersion(),
            $this->createMigrationCollection($pluginBaseClass),
            $keepUserData
        );
        $uninstallContext->setAutoMigrate(false);

        $this->eventDispatcher->dispatch(new PluginPreUninstallEvent($plugin, $uninstallContext));

        if (!$shopwareContext->hasState(self::STATE_SKIP_ASSET_BUILDING)) {
            $this->assetInstaller->removeAssetsOfBundle($pluginBaseClassString);
        }

        if (!$uninstallContext->keepUserData()) {
            // plugin->uninstall() will remove the tables etc of the plugin,
            // we drop the migrations before, so we can recover in case of errors by rerunning the migrations
            $pluginBaseClass->removeMigrations();
        }

        $pluginBaseClass->uninstall($uninstallContext);

        if (!$uninstallContext->keepUserData()) {
            $this->systemConfigService->deletePluginConfiguration($pluginBaseClass);
        }

        $pluginId = $plugin->getId();
        $this->updatePluginData(
            [
                'id' => $pluginId,
                'active' => false,
                'installedAt' => null,
            ],
            $shopwareContext
        );
        $plugin->setActive(false);
        $plugin->setInstalledAt(null);

        if (!$uninstallContext->keepUserData()) {
            $this->removeCustomEntities($plugin->getId());
        }

        if ($pluginBaseClass->executeComposerCommands()) {
            $this->executeComposerRemoveCommand($plugin, $shopwareContext);
        }

        $this->eventDispatcher->dispatch(new PluginPostUninstallEvent($plugin, $uninstallContext));

        return $uninstallContext;
    }

    /**
     * @throws RequirementStackException
     */
    public function updatePlugin(PluginEntity $plugin, Context $shopwareContext): UpdateContext
    {
        if ($plugin->getInstalledAt() === null) {
            throw PluginException::notInstalled($plugin->getName());
        }

        $pluginBaseClassString = $plugin->getBaseClass();
        $pluginBaseClass = $this->getPluginBaseClass($pluginBaseClassString);

        $updateContext = new UpdateContext(
            $pluginBaseClass,
            $shopwareContext,
            $this->shopwareVersion,
            $plugin->getVersion(),
            $this->createMigrationCollection($pluginBaseClass),
            $plugin->getUpgradeVersion() ?? $plugin->getVersion()
        );

        if ($pluginBaseClass->executeComposerCommands()) {
            $this->executeComposerRequireWhenNeeded($plugin, $pluginBaseClass, $updateContext->getUpdatePluginVersion(), $shopwareContext);
        } else {
            if ($plugin->getManagedByComposer() && $plugin->isLocatedInCustomDirectory()) {
                // If the plugin was previously managed by composer, but should no longer due to the update, we need to remove the composer dependency
                $this->executeComposerRemoveCommand($plugin, $shopwareContext);
            }
            $this->requirementValidator->validateRequirements($plugin, $shopwareContext, 'update');
        }

        $this->eventDispatcher->dispatch(new PluginPreUpdateEvent($plugin, $updateContext));

        $this->systemConfigService->savePluginConfiguration($pluginBaseClass);

        try {
            $pluginBaseClass->update($updateContext);
        } catch (\Throwable $updateException) {
            if ($plugin->getActive()) {
                try {
                    $this->deactivatePlugin($plugin, $shopwareContext);
                } catch (\Throwable) {
                    $this->updatePluginData(
                        [
                            'id' => $plugin->getId(),
                            'active' => false,
                        ],
                        $shopwareContext
                    );
                }
            }

            throw $updateException;
        }

        if ($plugin->getActive() && !$shopwareContext->hasState(self::STATE_SKIP_ASSET_BUILDING)) {
            $this->assetInstaller->copyAssets($pluginBaseClass);
        }

        $this->runMigrations($updateContext);

        $updateVersion = $updateContext->getUpdatePluginVersion();
        $updateDate = new \DateTime();
        $this->updatePluginData(
            [
                'id' => $plugin->getId(),
                'version' => $updateVersion,
                'upgradeVersion' => null,
                'upgradedAt' => $updateDate->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ],
            $shopwareContext
        );
        $plugin->setVersion($updateVersion);
        $plugin->setUpgradeVersion(null);
        $plugin->setUpgradedAt($updateDate);

        $pluginBaseClass->postUpdate($updateContext);

        $this->eventDispatcher->dispatch(new PluginPostUpdateEvent($plugin, $updateContext));

        return $updateContext;
    }

    /**
     * @throws PluginNotInstalledException
     */
    public function activatePlugin(PluginEntity $plugin, Context $shopwareContext, bool $reactivate = false): ActivateContext
    {
        if ($plugin->getInstalledAt() === null) {
            throw PluginException::notInstalled($plugin->getName());
        }

        $pluginBaseClassString = $plugin->getBaseClass();
        $pluginBaseClass = $this->getPluginBaseClass($pluginBaseClassString);

        $activateContext = new ActivateContext(
            $pluginBaseClass,
            $shopwareContext,
            $this->shopwareVersion,
            $plugin->getVersion(),
            $this->createMigrationCollection($pluginBaseClass)
        );

        if ($reactivate === false && $plugin->getActive()) {
            return $activateContext;
        }

        $this->requirementValidator->validateRequirements($plugin, $shopwareContext, 'activate');

        $this->eventDispatcher->dispatch(new PluginPreActivateEvent($plugin, $activateContext));

        $plugin->setActive(true);

        // only skip rebuild if plugin has overwritten rebuildContainer method and source is system source (CLI)
        if ($pluginBaseClass->rebuildContainer() || !$shopwareContext->getSource() instanceof SystemSource) {
            $this->rebuildContainerWithNewPluginState($plugin, $pluginBaseClass->getNamespace());
        }

        $pluginBaseClass = $this->getPluginInstance($pluginBaseClassString);
        $activateContext = new ActivateContext(
            $pluginBaseClass,
            $shopwareContext,
            $this->shopwareVersion,
            $plugin->getVersion(),
            $this->createMigrationCollection($pluginBaseClass)
        );
        $activateContext->setAutoMigrate(false);

        $pluginBaseClass->activate($activateContext);

        $this->runMigrations($activateContext);

        if (!$shopwareContext->hasState(self::STATE_SKIP_ASSET_BUILDING)) {
            $this->assetInstaller->copyAssets($pluginBaseClass);
        }

        $this->updatePluginData(
            [
                'id' => $plugin->getId(),
                'active' => true,
            ],
            $shopwareContext
        );

        $this->signalWorkerStopInOldCacheDir();

        $this->eventDispatcher->dispatch(new PluginPostActivateEvent($plugin, $activateContext));

        return $activateContext;
    }

    /**
     * @throws PluginNotInstalledException
     * @throws PluginNotActivatedException
     * @throws PluginHasActiveDependantsException
     */
    public function deactivatePlugin(PluginEntity $plugin, Context $shopwareContext): DeactivateContext
    {
        if ($plugin->getInstalledAt() === null) {
            throw PluginException::notInstalled($plugin->getName());
        }

        if ($plugin->getActive() === false) {
            throw PluginException::notActivated($plugin->getName());
        }

        $dependantPlugins = array_values($this->getEntities($this->pluginCollection->all(), $shopwareContext)->getEntities()->getElements());

        $dependants = $this->requirementValidator->resolveActiveDependants(
            $plugin,
            $dependantPlugins
        );

        if (\count($dependants) > 0) {
            throw PluginException::hasActiveDependants($plugin->getName(), $dependants);
        }

        $pluginBaseClassString = $plugin->getBaseClass();
        $pluginBaseClass = $this->getPluginInstance($pluginBaseClassString);

        $deactivateContext = new DeactivateContext(
            $pluginBaseClass,
            $shopwareContext,
            $this->shopwareVersion,
            $plugin->getVersion(),
            $this->createMigrationCollection($pluginBaseClass)
        );
        $deactivateContext->setAutoMigrate(false);

        $this->eventDispatcher->dispatch(new PluginPreDeactivateEvent($plugin, $deactivateContext));

        try {
            $pluginBaseClass->deactivate($deactivateContext);

            if (!$shopwareContext->hasState(self::STATE_SKIP_ASSET_BUILDING)) {
                $this->assetInstaller->removeAssetsOfBundle($plugin->getName());
            }

            $plugin->setActive(false);

            // only skip rebuild if plugin has overwritten rebuildContainer method and source is system source (CLI)
            if ($pluginBaseClass->rebuildContainer() || !$shopwareContext->getSource() instanceof SystemSource) {
                $this->rebuildContainerWithNewPluginState($plugin, $pluginBaseClass->getNamespace());
            }

            $this->updatePluginData(
                [
                    'id' => $plugin->getId(),
                    'active' => false,
                ],
                $shopwareContext
            );
        } catch (\Throwable $exception) {
            $activateContext = new ActivateContext(
                $pluginBaseClass,
                $shopwareContext,
                $this->shopwareVersion,
                $plugin->getVersion(),
                $this->createMigrationCollection($pluginBaseClass)
            );

            $this->eventDispatcher->dispatch(
                new PluginPostDeactivationFailedEvent(
                    $plugin,
                    $activateContext,
                    $exception
                )
            );

            throw $exception;
        }

        $this->signalWorkerStopInOldCacheDir();

        $this->eventDispatcher->dispatch(new PluginPostDeactivateEvent($plugin, $deactivateContext));

        return $deactivateContext;
    }

    /**
     * Only run composer remove as last thing in the request context,
     * as there might be some other event listeners that will break after the composer dependency is removed.
     *
     * This is not run on Kernel Terminate as this way we can give feedback to the user by letting the request fail,
     * if there is an issue with removing the composer dependency.
     */
    public function onResponse(): void
    {
        if (!self::$pluginToBeDeleted) {
            return;
        }

        $plugin = self::$pluginToBeDeleted['plugin'];
        $context = self::$pluginToBeDeleted['context'];
        self::$pluginToBeDeleted = null;

        $this->removePluginComposerDependency($plugin, $context);
    }

    private function removePluginComposerDependency(PluginEntity $plugin, Context $context): void
    {
        if ($this->container->getParameter('shopware.deployment.cluster_setup')) {
            return;
        }

        $pluginComposerName = $plugin->getComposerName();
        if ($pluginComposerName === null) {
            throw PluginException::composerJsonInvalid(
                $plugin->getPath() . '/composer.json',
                ['No name defined in composer.json']
            );
        }

        $this->executor->remove($pluginComposerName, $plugin->getName());

        // running composer require may have consequences for other plugins, when they are required by the plugin being uninstalled
        $this->pluginService->refreshPlugins($context, new NullIO());
    }

    private function removeCustomEntities(string $pluginId): void
    {
        $this->customEntityPersister->update([], PluginEntity::class, $pluginId);
        $this->customEntitySchemaUpdater->update();
    }

    private function getPluginBaseClass(string $pluginBaseClassString): Plugin
    {
        $baseClass = $this->pluginCollection->get($pluginBaseClassString);

        if ($baseClass === null) {
            throw PluginException::baseClassNotFound($pluginBaseClassString);
        }

        // set container because the plugin has not been initialized yet and therefore has no container set
        $baseClass->setContainer($this->container);

        return $baseClass;
    }

    private function createMigrationCollection(Plugin $pluginBaseClass): MigrationCollection
    {
        $migrationPath = str_replace(
            '\\',
            '/',
            $pluginBaseClass->getPath() . str_replace(
                $pluginBaseClass->getNamespace(),
                '',
                $pluginBaseClass->getMigrationNamespace()
            )
        );

        if (!is_dir($migrationPath)) {
            return $this->migrationLoader->collect('null');
        }

        $this->migrationLoader->addSource(new MigrationSource($pluginBaseClass->getName(), [
            $migrationPath => $pluginBaseClass->getMigrationNamespace(),
        ]));

        $collection = $this->migrationLoader
            ->collect($pluginBaseClass->getName());

        $collection->sync();

        return $collection;
    }

    private function runMigrations(InstallContext $context): void
    {
        if (!$context->isAutoMigrate()) {
            return;
        }

        $context->getMigrationCollection()->migrateInPlace();
    }

    private function hasPluginUpdate(string $updateVersion, string $currentVersion): bool
    {
        return version_compare($updateVersion, $currentVersion, '>');
    }

    /**
     * @param array<string, mixed|null> $pluginData
     */
    private function updatePluginData(array $pluginData, Context $context): void
    {
        $this->pluginRepo->update([$pluginData], $context);
    }

    private function rebuildContainerWithNewPluginState(PluginEntity $plugin, string $pluginNamespace): void
    {
        $kernel = $this->container->get('kernel');

        $pluginDir = $kernel->getContainer()->getParameter('kernel.plugin_dir');
        if (!\is_string($pluginDir)) {
            throw PluginException::invalidContainerParameter('kernel.plugin_dir', 'string');
        }

        $pluginLoader = $this->container->get(KernelPluginLoader::class);

        $plugins = $pluginLoader->getPluginInfos();
        foreach ($plugins as $i => $pluginData) {
            if ($pluginData['baseClass'] === $plugin->getBaseClass()) {
                $plugins[$i]['active'] = $plugin->getActive();
            }
        }

        if (!$plugin->getActive()) {
            $this->clearEntityExtensions($pluginNamespace);
        }

        /*
         * Reboot kernel with $plugin active=true.
         *
         * All other Requests won't have this plugin active until it's updated in the db
         */
        $tmpStaticPluginLoader = new StaticKernelPluginLoader($pluginLoader->getClassLoader(), $pluginDir, $plugins);
        $kernel->reboot(null, $tmpStaticPluginLoader);

        try {
            $newContainer = $kernel->getContainer();
        } catch (\LogicException) {
            // If symfony throws an exception when calling getContainer on a not booted kernel and catch it here
            throw PluginException::failedKernelReboot();
        }

        $this->container = $newContainer;
        $this->eventDispatcher = $newContainer->get('event_dispatcher');
    }

    private function clearEntityExtensions(string $pluginNamespace): void
    {
        if ($pluginNamespace === '') {
            return;
        }

        $definitions = $this->definitionRegistry->getDefinitions();
        foreach ($definitions as $definition) {
            $definition->removeExtensions($pluginNamespace);
        }
    }

    private function getPluginInstance(string $pluginBaseClassString): Plugin
    {
        if ($this->container->has($pluginBaseClassString)) {
            $containerPlugin = $this->container->get($pluginBaseClassString);
            if (!$containerPlugin instanceof Plugin) {
                throw PluginException::wrongBaseClass($pluginBaseClassString);
            }

            return $containerPlugin;
        }

        return $this->getPluginBaseClass($pluginBaseClassString);
    }

    private function signalWorkerStopInOldCacheDir(): void
    {
        $cacheItem = $this->restartSignalCachePool->getItem(StopWorkerOnRestartSignalListener::RESTART_REQUESTED_TIMESTAMP_KEY);
        $cacheItem->set(microtime(true));
        $this->restartSignalCachePool->save($cacheItem);
    }

    /**
     * Takes plugin base classes and returns the corresponding entities.
     *
     * @param Plugin[] $plugins
     *
     * @return EntitySearchResult<PluginCollection>
     */
    private function getEntities(array $plugins, Context $context): EntitySearchResult
    {
        $names = array_map(static fn (Plugin $plugin) => $plugin->getName(), $plugins);

        return $this->pluginRepo->search(
            (new Criteria())->addFilter(new EqualsAnyFilter('name', $names)),
            $context
        );
    }

    private function executeComposerRequireWhenNeeded(PluginEntity $plugin, Plugin $pluginBaseClass, string $pluginVersion, Context $shopwareContext): bool
    {
        if ($this->container->getParameter('shopware.deployment.cluster_setup')) {
            return false;
        }

        $pluginComposerName = $plugin->getComposerName();
        if ($pluginComposerName === null) {
            throw PluginException::composerJsonInvalid(
                $pluginBaseClass->getPath() . '/composer.json',
                ['No name defined in composer.json']
            );
        }

        try {
            $installedVersion = InstalledVersions::getVersion($pluginComposerName);
        } catch (\OutOfBoundsException) {
            // plugin is not installed using composer yet
            $installedVersion = null;
        }

        if ($installedVersion !== null) {
            $sanitizedVersion = $this->versionSanitizer->sanitizePluginVersion($installedVersion);

            if (Comparator::equalTo($sanitizedVersion, $pluginVersion)) {
                // plugin was already required at build time, no need to do so again at runtime
                return false;
            }
        }

        $this->executor->require($pluginComposerName . ':' . $pluginVersion, $plugin->getName());

        // running composer require may have consequences for other plugins, when they are required by the plugin being installed
        $this->pluginService->refreshPlugins($shopwareContext, new NullIO());

        return true;
    }

    private function executeComposerRemoveCommand(PluginEntity $plugin, Context $shopwareContext): void
    {
        if (\PHP_SAPI === 'cli') {
            // only remove the plugin composer dependency directly when running in CLI
            // otherwise do it async in kernel.response
            $this->removePluginComposerDependency($plugin, $shopwareContext);
        /* @codeCoverageIgnoreStart -> code path can not be executed in unit tests as SAPI will always be CLI */
        } else {
            self::$pluginToBeDeleted = [
                'plugin' => $plugin,
                'context' => $shopwareContext,
            ];

            if (!self::$registeredListener) {
                $this->eventDispatcher->addListener(KernelEvents::RESPONSE, $this->onResponse(...), \PHP_INT_MAX);
                self::$registeredListener = true;
            }
        }
        /* @codeCoverageIgnoreEnd */
    }
}
