<?php

namespace Drupal\UpgradeAutomation;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Symfony\Component\Process\Process;
use Symfony\Component\Console\Output\ConsoleOutput;

class Plugin implements PluginInterface, EventSubscriberInterface
{
    protected $composer;
    protected $io;
    protected $output;

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->output = new ConsoleOutput();
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
    }

    public static function getSubscribedEvents()
    {
        return [
            'post-update-cmd' => 'handlePostUpdate',
            'post-install-cmd' => 'handlePostUpdate',
        ];
    }

    public function handlePostUpdate(Event $event)
    {
        $this->output->writeln('<info>Starting Drupal upgrade automation process...</info>');
        
        // Get current Drupal version
        $currentVersion = $this->getDrupalVersion();
        $this->output->writeln("<info>Current Drupal version: $currentVersion</info>");

        // Perform upgrade steps based on current version
        if (version_compare($currentVersion, '9.0.0', '>=') && version_compare($currentVersion, '10.0.0', '<')) {
            $this->upgradeToDrupal10();
        } elseif (version_compare($currentVersion, '10.0.0', '>=') && version_compare($currentVersion, '11.0.0', '<')) {
            $this->upgradeToDrupal11();
        }
    }

    protected function getDrupalVersion()
    {
        $process = new Process(['drush', 'pm:list', '--type=core', '--format=json']);
        $process->run();
        $output = json_decode($process->getOutput(), true);
        return $output['drupal']['version'] ?? '0.0.0';
    }

    protected function upgradeToDrupal10()
    {
        $this->output->writeln('<info>Starting upgrade to Drupal 10...</info>');

        // Update composer.json to require Drupal 10
        $this->updateComposerRequirements('^10.0');

        // Run composer update
        $this->runComposerUpdate();

        // Run database updates
        $this->runDatabaseUpdates();

        $this->output->writeln('<info>Upgrade to Drupal 10 completed successfully!</info>');
    }

    protected function upgradeToDrupal11()
    {
        $this->output->writeln('<info>Starting upgrade to Drupal 11...</info>');

        // Update composer.json to require Drupal 11
        $this->updateComposerRequirements('^11.0');

        // Run composer update
        $this->runComposerUpdate();

        // Run database updates
        $this->runDatabaseUpdates();

        $this->output->writeln('<info>Upgrade to Drupal 11 completed successfully!</info>');
    }

    protected function updateComposerRequirements($version)
    {
        $composerJson = json_decode(file_get_contents('composer.json'), true);
        $composerJson['require']['drupal/core'] = $version;
        $composerJson['require']['drupal/core-recommended'] = $version;
        file_put_contents('composer.json', json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    protected function runComposerUpdate()
    {
        $this->output->writeln('<info>Running composer update...</info>');
        $process = new Process(['composer', 'update', '--with-all-dependencies']);
        $process->setTty(true);
        $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });
    }

    protected function runDatabaseUpdates()
    {
        $this->output->writeln('<info>Running database updates...</info>');
        $process = new Process(['drush', 'updatedb', '--yes']);
        $process->setTty(true);
        $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });
    }
} 