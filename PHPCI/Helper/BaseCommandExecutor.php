<?php
/**
 * PHPCI - Continuous Integration for PHP
 *
 * @copyright    Copyright 2014, Block 8 Limited.
 * @license      https://github.com/Block8/PHPCI/blob/master/LICENSE.md
 * @link         https://www.phptesting.org/
 */

namespace PHPCI\Helper;

use \PHPCI\Logging\BuildLogger;
use Psr\Log\LogLevel;

abstract class BaseCommandExecutor implements CommandExecutor
{
    /**
     * @var \PHPCI\Logging\BuildLogger
     */
    protected $logger;

    /**
     * @var bool
     */
    protected $quiet;

    /**
     * @var bool
     */
    protected $verbose;

    protected $lastOutput;

    public $logExecOutput = true;

    /**
     * The path which findBinary will look in.
     * @var string
     */
    protected $rootDir;

    /**
     * Current build path
     * @var string
     */
    protected $buildPath;

    /**
     * @param BuildLogger $logger
     * @param string      $rootDir
     * @param bool        $quiet
     * @param bool        $verbose
     */
    public function __construct(BuildLogger $logger, $rootDir, &$quiet = false, &$verbose = false)
    {
        $this->logger = $logger;
        $this->quiet = $quiet;
        $this->verbose = $verbose;

        $this->lastOutput = array();

        $this->rootDir = $rootDir;
    }

    /**
     * Executes shell commands.
     * @param array $args
     * @return bool Indicates success
     */
    public function executeCommand($args = array())
    {
        $this->lastOutput = array();

        $command = call_user_func_array('sprintf', $args);

        if ($this->quiet) {
            $this->logger->log('Executing: ' . $command);
        }

        $status = 0;
        exec($command, $this->lastOutput, $status);

        foreach ($this->lastOutput as &$lastOutput) {
            $lastOutput = trim($lastOutput, '"');
        }

        if ($this->logExecOutput && !empty($this->lastOutput) && ($this->verbose|| $status != 0)) {
            $this->logger->log($this->lastOutput);
        }

        $rtn = false;

        if ($status == 0) {
            $rtn = true;
        }

        return $rtn;
    }

    /**
     * Returns the output from the last command run.
     */
    public function getLastOutput()
    {
        return implode(PHP_EOL, $this->lastOutput);
    }

    /**
     * Find a binary required by a plugin.
     * @param string $binary
     * @return null|string
     */
    public function findBinary($binary, $buildPath = null) {
        $binaryPath = null;
        $composerBin = $this->getComposerBinDir(realpath($buildPath));

        if (is_string($binary)) {
            $binary = array($binary);
        }

        foreach ($binary as $bin) {
            $this->logger->log("Looking for binary: " . $bin, LogLevel::DEBUG);

            if (is_dir($composerBin) && is_file($composerBin.'/'.$bin)) {
                $this->logger->log("Found in ".$composerBin.": " . $bin, LogLevel::DEBUG);
                $binaryPath = $composerBin . '/' . $bin;
                break;
            }

            if (is_file($this->rootDir . $bin)) {
                $this->logger->log("Found in root: " . $bin, LogLevel::DEBUG);
                $binaryPath = $this->rootDir . $bin;
                break;
            }

            if (is_file($this->rootDir . 'vendor/bin/' . $bin)) {
                $this->logger->log("Found in vendor/bin: " . $bin, LogLevel::DEBUG);
                $binaryPath = $this->rootDir . 'vendor/bin/' . $bin;
                break;
            }

            $findCmdResult = $this->findGlobalBinary($bin);
            if (is_file($findCmdResult)) {
                $this->logger->log("Found in " . $findCmdResult, LogLevel::DEBUG);
                $binaryPath = $findCmdResult;
                break;
            }
        }
        return $binaryPath;
    }

    /**
     * Find a binary which is installed globally on the system
     * @param string $binary
     * @return null|string
     */
    abstract protected function findGlobalBinary($bin);

    /**
     * Try to load the composer.json file in the building project
     * If the bin-dir is configured, return the full path to it
     * @param string $path Current build path
     * @return string|null
     */
    public function getComposerBinDir($path) {
        if (is_dir($path)) {
            $composer = $path.'/composer.json';
            if( is_file($composer) ) {
                $json = json_decode(file_get_contents($composer));
                if( isset($json->config->{"bin-dir"}) ) {
                    return $path.'/'.$json->config->{"bin-dir"};
                }
            }
        }
        return null;
    }
}
