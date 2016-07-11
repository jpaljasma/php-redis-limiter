<?php

use DI\ContainerBuilder;

require __DIR__ . '/../vendor/autoload.php';

$builder = new ContainerBuilder;
$builder->addDefinitions(__DIR__ . '/config.php');
$builder->ignorePhpDocErrors(true);
$container = $builder->build();

return $container;