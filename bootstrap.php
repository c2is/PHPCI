<?php
/**
* PHPCI - Continuous Integration for PHP
*
* @copyright    Copyright 2013, Block 8 Limited.
* @license      https://github.com/Block8/PHPCI/blob/master/LICENSE.md
* @link         http://www.phptesting.org/
*/

// Let PHP take a guess as to the default timezone, if the user hasn't set one:
use PHPCI\Logging\Handler;
use PHPCI\Logging\LoggerConfig;

$timezone = ini_get('date.timezone');
if (empty($timezone)) {
    date_default_timezone_set('UTC');
}

// Set up a basic autoloader for PHPCI:
$autoload = function ($class) {
    $file = str_replace(array('\\', '_'), '/', $class);
    $file .= '.php';

    if (substr($file, 0, 1) == '/') {
        $file = substr($file, 1);
    }

    if (is_file(dirname(__FILE__) . '/' . $file)) {
        include(dirname(__FILE__) . '/' . $file);
        return;
    }
};

spl_autoload_register($autoload, true, true);

// If the PHPCI config file is not where we expect it, try looking in
// env for an alternative config path.
$configFile = dirname(__FILE__) . '/PHPCI/config.yml';

if (!file_exists($configFile)) {
    $configEnv = getenv('phpci_config_file');

    if (!empty($configEnv)) {
        $configFile = $configEnv;
    }
}

// If we don't have a config file at all, fail at this point and tell the user to install:
if (!file_exists($configFile) && (!defined('PHPCI_IS_CONSOLE') || !PHPCI_IS_CONSOLE)) {
    die('PHPCI has not yet been installed - Please use the command ./console phpci:install to install it.');
}

// If composer has not been run, fail at this point and tell the user to install:
if (!file_exists(dirname(__FILE__) . '/vendor/autoload.php') && defined('PHPCI_IS_CONSOLE') && PHPCI_IS_CONSOLE) {
    file_put_contents('php://stderr', 'Please install PHPCI with "composer install" before using console');
    exit(1);
}

// Load Composer autoloader:
require_once(dirname(__FILE__) . '/vendor/autoload.php');

if (defined('PHPCI_IS_CONSOLE') && PHPCI_IS_CONSOLE) {
    $loggerConfig = LoggerConfig::newFromFile(__DIR__ . "/loggerconfig.php");
    Handler::register($loggerConfig->getFor('_'));
}

// Load configuration if present:
$conf = array();
$conf['b8']['app']['namespace'] = 'PHPCI';
$conf['b8']['app']['default_controller'] = 'Home';
$conf['b8']['view']['path'] = dirname(__FILE__) . '/PHPCI/View/';

$config = new b8\Config($conf);

if (file_exists($configFile)) {
    $config->loadYaml($configFile);
}

require_once(dirname(__FILE__) . '/vars.php');
