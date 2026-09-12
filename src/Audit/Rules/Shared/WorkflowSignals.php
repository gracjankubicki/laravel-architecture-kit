<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\Shared;

/**
 * What counts as business logic that belongs in an Action rather than at the edge of
 * the application.
 *
 * The controller rule and the route rule ask the same question about different hosts, a
 * method body and a closure, so the answer lives here. Two copies would drift the first
 * time one of them learned about a new signal.
 */
final readonly class WorkflowSignals
{
    public const INLINE_VALIDATION = 'inline_validation';

    public const MODEL_WRITE = 'model_write';

    public const TRANSACTION = 'transaction';

    public const DISPATCH = 'dispatch';

    public const TRANSACTION_FACADE = 'Illuminate\\Support\\Facades\\DB';

    /**
     * Instance methods that write to a model the host received or resolved.
     *
     * @var array<int, string>
     */
    public const MODEL_WRITE_METHODS = ['update', 'delete'];

    public static function isValidationCall(string $method): bool
    {
        return $method === 'validate';
    }

    public static function isModelWriteMethod(string $method): bool
    {
        return in_array($method, self::MODEL_WRITE_METHODS, true);
    }

    public static function isTransactionCall(string $class, string $method): bool
    {
        return $class === self::TRANSACTION_FACADE && $method === 'transaction';
    }

    public static function isModelClass(string $class): bool
    {
        return str_starts_with($class, 'App\\Models\\');
    }

    public static function isWorkflowDispatchTarget(string $class): bool
    {
        return str_starts_with($class, 'App\\Jobs\\') || str_starts_with($class, 'App\\Events\\');
    }
}
