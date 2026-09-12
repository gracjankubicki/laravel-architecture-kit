<?php

use GracjanKubicki\ArchitectureKit\Architecture;

return [
    'enabled' => [
        Architecture::ThinControllers,
        Architecture::FormRequests,
        Architecture::Actions,
        Architecture::DataObjects,
        Architecture::ApiResources,
        Architecture::LaravelBestPractices,
    ],
    'runtime' => [
        'driver' => 'local',
        'service' => null,
        'php' => 'php',
        'command' => null,
    ],
    'audit' => [
        // Directories outside app/ the audit should read, for example:
        // 'paths' => ['routes'],
        //
        // Report an architecture element that no test depends on.
        // One of 'off', 'warn', 'error'. Enabling it also brings tests/
        // into scope, because the rule answers from the project graph.
        // 'missing_test' => 'warn',
        //
        // The project graph is kept between runs so an unchanged file is not
        // parsed again. Set false to turn it off, or give a directory to move
        // it; the default lives in storage/, which Laravel already ignores.
        // 'cache' => false,
    ],
];
