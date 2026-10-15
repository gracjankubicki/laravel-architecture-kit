<?php

use GracjanKubicki\ArchitectureKit\Architecture;

return [
    'analysis' => ['engine' => 'php', 'php_version' => '8.3'],
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
];
