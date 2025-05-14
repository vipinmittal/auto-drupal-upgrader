<?php

namespace Drupal\DrupalUpgrader\Upgrader;

use Composer\Composer;
use Composer\IO\IOInterface;

class DrupalUpgrader
{
    /**
     * @var Composer
     */
    protected $composer;

    /**
     * @var IOInterface
     */
    protected $io;

    /**
     * @var VersionManager
     */
    protected $versionManager;

    /**
     * @var PackageUpdater
     */
    protected $packageUpdater;

    /**
     * @var DatabaseUpdater
     */
    protected $databaseUpdater;

    /**
     * @var CompatibilityChecker
     */
    protected $compatibilityChecker;

    /**
     * @var UpgradeLogger
     */
    protected $logger;

    /**
     * @var array
     */
    protected $config;

    /**
     * Constructor.
     */
    public function __construct(Composer $composer, IOInterface $io, array $options = [])
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->loadConfig($options);
        
        $this->logger = new UpgradeLogger($io, $this->config['verbose']);
        $this->versionManager = new VersionManager($composer, $io);
        $this->packageUpdater = new PackageUpdater($composer, $io, $this->versionManager, $this->logger, $this->config);
        $this->databaseUpdater = new DatabaseUpdater($io, $this->logger, $this->config);
        $this->compatibilityChecker = new CompatibilityChecker($io, $this->logger, $this->config);
    }

    /**
     * Loads configuration from drupal-upgrader.json and merges with options.
     */
    protected function loadConfig(array $options = [])
    {
        $this->config = [
            'skip_compatibility_checks' => false,
            'auto_fix_dependencies' => true,
            'upgrade_contrib_modules' => true,
            'upgrade_custom_modules' => true,
            'phpstan_level' => 5,
            'ignore_paths' => [],
            'dry_run' => false,
            'verbose' => false,
        ];

        // Load custom config if exists
        $configFile = getcwd() . '/drupal-upgrader.json';
        if (file_exists($configFile)) {
            $customConfig = json_decode(file_get_contents($configFile), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $this->config = array_merge($this->config, $customConfig);
            }
        }

        // Override with command line options
        $this->config = array_merge($this->config, $options);
    }

    /**
     * Checks the current Drupal version.
     */
    public function checkCurrentVersion()
    {
        return $this->versionManager->detectCurrentVersion();
    }

    /**
     * Plans the upgrade path.
     */
    public function planUpgrade()
    {
        $targetVersion = isset($this->config['target_version']) ? $this->config['target_version'] : null;
        $upgradePath = $this->versionManager->determineUpgradePath($targetVersion);
        
        $this->logger->logSection('Upgrade Plan');
        $this->logger->log(sprintf('Current version: %s', $this->versionManager->getCurrentVersion()));
        $this->logger->log(sprintf('Upgrade path: %s', implode(' → ', $upgradePath)));
        
        return $upgradePath;
    }

    /**
     * Executes the upgrade process.
     */
    public function executeUpgrade()
    {
        // Change this line from getUpgradePath() to determineUpgradePath()
        $upgradePath = $this->versionManager->determineUpgradePath();
        
        if (empty($upgradePath)) {
            throw new \RuntimeException('No upgrade path determined. Run planUpgrade() first.');
        }
        
        // Run pre-upgrade compatibility checks
        if (!$this->config['skip_compatibility_checks']) {
            $issues = $this->compatibilityChecker->runCompatibilityChecks();
            
            if (!empty($issues)) {
                $this->logger->logSection('Compatibility Issues Found');
                foreach ($issues as $issue) {
                    $this->logger->log('- ' . $issue);
                }
                
                if (!$this->io->askConfirmation('Compatibility issues found. Continue anyway? [y/N] ', false)) {
                    $this->logger->log('Upgrade aborted by user.');
                    return false;
                }
            }
        }
        
        // Perform the upgrade for each version in the path
        foreach ($upgradePath as $version) {
            $this->logger->logSection(sprintf('Upgrading to Drupal %s', $version));
            
            // Update dependencies
            $this->packageUpdater->updateDependencies($version);
            
            // Run database updates
            $this->databaseUpdater->runDatabaseUpdates();
            
            // Export configuration if needed
            if ($this->config['export_config']) {
                $this->databaseUpdater->exportConfig();
            }
            
            $this->logger->log(sprintf('Successfully upgraded to Drupal %s', $version));
        }
        
        $this->logger->logSection('Upgrade Completed Successfully');
        return true;
    }
}