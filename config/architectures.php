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
    'graph_runtime' => [
        // Standalone Basic map storage; independent of analysis.engine (php or mago).
        // Rust is explicit opt-in until large-project parity and performance are verified.
        'representation' => 'php',
        // Finite PHP memory_limit supplies each ceiling unless configured explicitly.
        // These are global user/tool-home budgets, not evidence of OS free memory.
        // Seed global ceilings only on the first registry creation; later requests can only reduce them.
        // Explicit tool-home administration: runtime global-limits status|set RESIDENT_BYTES TRANSITION_BYTES.
        'resident_budget_bytes' => null,
        'transition_budget_bytes' => null,
        'queue_paths' => 64,
        'queue_bytes' => 1048576,
        'posting_budget' => 32768,
        'debounce_ms' => 50,
        'max_window_ms' => 250,
    ],
    'control' => [
        // Reaction is independent of the analysis result and Basic/Deep scope.
        'policy' => 'advisory',
    ],
    'audit' => [
        // Partial analysis warns by default. Use block to require completeness.
        // 'incomplete_analysis' => 'warn',
        //
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
