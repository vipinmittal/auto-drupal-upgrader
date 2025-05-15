<?php

namespace Drupal\DrupalUpgrader\Upgrader;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Symfony\Component\Process\Process;

class DrupalUpdater
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
     * @var UpgradeLogger
     */
    protected $logger;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var string
     */
    protected $workingDir;

    /**
     * Constructor.
     */
    public function __construct(Composer $composer, IOInterface $io, array $options = [])
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->config = $options;
        $this->logger = new UpgradeLogger($io, true);
        $this->workingDir = getcwd();
    }

    /**
     * Creates a backup before updating.
     */
    public function createBackup()
    {
        if ($this->config['skip_backup']) {
            $this->logger->log('Skipping backup as requested');
            return;
        }

        $this->logger->logSection('Creating backup');
        
        if ($this->config['dry_run']) {
            $this->logger->log('DRY RUN: Would create backup');
            return;
        }
        
        // Create backup directory
        $backupDir = $this->workingDir . '/backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        $timestamp = date('Y-m-d_H-i-s');
        $backupFile = $backupDir . '/drupal_backup_' . $timestamp . '.tar.gz';
        
        // Create backup using tar
        $process = new Process([
            'tar', 
            '-czf', 
            $backupFile, 
            '--exclude=backups', 
            '--exclude=vendor', 
            '--exclude=node_modules', 
            '.'
        ]);
        
        $process->setWorkingDirectory($this->workingDir);
        $process->setTimeout(3600); // 1 hour timeout
        $process->run(function ($type, $buffer) {
            $this->logger->logVerbose($buffer, false);
        });
        
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Backup creation failed: ' . $process->getErrorOutput());
        }
        
        $this->logger->logSuccess('Backup created successfully: ' . $backupFile);
    }

    /**
     * Updates Drupal core to the latest version.
     */
    public function updateCore()
    {
        $this->logger->logSection('Updating Drupal core');
        
        if ($this->config['dry_run']) {
            $this->logger->log('DRY RUN: Would update Drupal core');
            return;
        }
        
        // Get current Drupal version
        $packages = $this->composer->getRepositoryManager()->getLocalRepository()->getPackages();
        $currentVersion = null;
        
        foreach ($packages as $package) {
            if ($package->getName() === 'drupal/core-recommended' || $package->getName() === 'drupal/core') {
                $currentVersion = $package->getVersion();
                break;
            }
        }
        
        if (!$currentVersion) {
            throw new \RuntimeException('Could not detect Drupal core version');
        }
        
        $this->logger->log('Current Drupal version: ' . $currentVersion);
        
        // Update composer.json
        $composerJson = new JsonFile('composer.json');
        $config = $composerJson->read();
        
        // Core packages to update
        $corePackages = [
            'drupal/core-recommended',
            'drupal/core-composer-scaffold',
            'drupal/core-project-message',
            'drupal/core'
        ];
        
        // Update core packages to latest 10.x
        foreach ($corePackages as $package) {
            if (isset($config['require'][$package])) {
                $this->logger->log(sprintf('Setting %s to ^10', $package));
                $config['require'][$package] = '^10';
            }
        }
        
        $composerJson->write($config);
        $this->logger->log('Updated composer.json with new version constraints');
        
        // Run composer update for core packages
        $updateCommand = array_merge(
            ['composer', 'update'],
            $corePackages,
            ['--with-dependencies', '--prefer-dist', '--no-dev']
        );
        
        $process = new Process($updateCommand);
        $process->setTimeout(3600);
        $process->setWorkingDirectory($this->workingDir);
        $process->run(function ($type, $buffer) {
            $this->logger->logVerbose($buffer, false);
        });
        
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Composer update failed: ' . $process->getErrorOutput());
        }
        
        $this->logger->logSuccess('Drupal core updated successfully');
    }

    /**
     * Updates contributed modules to latest compatible versions.
     */
    public function updateModules()
    {
        $this->logger->logSection('Updating contributed modules');
        
        if ($this->config['dry_run']) {
            $this->logger->log('DRY RUN: Would update contributed modules');
            return;
        }
        
        // Get list of installed modules
        $process = new Process(['vendor/bin/drush', 'pm:list', '--type=module', '--status=enabled', '--format=json']);
        $process->setWorkingDirectory($this->workingDir);
        $process->run();
        
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Could not get list of modules: ' . $process->getErrorOutput());
        }
        
        $modules = json_decode($process->getOutput(), true);
        if (empty($modules)) {
            $this->logger->log('No modules found to update');
            return;
        }
        
        // Update composer.json
        $composerJson = new JsonFile('composer.json');
        $config = $composerJson->read();
        
        $modulesToUpdate = [];
        
        foreach ($modules as $name => $module) {
            // Skip core modules
            if (strpos($name, 'core_') === 0 || $module['package'] === 'Core') {
                continue;
            }
            
            $packageName = 'drupal/' . $name;
            if (isset($config['require'][$packageName])) {
                $this->logger->log('Will update ' . $packageName);
                $modulesToUpdate[] = $packageName;
            }
        }
        
        if (empty($modulesToUpdate)) {
            $this->logger->log('No contributed modules found in composer.json');
            return;
        }
        
        // Run composer update for modules
        $updateCommand = array_merge(
            ['composer', 'update'],
            $modulesToUpdate,
            ['--with-dependencies', '--prefer-dist', '--no-dev']
        );
        
        $process = new Process($updateCommand);
        $process->setTimeout(3600);
        $process->setWorkingDirectory($this->workingDir);
        $process->run(function ($type, $buffer) {
            $this->logger->logVerbose($buffer, false);
        });
        
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Module update failed: ' . $process->getErrorOutput());
        }
        
        $this->logger->logSuccess('Contributed modules updated successfully');
    }

    /**
     * Updates contributed themes to latest compatible versions.
     */
    public function updateThemes()
    {
        $this->logger->logSection('Updating contributed themes');
        
        if ($this->config['dry_run']) {
            $this->logger->log('DRY RUN: Would update contributed themes');
            return;
        }
        
        // Get list of installed themes
        $process = new Process(['vendor/bin/drush', 'pm:list', '--type=theme', '--status=enabled', '--format=json']);
        $process->setWorkingDirectory($this->workingDir);
        $process->run();
        
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Could not get list of themes: ' . $process->getErrorOutput());
        }
        
        $themes = json_decode($process->getOutput(), true);
        if (empty($themes)) {
            $this->logger->log('No themes found to update');
            return;
        }
        
        // Update composer.json
        $composerJson = new JsonFile('composer.json');
        $config = $composerJson->read();
        
        $themesToUpdate = [];
        
        foreach ($themes as $name => $theme) {
            // Skip core themes
            if (strpos($name, 'core_') === 0 || $theme['package'] === 'Core') {
                continue;
            }
            
            $packageName = 'drupal/' . $name;
            if (isset($config['require'][$packageName])) {
                $this->logger->log('Will update ' . $packageName);
                $themesToUpdate[] = $packageName;
            }
        }
        
        if (empty($themesToUpdate)) {
            $this->logger->log('No contributed themes found in composer.json');
            return;
        }
        
        // Run composer update for themes
        $updateCommand = array_merge(
            ['composer', 'update'],
            $themesToUpdate,
            ['--with-dependencies', '--prefer-dist', '--no-dev']
        );
        
        $process = new Process($updateCommand);
        $process->setTimeout(3600);
        $process->setWorkingDirectory($this->workingDir);
        $process->run(function ($type, $buffer) {
            $this->logger->logVerbose($buffer, false);
        });
        
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Theme update failed: ' . $process->getErrorOutput());
        }
        
        $this->logger->logSuccess('Contributed themes updated successfully');
    }

    /**
     * Runs database updates.
     */
    public function runDatabaseUpdates()
    {
        $this->logger->logSection('Running database updates');
        
        if ($this->config['dry_run']) {
            $this->logger->log('DRY RUN: Would run database updates');
            return;
        }
        
        $process = new Process(['vendor/bin/drush', 'updatedb', '-y']);
        $process->setWorkingDirectory($this->workingDir);
        $process->setTimeout(1800); // 30 minutes timeout
        $process->run(function ($type, $buffer) {
            $this->logger->logVerbose($buffer, false);
        });
        
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Database update failed: ' . $process->getErrorOutput());
        }
        
        $this->logger->logSuccess('Database updates completed successfully');
    }

    /**
     * Rebuilds cache.
     */
    public function rebuildCache()
    {
        $this->logger->logSection('Rebuilding cache');
        
        if ($this->config['dry_run']) {
            $this->logger->log('DRY RUN: Would rebuild cache');
            return;
        }
        
        $process = new Process(['vendor/bin/drush', 'cache:rebuild', '-y']);
        $process->setWorkingDirectory($this->workingDir);
        $process->setTimeout(600); // 10 minutes timeout
        $process->run(function ($type, $buffer) {
            $this->logger->logVerbose($buffer, false);
        });
        
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Cache rebuild failed: ' . $process->getErrorOutput());
        }
        
        $this->logger->logSuccess('Cache rebuilt successfully');
    }
}