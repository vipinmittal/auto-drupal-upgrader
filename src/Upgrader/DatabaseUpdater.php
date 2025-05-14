<?php

namespace Drupal\DrupalUpgrader\Upgrader;

use Composer\IO\IOInterface;
use Symfony\Component\Process\Process;

class DatabaseUpdater
{
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
    public function __construct(IOInterface $io, UpgradeLogger $logger, array $config)
    {
        $this->io = $io;
        $this->logger = $logger;
        $this->config = $config;
        $this->workingDir = getcwd();
    }

    /**
     * Runs database updates using Drush.
     */
    public function runDatabaseUpdates()
    {
        $this->logger->logSection('Running database updates');
        
        if ($this->config['dry_run']) {
            $this->logger->log('DRY RUN: Would run database updates via Drush');
            return true;
        }
        
        // Run database updates
        $this->logger->log('Running drush updatedb...');
        $process = new Process(['vendor/bin/drush', 'updatedb', '-y']);
        $process->setWorkingDirectory($this->workingDir);
        $process->run(function ($type, $buffer) {
            $this->logger->logVerbose($buffer, false);
        });
        
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Database update failed: ' . $process->getErrorOutput());
        }
        
        // Run entity updates if available (Drupal 8.x/9.x)
        $this->logger->log('Running entity schema updates...');
        $process = new Process(['vendor/bin/drush', 'entity:updates', '-y']);
        $process->setWorkingDirectory($this->workingDir);
        $process->run(function ($type, $buffer) {
            $this->logger->logVerbose($buffer, false);
        });
        
        // Don't fail if entity:updates is not available
        
        // Clear caches
        $this->logger->log('Rebuilding caches...');
        $process = new Process(['vendor/bin/drush', 'cache:rebuild', '-y']);
        $process->setWorkingDirectory($this->workingDir);
        $process->run(function ($type, $buffer) {
            $this->logger->logVerbose($buffer, false);
        });
        
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Cache rebuild failed: ' . $process->getErrorOutput());
        }
        
        $this->logger->log('Database updates completed successfully');
        return true;
    }

    /**
     * Exports configuration if available.
     */
    public function exportConfig()
    {
        $this->logger->logSection('Exporting configuration');
        
        if ($this->config['dry_run']) {
            $this->logger->log('DRY RUN: Would export configuration via Drush');
            return true;
        }
        
        $process = new Process(['vendor/bin/drush', 'config:export', '-y']);
        $process->setWorkingDirectory($this->workingDir);
        $process->run(function ($type, $buffer) {
            $this->logger->logVerbose($buffer, false);
        });
        
        if (!$process->isSuccessful()) {
            $this->logger->log('Configuration export failed or not available. Continuing anyway.');
            return false;
        }
        
        $this->logger->log('Configuration exported successfully');
        return true;
    }
}