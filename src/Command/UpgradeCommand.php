<?php

namespace Drupal\DrupalUpgrader\Command;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Drupal\DrupalUpgrader\Upgrader\DrupalUpgrader;

class UpgradeCommand extends BaseCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('drupal:upgrade')
            ->setDescription('Automatically upgrade Drupal core and modules')
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Run in dry-run mode without making actual changes'
            )
            ->addOption(
                'target-version',
                null,
                InputOption::VALUE_REQUIRED,
                'Target Drupal version (e.g., 10, 11)'
            )
            ->addOption(
                'skip-compatibility',
                null,
                InputOption::VALUE_NONE,
                'Skip compatibility checks'
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $composer = $this->getComposer();
        $io = $this->getIO();
        
        // Parse command options
        $options = [
            'dry_run' => $input->getOption('dry-run'),
            'skip_compatibility_checks' => $input->getOption('skip-compatibility'),
            'target_version' => $input->getOption('target-version'),
        ];
        
        try {
            $upgrader = new DrupalUpgrader($composer, $io, $options);
            $upgrader->checkCurrentVersion();
            $upgrader->planUpgrade();
            $upgrader->executeUpgrade();
            
            return 0; // Success
        } catch (\Exception $e) {
            $io->writeError(sprintf('<error>Error: %s</error>', $e->getMessage()));
            return 1; // Error
        }
    }
}