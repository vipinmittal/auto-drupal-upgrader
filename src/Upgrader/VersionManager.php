<?php

namespace Drupal\DrupalUpgrader\Upgrader;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\Version\VersionParser;

class VersionManager
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
     * @var string
     */
    protected $currentVersion;

    /**
     * @var string
     */
    protected $targetVersion;

    /**
     * @var VersionParser
     */
    protected $versionParser;

    /**
     * Constructor.
     */
    public function __construct(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->versionParser = new VersionParser();
    }

    /**
     * Detects the current Drupal core version.
     */
    public function detectCurrentVersion()
    {
        $packages = $this->composer->getRepositoryManager()->getLocalRepository()->getPackages();
        
        // First try to find drupal/core-recommended
        foreach ($packages as $package) {
            if ($package->getName() === 'drupal/core-recommended') {
                $this->currentVersion = $package->getVersion();
                $this->io->write(sprintf('Detected Drupal version: %s (from drupal/core-recommended)', $this->currentVersion));
                return $this->currentVersion;
            }
        }
        
        // Fall back to drupal/core
        foreach ($packages as $package) {
            if ($package->getName() === 'drupal/core') {
                $this->currentVersion = $package->getVersion();
                $this->io->write(sprintf('Detected Drupal version: %s (from drupal/core)', $this->currentVersion));
                return $this->currentVersion;
            }
        }
        
        throw new \RuntimeException('Could not detect Drupal core version');
    }

    /**
     * Determines the upgrade path based on current version.
     */
    public function determineUpgradePath($targetMajorVersion = null)
    {
        if (!$this->currentVersion) {
            throw new \RuntimeException('Current version not detected. Run detectCurrentVersion() first.');
        }
        
        $currentMajor = $this->getMajorVersion($this->currentVersion);
        $path = [];
        
        // If target version is specified, validate it
        if ($targetMajorVersion) {
            $targetMajorVersion = (int) $targetMajorVersion;
            if ($targetMajorVersion <= $currentMajor) {
                throw new \RuntimeException(sprintf(
                    'Target version %d is not higher than current version %d',
                    $targetMajorVersion,
                    $currentMajor
                ));
            }
            
            if ($targetMajorVersion > 11) {
                throw new \RuntimeException(sprintf(
                    'Target version %d is not supported. Maximum supported version is 11.',
                    $targetMajorVersion
                ));
            }
        } else {
            // Default to latest supported version
            $targetMajorVersion = 11;
        }
        
        // Build upgrade path
        if ($currentMajor < 9) {
            throw new \RuntimeException('Upgrading from Drupal 8 or earlier is not supported');
        }
        
        if ($currentMajor === 9) {
            $path[] = '9.5.0'; // Latest stable D9
            if ($targetMajorVersion >= 10) {
                $path[] = '10.0.0';
            }
        }
        
        if ($currentMajor === 10 || (isset($path) && end($path) === '10.0.0')) {
            if ($targetMajorVersion >= 11) {
                $path[] = '11.0.0';
            }
        }
        
        $this->targetVersion = end($path);
        return $path;
    }

    /**
     * Gets the major version from a version string.
     */
    public function getMajorVersion($version)
    {
        // Remove the caret and any other constraints
        $version = trim(str_replace('^', '', $version));
        // Get only the first part of the version number
        $parts = explode('.', $version);
        return (int) $parts[0];
    }

    /**
     * Gets a compatible version constraint for a package.
     */
    public function getCompatibleVersion($package, $targetVersion)
    {
        // Convert version number to major version constraint
        $major = $this->getMajorVersion($targetVersion);
        return "^$major.0";
    }

    /**
     * Gets the current version.
     */
    public function getCurrentVersion()
    {
        return $this->currentVersion;
    }

    /**
     * Gets the target version.
     */
    public function getTargetVersion()
    {
        return $this->targetVersion;
    }
}