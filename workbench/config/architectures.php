<?php

use Taqie\ArchitectureKit\Architecture;

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
];
