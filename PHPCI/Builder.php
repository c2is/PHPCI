<?php
/**
 * PHPCI - Continuous Integration for PHP
 *
 * @copyright    Copyright 2013, Block 8 Limited.
 * @license      https://github.com/Block8/PHPCI/blob/master/LICENSE.md
 * @link         http://www.phptesting.org/
 */

namespace PHPCI;

use PHPCI\Helper\CommandExecutor;
use PHPCI\Helper\MailerFactory;
use PHPCI\Model\Build;
use b8\Store;
use b8\Config;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * PHPCI Build Runner
 * @author   Dan Cryer <dan@block8.co.uk>
 */
class Builder implements LoggerAwareInterface, BuildLogger
{
    /**
     * @var string
     */
    public $buildPath;

    /**
     * @var string[]
     */
    public $ignore = array();

    /**
     * @var string
     */
    protected $ciDir;

    /**
     * @var string
     */
    protected $directory;

    /**
     * @var bool
     */
    protected $success = true;

    /**
     * @var bool
     */
    protected $verbose = true;

    /**
     * @var \PHPCI\Model\Build
     */
    protected $build;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var string
     */
    protected $lastOutput;

    /**
     * An array of key => value pairs that will be used for
     * interpolation and environment variables
     * @var array
     * @see setInterpolationVars()
     */
    protected $interpolation_vars = array();

    /**
     * @var \PHPCI\Store\BuildStore
     */
    protected $store;

    /**
     * @var bool
     */
    public $quiet = false;

    /**
     * @var \PHPCI\Plugin\Util\Executor
     */
    protected $pluginExecutor;

    /**
     * @var Helper\CommandExecutor
     */
    protected $commandExecutor;

    /**
     * Set up the builder.
     * @param \PHPCI\Model\Build $build
     * @param LoggerInterface $logger
     */
    public function __construct(Build $build, $logger = null)
    {
        if ($logger) {
            $this->setLogger($logger);
        }
        $this->build = $build;
        $this->store = Store\Factory::getStore('Build');
        $this->pluginExecutor = new Plugin\Util\Executor($this->buildPluginFactory($build), $this);

        $this->commandExecutor = new CommandExecutor($this, PHPCI_DIR, $this->quiet, $this->verbose);
    }

    /**
     * Set the config array, as read from phpci.yml
     * @param array
     */
    public function setConfigArray(array $config)
    {
        $this->config = $config;
    }

    /**
     * Access a variable from the phpci.yml file.
     * @param string
     */
    public function getConfig($key)
    {
        $rtn = null;

        if (isset($this->config[$key])) {
            $rtn = $this->config[$key];
        }

        return $rtn;
    }

    /**
     * Access a variable from the config.yml
     * @param $key
     * @return mixed
     */
    public function getSystemConfig($key)
    {
        return Config::getInstance()->get($key);
    }

    /**
     * @return string   The title of the project being built.
     */
    public function getBuildProjectTitle()
    {
        return $this->build->getProject()->getTitle();
    }

    /**
     * Run the active build.
     */
    public function execute()
    {
        // Update the build in the database, ping any external services.
        $this->build->setStatus(Build::STATUS_RUNNING);
        $this->build->setStarted(new \DateTime());
        $this->store->save($this->build);
        $this->build->sendStatusPostback();
        $this->success = true;

        // Set up the build:
        $this->setupBuild();

        // Run the core plugin stages:
        foreach (array('setup', 'test') as $stage) {
            $this->success &= $this->pluginExecutor->executePlugins($this->config, $stage);
        }

        // Set the status so this can be used by complete, success and failure
        // stages.
        if ($this->success) {
            $this->build->setStatus(Build::STATUS_SUCCESS);
        }
        else {
            $this->build->setStatus(Build::STATUS_FAILED);
        }

        // Complete stage plugins are always run
        $this->pluginExecutor->executePlugins($this->config, 'complete');

        if ($this->success) {
            $this->pluginExecutor->executePlugins($this->config, 'success');
            $this->logSuccess('BUILD SUCCESSFUL!');
        }
        else {
            $this->pluginExecutor->executePlugins($this->config, 'failure');
            $this->logFailure("BUILD FAILURE");
        }

        // Clean up:
        $this->log('Removing build.');
        shell_exec(sprintf('rm -Rf "%s"', $this->buildPath));

        // Update the build in the database, ping any external services, etc.
        $this->build->sendStatusPostback();
        $this->build->setFinished(new \DateTime());
        $this->store->save($this->build);
    }

    /**
     * Used by this class, and plugins, to execute shell commands.
     */
    public function executeCommand()
    {
        return $this->commandExecutor->buildAndExecuteCommand(func_get_args());
    }

    /**
     * Returns the output from the last command run.
     */
    public function getLastOutput()
    {
        return $this->commandExecutor->getLastOutput();
    }

    /**
     * Find a binary required by a plugin.
     * @param $binary
     * @return null|string
     */
    public function findBinary($binary)
    {
        return $this->commandExecutor->findBinary($binary);
    }

    /**
     * Add an entry to the build log.
     * @param string|string[] $message
     * @param string $level
     * @param mixed[] $context
     */
    public function log($message, $level = LogLevel::INFO, $context = array())
    {
        // Skip if no logger has been loaded.
        if (!$this->logger) {
            return;
        }

        if (!is_array($message)) {
            $message = array($message);
        }

        // The build is added to the context so the logger can use
        // details from it if required.
        $context['build'] = $this->build;

        foreach ($message as $item) {
            $this->logger->log($level, $item, $context);
        }
    }

    /**
     * Add a success-coloured message to the log.
     * @param string
     */
    public function logSuccess($message)
    {
        $this->log("\033[0;32m" . $message . "\033[0m");
    }

    /**
     * Add a failure-coloured message to the log.
     * @param string $message
     * @param \Exception $exception The exception that caused the error.
     */
    public function logFailure($message, \Exception $exception = null)
    {
        $context = array();

        // The psr3 log interface stipulates that exceptions should be passed
        // as the exception key in the context array.
        if ($exception) {
            $context['exception'] = $exception;
        }

        $this->log(
            "\033[0;31m" . $message . "\033[0m",
            LogLevel::ERROR,
            $context
        );
    }

    /**
     * Replace every occurance of the interpolation vars in the given string
     * Example: "This is build %PHPCI_BUILD%" => "This is build 182"
     * @param string $input
     * @return string
     */
    public function interpolate($input)
    {
        $keys = array_keys($this->interpolation_vars);
        $values = array_values($this->interpolation_vars);
        return str_replace($keys, $values, $input);
    }

    /**
     * Sets the variables that will be used for interpolation. This must be run
     * from setupBuild() because prior to that, we don't know the buildPath
     */
    protected function setInterpolationVars()
    {
        $this->interpolation_vars = array();
        $this->interpolation_vars['%PHPCI%'] = 1;
        $this->interpolation_vars['%COMMIT%'] = $this->build->getCommitId();
        $this->interpolation_vars['%PROJECT%'] = $this->build->getProjectId();
        $this->interpolation_vars['%BUILD%'] = $this->build->getId();
        $this->interpolation_vars['%PROJECT_TITLE%'] = $this->getBuildProjectTitle(
        );
        $this->interpolation_vars['%BUILD_PATH%'] = $this->buildPath;
        $this->interpolation_vars['%BUILD_URI%'] = PHPCI_URL . "build/view/" . $this->build->getId(
            );
        $this->interpolation_vars['%PHPCI_COMMIT%'] = $this->interpolation_vars['%COMMIT%'];
        $this->interpolation_vars['%PHPCI_PROJECT%'] = $this->interpolation_vars['%PROJECT%'];
        $this->interpolation_vars['%PHPCI_BUILD%'] = $this->interpolation_vars['%BUILD%'];
        $this->interpolation_vars['%PHPCI_PROJECT_TITLE%'] = $this->interpolation_vars['%PROJECT_TITLE%'];
        $this->interpolation_vars['%PHPCI_BUILD_PATH%'] = $this->interpolation_vars['%BUILD_PATH%'];
        $this->interpolation_vars['%PHPCI_BUILD_URI%'] = $this->interpolation_vars['%BUILD_URI%'];

        putenv('PHPCI=1');
        putenv('PHPCI_COMMIT=' . $this->interpolation_vars['%COMMIT%']);
        putenv('PHPCI_PROJECT=' . $this->interpolation_vars['%PROJECT%']);
        putenv('PHPCI_BUILD=' . $this->interpolation_vars['%BUILD%']);
        putenv(
            'PHPCI_PROJECT_TITLE=' . $this->interpolation_vars['%PROJECT_TITLE%']
        );
        putenv('PHPCI_BUILD_PATH=' . $this->interpolation_vars['%BUILD_PATH%']);
        putenv('PHPCI_BUILD_URI=' . $this->interpolation_vars['%BUILD_URI%']);
    }

    /**
     * Set up a working copy of the project for building.
     */
    protected function setupBuild()
    {
        $buildId = 'project' . $this->build->getProject()->getId(
            ) . '-build' . $this->build->getId();
        $this->ciDir = dirname(__FILE__) . '/../';
        $this->buildPath = $this->ciDir . 'build/' . $buildId . '/';

        $this->setInterpolationVars();

        // Create a working copy of the project:
        if (!$this->build->createWorkingCopy($this, $this->buildPath)) {
            throw new \Exception('Could not create a working copy.');
        }

        // Does the project's phpci.yml request verbose mode?
        if (!isset($this->config['build_settings']['verbose']) || !$this->config['build_settings']['verbose']) {
            $this->verbose = false;
        }

        // Does the project have any paths it wants plugins to ignore?
        if (isset($this->config['build_settings']['ignore'])) {
            $this->ignore = $this->config['build_settings']['ignore'];
        }

        $this->logSuccess('Working copy created: ' . $this->buildPath);
        return true;
    }

    /**
     * Sets a logger instance on the object
     *
     * @param LoggerInterface $logger
     * @return null
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * returns the logger attached to this builder.
     *
     * @return LoggerInterface
     */
    public function getLogger()
    {
        return $this->logger;
    }

    private function buildPluginFactory(Build $build)
    {
        $pluginFactory = new Plugin\Util\Factory();

        $self = $this;
        $pluginFactory->registerResource(
            function () use($self) {
                return $self;
            },
            null,
            'PHPCI\Builder'
        );

        $pluginFactory->registerResource(
            function () use($build) {
                return $build;
            },
            null,
            'PHPCI\Model\Build'
        );

        $pluginFactory->registerResource(
            function () use ($self) {
                $factory = new MailerFactory($self->getSystemConfig('phpci'));
                return $factory->getSwiftMailerFromConfig();
            },
            null,
            'Swift_Mailer'
        );

        return $pluginFactory;
    }
}
