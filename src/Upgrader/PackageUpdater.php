<?php

namespace Drupal\DrupalUpgrader\Upgrader;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Symfony\Component\Process\Process;

class PackageUpdater
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
    public function __construct(Composer $composer, IOInterface $io, VersionManager $versionManager, UpgradeLogger $logger, array $config)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->versionManager = $versionManager;
        $this->logger = $logger;
        $this->config = $config;
        $this->workingDir = getcwd();
    }

    /**
     * Updates dependencies in composer.json and runs composer update.
     */
    public function updateDependencies($targetVersion)
    {
        $this->logger->logSection(sprintf('Updating dependencies for Drupal %s', $targetVersion));
        
        if ($this->config['dry_run']) {
            $this->logger->log('DRY RUN: Would update dependencies to version ' . $targetVersion);
            return true;
        }
        
        $composerJson = new JsonFile('composer.json');
        $config = $composerJson->read();
        
        // Convert version to constraint
        $versionConstraint = '^' . $targetVersion;
        
        // Update core packages
        $corePackages = [
            'drupal/core-recommended',
            'drupal/core-composer-scaffold',
            'drupal/core-project-message',
            'drupal/core'
        ];
        
        // Force update core packages
        foreach ($corePackages as $package) {
            if (isset($config['require'][$package])) {
                $this->logger->log(sprintf('Setting %s to %s', $package, $versionConstraint));
                $config['require'][$package] = $versionConstraint;
            }
        }
        
        // Update contrib modules if enabled
        if ($this->config['upgrade_contrib_modules']) {
            foreach ($config['require'] as $package => $version) {
                if (strpos($package, 'drupal/') === 0 && !in_array($package, $corePackages)) {
                    $compatibleVersion = $this->versionManager->getCompatibleVersion($package, $targetVersion);
                    $this->logger->log(sprintf('Setting %s to %s', $package, $compatibleVersion));
                    $config['require'][$package] = $compatibleVersion;
                }
            }
        }
        
        $composerJson->write($config);
        $this->logger->log('Updated composer.json with new version constraints');
        
        // First run a full composer update to generate the lock file
        $this->logger->log('Running initial composer update...');
        $process = new Process(['composer', 'update', '--no-dev']);
        $process->setTimeout(3600); // Set timeout to 1 hour
        $process->setWorkingDirectory($this->workingDir);
        $process->run(function ($type, $buffer) {
            $this->logger->logVerbose($buffer, false);
        });
        
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Initial composer update failed: ' . $process->getErrorOutput());
        }
        
        // Now update core packages
        $this->logger->log('Running composer update for core packages...');
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
        
        $this->logger->log('Dependencies updated successfully');
        return true;
    }

    /**
     * Checks for pinned versions that might prevent upgrades.
     */
    public function checkPinnedVersions()
    {
        $this->logger->logSection('Checking for pinned versions');
        
        $composerJson = new JsonFile('composer.json');
        $config = $composerJson->read();
        $pinnedPackages = [];
        
        foreach ($config['require'] as $package => $version) {
            if (strpos($package, 'drupal/') === 0) {
                // Check for exact version constraints (e.g., 1.0.0 instead of ^1.0.0)
                if ($version[0] !== '^' && $version[0] !== '~' && strpos($version, '*') === false) {
                    $pinnedPackages[$package] = $version;
                }
            }
        }
        
        if (!empty($pinnedPackages)) {
            $this->logger->log('Found pinned package versions that may prevent automatic upgrades:');
            foreach ($pinnedPackages as $package => $version) {
                $this->logger->log(sprintf('  - %s: %s', $package, $version));
            }
            
            if ($this->config['auto_fix_dependencies']) {
                $this->logger->log('Automatically converting pinned versions to caret constraints...');
                
                if (!$this->config['dry_run']) {
                    foreach ($pinnedPackages as $package => $version) {
                        $config['require'][$package] = '^' . $version;
                        $this->logger->log(sprintf('  - Changed %s from %s to ^%s', $package, $version, $version));
                    }
                    
                    $composerJson->write($config);
                    $this->logger->log('Updated composer.json with flexible version constraints');
                } else {
                    $this->logger->log('DRY RUN: Would convert pinned versions to caret constraints');
                }
            } else {
                $this->logger->log('Consider enabling auto_fix_dependencies in configuration to automatically fix these issues.');
            }
        } else {
            $this->logger->log('No pinned package versions found.');
        }
        
        return $pinnedPackages;
    }
}