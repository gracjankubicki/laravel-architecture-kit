<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use GracjanKubicki\ArchitectureKit\Standalone\GraphRuntime;
use GracjanKubicki\ArchitectureKit\Standalone\Host;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;

GraphRuntime::startProcess();
if (isset($argv[4])) {
    GraphRuntime::instrument(null, null, $argv[4]);
}
$context = new RepositoryContext($argv[1], $argv[2]);
RepositoryContext::activate($context);
Host::create($argv[2]);
$result = (new GraphRuntime($context))->request(json_decode($argv[3], true, flags: JSON_THROW_ON_ERROR));
fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR)."\n");
