<?php

namespace Drupal\DrupalUpgrader\Command;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;

class CommandProvider implements CommandProviderCapability
{
    /**
     * {@inheritdoc}
     */
    public function getCommands()
    {
        return [
            new UpgradeCommand(),
        ];
    }
}