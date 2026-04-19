<?php

/*
 *---------------------------------------------------------------
 * APPLICATION ENVIRONMENT
 *---------------------------------------------------------------
 *
 * You can load different configurations depending on your
 * current environment. Setting the environment also influences
 * things like logging and error reporting.
 *
 * This can be set to anything, but default usage is:
 *
 *     development
 *     testing
 *     production
 *
 * NOTE: If you change these, also change the error_reporting() code below
 */
defined('ENVIRONMENT') || define('ENVIRONMENT', 'development');

/*
 *---------------------------------------------------------------
 * ERROR REPORTING
 *---------------------------------------------------------------
 *
 * Different environments will require different levels of error reporting.
 * By default development will show errors but testing and production will hide them.
 */
switch (ENVIRONMENT) {
    case 'development':
        error_reporting(-1);
        ini_set('display_errors', '1');
        break;

    case 'testing':
    case 'production':
        ini_set('display_errors', '0');
        error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
        break;

    default:
        header('HTTP/1.1 503 Service Unavailable.', true, 503);
        echo 'The application environment is not set correctly.';
        exit(1); // EXIT_ERROR
}

/*
 *---------------------------------------------------------------
 * SYSTEM DIRECTORY NAME
 *---------------------------------------------------------------
 *
 * This variable must contain the name of your "system" directory.
 * Set the path if it is not in the same directory as this file.
 */
$systemDirectory = '../vendor/codeigniter4/framework/system';

/*
 *---------------------------------------------------------------
 * APPLICATION DIRECTORY NAME
 *---------------------------------------------------------------
 *
 * If you want this front controller to use a different "app"
 * directory than the default one you can set its name here.
 * The directory can also be renamed or relocated anywhere on your server.
 * If you do, use an absolute (full) server path.
 * For more info please see the user guide:
 *
 * https://codeigniter4.github.io/userguide/general/managing_apps.html
 *
 * NO TRAILING SLASH!
 */
$applicationDirectory = '../app';

/*
 *---------------------------------------------------------------
 * VIEW DIRECTORY NAME
 *---------------------------------------------------------------
 *
 * If you want to move the view directory out of the application
 * directory, set the path to it here. The directory can be renamed
 * and relocated anywhere on your server. If blank, it will default
 * to the standard location inside your application directory.
 * If you do, use an absolute (full) server path.
 *
 * NO TRAILING SLASH!
 */
$viewDirectory = '';

/*
 * ---------------------------------------------------------------
 * BOOTSTRAP THE APPLICATION
 * ---------------------------------------------------------------
 * This process sets up the path constants, loads and registers
 * our autoloader, along with Composer's, loads our constants
 * and fires up an environment-specific bootstrapping.
 */

// Load our paths config file
require __DIR__ . '/../app/Config/Paths.php';

// Paths
$paths = new Config\Paths();

// Location of the framework bootstrap file.
require rtrim(__DIR__ . '/../vendor/codeigniter4/framework/system', '/ ') . '/Boot.php';

// Load and run the application
try {
    require_once __DIR__ . '/../vendor/codeigniter4/framework/system/Boot.php';
} catch (\Exception $e) {
    echo 'Error: ' . $e->getMessage();
}