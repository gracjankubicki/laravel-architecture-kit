<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use GracjanKubicki\ArchitectureKit\Standalone\Capture\CaptureSourcePlan;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\CaptureStore;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\TypedValue;
use GracjanKubicki\ArchitectureKit\Standalone\Host;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;

[$script, $root, $home, $manifest] = $argv;
RepositoryContext::activate(new RepositoryContext($root, $home));
Host::create($home);
CaptureStore::writePrivate($manifest, TypedValue::json(CaptureSourcePlan::create($root)));
