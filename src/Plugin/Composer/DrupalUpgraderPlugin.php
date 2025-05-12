<?php

namespace Drupal\DrupalUpgrader\Plugin\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;
use Drupal\DrupalUpgrader\Upgrader\DrupalUpgrader;

class DrupalUpgraderPlugin implements PluginInterface, EventSubscriberInterface
{
    protected $composer;
    protected $io;
    protected $upgrader;

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->upgrader = new DrupalUpgrader($composer, $io);
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
            ScriptEvents::PRE_UPDATE_CMD => 'onPreUpdate',
            ScriptEvents::POST_UPDATE_CMD => 'onPostUpdate',
        ];
    }

    public function onPreUpdate()
    {
        $this->upgrader->checkCurrentVersion();
        $this->upgrader->planUpgrade();
    }

    public function onPostUpdate()
    {
        $this->upgrader->executeUpgrade();
    }
}