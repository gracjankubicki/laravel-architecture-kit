<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\LaravelAi;

enum LaravelAiCompatibilityStatus: string
{
    case Supported = 'supported';
    case Missing = 'missing';
    case RuntimeDependencyInRequireDev = 'runtime_dependency_in_require_dev';
    case InvalidConstraint = 'invalid_constraint';
    case UnsupportedConstraint = 'unsupported_constraint';
    case NotInstalled = 'not_installed';
    case StaleLock = 'stale_lock';
    case UnsupportedVersion = 'unsupported_version';
    case MissingCapability = 'missing_capability';
}
