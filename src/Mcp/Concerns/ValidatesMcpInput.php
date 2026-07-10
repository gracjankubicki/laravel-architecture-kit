<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Mcp\Concerns;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

trait ValidatesMcpInput
{
    /**
     * @param  array<string, 'boolean'|'integer'|'string'>  $types
     */
    protected function invalidInput(Request $request, array $types): ?string
    {
        foreach ($types as $name => $type) {
            $value = $request->get($name);

            if ($value === null) {
                continue;
            }

            $valid = match ($type) {
                'boolean' => is_bool($value),
                'integer' => is_int($value) && $value >= 0,
                'string' => is_string($value),
            };

            if (! $valid) {
                return "Argument [{$name}] must be a {$type}".($type === 'integer' ? ' >= 0.' : '.');
            }
        }

        return null;
    }

    protected function inputError(string $command, string $message, string $code = 'E_INVALID_TOOL_INPUT'): ResponseFactory
    {
        return Response::structured([
            'v' => 1,
            'ok' => false,
            'cmd' => $command,
            'm' => $code,
            'msg' => $message,
            'next' => ['fix_tool_input', 'rerun:'.$command],
        ]);
    }
}
