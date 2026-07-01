<?php

use Taqie\ArchitectureKit\Architecture;

return [
    'enabled' => [
        Architecture::ThinControllers,
        Architecture::FormRequests,
        Architecture::Actions,
        Architecture::DataObjects,
        Architecture::ApiResources,
    ],
];
