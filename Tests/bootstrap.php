<?php 

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Debug\Debug;

$loader = require_once __DIR__.'/app/autoload.php';
Debug::enable();

require_once __DIR__.'/app/AppKernel.php';

