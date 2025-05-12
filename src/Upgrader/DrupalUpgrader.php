<?php

namespace Drupal\DrupalUpgrader\Upgrader;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\Version\VersionParser;
use Symfony\Component\Process\Process;
use Composer\Json\JsonFile;

class DrupalUpgrader
{
    protected $composer;
    protected $io;
    protected $currentVersion;
    protected $targetVersion;
    protected $config;
    protected $workingDir;
    protected $versionParser;

    public function __construct(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->versionParser = new VersionParser();
        $this->workingDir = getcwd();
        $this->loadConfig();
    }

    protected function loadConfig()
    {
        $this->config = [
            'skip_compatibility_checks' => false,
            'auto_fix_dependencies' => true,
            'backup_before_upgrade' => true,
            'upgrade_contrib_modules' => true,
            'upgrade_custom_modules' => true,
            'phpstan_level' => 5,
            'ignore_paths' => ['web/modules/custom/legacy_module']
        ];

        // Load custom config if exists
        $configFile = $this->workingDir . '/drupal-upgrader.json';
        if (file_exists($configFile)) {
            $customConfig = json_decode(file_get_contents($configFile), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $this->config = array_merge($this->config, $customConfig);
            }
        }
    }

    public function checkCurrentVersion()
    {
        try {
            $packages = $this->composer->getRepositoryManager()->getLocalRepository()->getPackages();
            foreach ($packages as $package) {
                if ($package->getName() === 'drupal/core') {
                    $this->currentVersion = $package->getVersion();
                    break;
                }
            }

            if (!$this->currentVersion) {
                throw new \RuntimeException('Could not detect Drupal core version');
            }

            $this->io->write(sprintf('Current Drupal version: %s', $this->currentVersion));
        } catch (\Exception $e) {
            $this->io->writeError(sprintf('Error detecting Drupal version: %s', $e->getMessage()));
            throw $e;
        }
    }

    public function planUpgrade()
    {
        try {
            // Determine target version and upgrade path
            $majorVersion = $this->getMajorVersion($this->currentVersion);
            $upgradePath = $this->determineUpgradePath($majorVersion);

            if (empty($upgradePath)) {
                throw new \RuntimeException('No valid upgrade path found');
            }

            $this->targetVersion = end($upgradePath);
            $this->io->write(sprintf('Upgrade path: %s', implode(' → ', $upgradePath)));
        } catch (\Exception $e) {
            $this->io->writeError(sprintf('Error planning upgrade: %s', $e->getMessage()));
            throw $e;
        }
    }

    protected function determineUpgradePath($currentMajor)
    {
        $path = [];
        
        // Add intermediate versions if needed
        if ($currentMajor < 10) {
            $path[] = '9.5.0';
            $path[] = '10.0.0';
        } elseif ($currentMajor === 10) {
            $path[] = '10.1.0';
        }
        
        // Add final target version
        if ($currentMajor < 11) {
            $path[] = '11.0.0';
        }

        return $path;
    }

    public function executeUpgrade()
    {
        if (!$this->targetVersion) {
            return;
        }

        try {
            // Backup if enabled
            if ($this->config['backup_before_upgrade']) {
                $this->backupProject();
            }

            // Run pre-upgrade checks
            $this->io->write('Running pre-upgrade compatibility checks...');
            $compatibilityIssues = $this->runCompatibilityChecks();
            
            if (!empty($compatibilityIssues) && !$this->config['skip_compatibility_checks']) {
                $this->displayCompatibilityIssues($compatibilityIssues);
                if (!$this->io->askConfirmation('Compatibility issues found. Continue anyway? [y/N] ', false)) {
                    return;
                }
            }

            // Perform the upgrade
            foreach ($this->determineUpgradePath($this->getMajorVersion($this->currentVersion)) as $version) {
                $this->io->write(sprintf('\nUpgrading to Drupal %s', $version));
                $this->updateDependencies($version);
                $this->runPostUpdateChecks();
            }

            $this->io->write('\n<info>Upgrade completed successfully!</info>');
        } catch (\Exception $e) {
            $this->io->writeError(sprintf('\n<error>Error during upgrade: %s</error>', $e->getMessage()));
            throw $e;
        }
    }

    protected function runCompatibilityChecks()
    {
        $issues = [];

        // Run upgrade_status checks
        if ($this->config['upgrade_contrib_modules'] || $this->config['upgrade_custom_modules']) {
            $this->io->write('Running upgrade_status checks...');
            $upgradeStatusIssues = $this->runUpgradeStatus();
            $issues = array_merge($issues, $upgradeStatusIssues);
        }

        // Run PHPStan checks
        $this->io->write('Running PHPStan analysis...');
        $phpstanIssues = $this->runPhpStan();
        $issues = array_merge($issues, $phpstanIssues);

        return $issues;
    }

    protected function runUpgradeStatus()
    {
        $issues = [];
        $process = new Process([
            'vendor/bin/drush',
            'upgrade_status:analyze',
            '--all'
        ]);
        
        $process->run();
        if ($process->isSuccessful()) {
            $output = $process->getOutput();
            // Parse upgrade_status output and collect issues
            preg_match_all('/([^\n]+):\s+([0-9]+)\s+errors?/i', $output, $matches);
            for ($i = 0; $i < count($matches[1]); $i++) {
                $issues[] = sprintf('%s has %d error(s)', $matches[1][$i], $matches[2][$i]);
            }
        }

        return $issues;
    }

    protected function runPhpStan()
    {
        $issues = [];
        $phpstanConfig = sprintf('%s/phpstan.neon', $this->workingDir);
        
        $process = new Process([
            'vendor/bin/phpstan',
            'analyse',
            '-l',
            $this->config['phpstan_level'],
            'web/modules/custom'
        ]);

        $process->run();
        if (!$process->isSuccessful()) {
            $output = $process->getErrorOutput();
            // Parse PHPStan output and collect issues
            preg_match_all('/([^\n]+\.php):([0-9]+):(.+)/', $output, $matches);
            for ($i = 0; $i < count($matches[1]); $i++) {
                $issues[] = sprintf('%s:%s - %s', $matches[1][$i], $matches[2][$i], trim($matches[3][$i]));
            }
        }

        return $issues;
    }

    protected function updateDependencies($targetVersion)
    {
        $composerJson = new JsonFile('composer.json');
        $config = $composerJson->read();

        // Convert version to constraint
        $versionConstraint = '^' . $targetVersion;

        // Update core packages
        $corePackages = [
            'drupal/core-recommended',
            'drupal/core-composer-scaffold',
            'drupal/core-project-message'
        ];

        foreach ($corePackages as $package) {
            if (isset($config['require'][$package])) {
                $config['require'][$package] = $versionConstraint;
            }
        }

        // Update contrib modules if enabled
        if ($this->config['upgrade_contrib_modules']) {
            foreach ($config['require'] as $package => $version) {
                if (strpos($package, 'drupal/') === 0 && !in_array($package, $corePackages)) {
                    $config['require'][$package] = $this->getCompatibleVersion($package, $targetVersion);
                }
            }
        }

        $composerJson->write($config);

        // Run composer update
        $this->io->write('Running composer update...');
        $process = new Process(['composer', 'update', '--with-dependencies']);
        $process->setTimeout(3600); // Set timeout to 1 hour
        $process->run(function ($type, $buffer) {
            $this->io->write($buffer, false);
        });

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Composer update failed: ' . $process->getErrorOutput());
        }
    }

    protected function getCompatibleVersion($package, $version)
    {
        // Convert version number to major version constraint
        $major = $this->getMajorVersion($version);
        return "^$major.0";
    }

    protected function getMajorVersion($version)
    {
        // Remove the caret and any other constraints
        $version = trim(str_replace('^', '', $version));
        // Get only the first part of the version number
        $parts = explode('.', $version);
        return (int) $parts[0];
    }

    protected function backupProject()
    {
        $this->io->write('Creating backup...');
        $backupDir = sprintf('%s/backup_%s', dirname($this->workingDir), date('Y-m-d_H-i-s'));
        $process = new Process(['cp', '-r', $this->workingDir, $backupDir]);
        $process->run();

        if ($process->isSuccessful()) {
            $this->io->write(sprintf('Backup created at: %s', $backupDir));
        } else {
            throw new \RuntimeException('Failed to create backup: ' . $process->getErrorOutput());
        }
    }

    protected function displayCompatibilityIssues($issues)
    {
        $this->io->write('\n<error>Compatibility Issues Found:</error>');
        foreach ($issues as $issue) {
            $this->io->write(sprintf('- %s', $issue));
        }
        $this->io->write('');
    }

    protected function runPostUpdateChecks()
    {
        $this->io->write('Running post-update checks...');
        
        // Run database updates
        $process = new Process(['vendor/bin/drush', 'updatedb', '-y']);
        $process->run(function ($type, $buffer) {
            $this->io->write($buffer, false);
        });

        // Clear caches
        $process = new Process(['vendor/bin/drush', 'cache:rebuild', '-y']);
        $process->run(function ($type, $buffer) {
            $this->io->write($buffer, false);
        });
    }
}