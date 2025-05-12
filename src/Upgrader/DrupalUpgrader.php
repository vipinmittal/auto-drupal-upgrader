<?php

namespace Drupal\DrupalUpgrader\Upgrader;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\Version\VersionParser;
use Symfony\Component\Process\Process;
use Composer\Json\JsonFile;

class DrupalUpgrader
{
    // ... keep existing properties and constructor ...

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
        $process->run(function ($type, $buffer) {
            $this->io->write($buffer, false);
        });
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

    // ... keep rest of the existing methods ...
}