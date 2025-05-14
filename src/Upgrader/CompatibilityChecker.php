<?php

namespace Drupal\DrupalUpgrader\Upgrader;

use Composer\IO\IOInterface;
use Symfony\Component\Process\Process;

class CompatibilityChecker
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
     * Runs compatibility checks.
     */
    public function runCompatibilityChecks()
    {
        $this->logger->logSection('Running compatibility checks');
        $issues = [];
        
        // Run upgrade_status checks
        if ($this->config['upgrade_contrib_modules'] || $this->config['upgrade_custom_modules']) {
            $this->logger->log('Running upgrade_status checks...');
            $upgradeStatusIssues = $this->runUpgradeStatus();
            $issues = array_merge($issues, $upgradeStatusIssues);
        }
        
        // Run PHPStan checks
        $this->logger->log('Running PHPStan analysis...');
        $phpstanIssues = $this->runPhpStan();
        $issues = array_merge($issues, $phpstanIssues);
        
        return $issues;
    }

    /**
     * Runs upgrade_status module checks.
     */
    protected function runUpgradeStatus()
    {
        $issues = [];
        
        // Check if upgrade_status is installed
        $process = new Process(['vendor/bin/drush', 'pm:list', '--status=enabled', '--format=json']);
        $process->setWorkingDirectory($this->workingDir);
        $process->run();
        
        if (!$process->isSuccessful()) {
            $this->logger->log('Could not check for upgrade_status module. Skipping this check.');
            return $issues;
        }
        
        $enabledModules = json_decode($process->getOutput(), true);
        if (!isset($enabledModules['upgrade_status'])) {
            $this->logger->log('The upgrade_status module is not installed. Installing it temporarily...');
            
            if (!$this->config['dry_run']) {
                $process = new Process(['vendor/bin/drush', 'pm:install', 'upgrade_status', '-y']);
                $process->setWorkingDirectory($this->workingDir);
                $process->run(function ($type, $buffer) {
                    $this->logger->logVerbose($buffer, false);
                });
                
                if (!$process->isSuccessful()) {
                    $this->logger->log('Could not install upgrade_status module. Skipping this check.');
                    return $issues;
                }
            } else {
                $this->logger->log('DRY RUN: Would install upgrade_status module');
                return $issues;
            }
        }
        
        if ($this->config['dry_run']) {
            $this->logger->log('DRY RUN: Would run upgrade_status checks');
            return $issues;
        }
        
        $process = new Process([
            'vendor/bin/drush',
            'upgrade_status:analyze',
            '--all'
        ]);
        $process->setWorkingDirectory($this->workingDir);
        $process->run(function ($type, $buffer) {
            $this->logger->logVerbose($buffer, false);
        });
        
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

    /**
     * Runs PHPStan analysis.
     */
    protected function runPhpStan()
    {
        $issues = [];
        
        // Check if PHPStan is installed
        if (!file_exists($this->workingDir . '/vendor/bin/phpstan')) {
            $this->logger->log('PHPStan is not installed. Skipping this check.');
            return $issues;
        }
        
        if ($this->config['dry_run']) {
            $this->logger->log('DRY RUN: Would run PHPStan analysis');
            return $issues;
        }
        
        $phpstanConfig = sprintf('%s/phpstan.neon', $this->workingDir);
        if (!file_exists($phpstanConfig)) {
            $this->logger->log('PHPStan configuration not found. Creating a basic one...');
            
            // Create a basic PHPStan config if it doesn't exist
            $basicConfig = <<<EOT
parameters:
    level: {$this->config['phpstan_level']}
    paths:
        - web/modules/custom
    excludePaths:
        - vendor
        - web/core
        - web/modules/contrib
EOT;
            
            if (!$this->config['dry_run']) {
                file_put_contents($phpstanConfig, $basicConfig);
            } else {
                $this->logger->log('DRY RUN: Would create PHPStan configuration');
                return $issues;
            }
        }
        
        // Build the command with ignore paths
        $command = [
            'vendor/bin/phpstan',
            'analyse',
            '-l',
            $this->config['phpstan_level'],
            'web/modules/custom'
        ];
        
        // Add ignore paths if configured
        if (!empty($this->config['ignore_paths'])) {
            foreach ($this->config['ignore_paths'] as $path) {
                $command[] = '--exclude';
                $command[] = $path;
            }
        }
        
        $process = new Process($command);
        $process->setWorkingDirectory($this->workingDir);
        $process->setTimeout(300); // 5 minutes timeout for PHPStan
        $process->run(function ($type, $buffer) {
            $this->logger->logVerbose($buffer, false);
        });
        
        // PHPStan returns non-zero exit code when it finds errors, which is expected
        $output = $process->getOutput() . $process->getErrorOutput();
        
        // Parse PHPStan output and collect issues
        preg_match_all('/([^\n]+\.php):([0-9]+):(.+)/', $output, $matches);
        for ($i = 0; $i < count($matches[1]); $i++) {
            $issues[] = sprintf('%s:%s - %s', $matches[1][$i], $matches[2][$i], trim($matches[3][$i]));
        }
        
        return $issues;
    }
    
    /**
     * Displays compatibility issues.
     */
    public function displayCompatibilityIssues($issues)
    {
        if (empty($issues)) {
            $this->logger->logSuccess('No compatibility issues found!');
            return;
        }
        
        $this->logger->logSection('Compatibility Issues Found');
        foreach ($issues as $issue) {
            $this->logger->log('- ' . $issue);
        }
        
        $this->logger->log('');
        $this->logger->logWarning('Please review these issues before proceeding with the upgrade.');
    }
    
    /**
     * Checks if there are any critical compatibility issues that should block the upgrade.
     */
    public function hasCriticalIssues($issues)
    {
        // This is a simplified implementation. In a real-world scenario,
        // you might want to categorize issues by severity.
        $criticalCount = 0;
        
        foreach ($issues as $issue) {
            // Count issues that contain words indicating critical problems
            if (preg_match('/(critical|fatal|deprecated|removed|incompatible)/i', $issue)) {
                $criticalCount++;
            }
        }
        
        return $criticalCount > 0;
    }
}