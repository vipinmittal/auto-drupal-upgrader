<?php

namespace Drupal\DrupalUpgrader\Command;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Drupal\DrupalUpgrader\Upgrader\DrupalUpdater;

class AutoUpdateCommand extends BaseCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('drupal:auto-update')
            ->setDescription('Automatically update Drupal 10 core, modules and themes to latest compatible versions')
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Run in dry-run mode without making actual changes'
            )
            ->addOption(
                'skip-backup',
                null,
                InputOption::VALUE_NONE,
                'Skip creating a backup before updating'
            )
            ->addOption(
                'update-modules',
                null,
                InputOption::VALUE_NONE,
                'Update contributed modules to latest compatible versions'
            )
            ->addOption(
                'update-themes',
                null,
                InputOption::VALUE_NONE,
                'Update contributed themes to latest compatible versions'
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
            'skip_backup' => $input->getOption('skip-backup'),
            'update_modules' => $input->getOption('update-modules'),
            'update_themes' => $input->getOption('update-themes'),
        ];
        
        try {
            $updater = new DrupalUpdater($composer, $io, $options);
            $updater->createBackup();
            $updater->updateCore();
            
            if ($options['update_modules']) {
                $updater->updateModules();
            }
            
            if ($options['update_themes']) {
                $updater->updateThemes();
            }
            
            $updater->runDatabaseUpdates();
            $updater->rebuildCache();
            
            return 0; // Success
        } catch (\Exception $e) {
            $io->writeError(sprintf('<error>Error: %s</error>', $e->getMessage()));
            return 1; // Error
        }
    }
}