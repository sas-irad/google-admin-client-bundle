<?php

use Doctrine\Common\Annotations\AnnotationRegistry;
use Composer\Autoload\ClassLoader;

/**
 * @var ClassLoader $loader
 */
$loader = require __DIR__.'/../../vendor/autoload.php';

// add google root manually since the Google libs rely on "require_once" instead of an autoloader
set_include_path(get_include_path() . PATH_SEPARATOR . __DIR__ . '/../../vendor/google/google-api-php-client/src');

AnnotationRegistry::registerLoader(array($loader, 'loadClass'));

return $loader;