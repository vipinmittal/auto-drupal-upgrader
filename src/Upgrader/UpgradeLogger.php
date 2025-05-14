<?php

namespace Drupal\DrupalUpgrader\Upgrader;

use Composer\IO\IOInterface;

class UpgradeLogger
{
    /**
     * @var IOInterface
     */
    protected $io;

    /**
     * @var bool
     */
    protected $verbose;

    /**
     * Constructor.
     */
    public function __construct(IOInterface $io, $verbose = false)
    {
        $this->io = $io;
        $this->verbose = $verbose;
    }

    /**
     * Logs a section header.
     */
    public function logSection($message)
    {
        $this->io->write(sprintf('\n<info>%s</info>', $message));
        $this->io->write(str_repeat('-', strlen($message)));
    }

    /**
     * Logs a regular message.
     */
    public function log($message, $newLine = true)
    {
        $this->io->write($message, $newLine);
    }

    /**
     * Logs a verbose message (only if verbose mode is enabled).
     */
    public function logVerbose($message, $newLine = true)
    {
        if ($this->verbose) {
            $this->io->write($message, $newLine);
        }
    }

    /**
     * Logs an error message.
     */
    public function logError($message)
    {
        $this->io->writeError(sprintf('<error>%s</error>', $message));
    }

    /**
     * Logs a warning message.
     */
    public function logWarning($message)
    {
        $this->io->write(sprintf('<warning>%s</warning>', $message));
    }

    /**
     * Logs a success message.
     */
    public function logSuccess($message)
    {
        $this->io->write(sprintf('<info>%s</info>', $message));
    }
}