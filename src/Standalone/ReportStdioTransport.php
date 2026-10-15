<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use Laravel\Mcp\Server\Transport\StdioTransport;
use Laravel\Mcp\Transport\JsonRpcResponse;

final class ReportStdioTransport extends StdioTransport
{
    public function sendReport(JsonRpcResponse $response): void
    {
        $buffer = '';
        foreach (JsonResponseChunks::encode($response->toArray()) as $chunk) {
            $buffer .= $chunk;
            if (strlen($buffer) >= 65536) {
                fwrite(STDOUT, $buffer);
                $buffer = '';
            }
        }
        fwrite(STDOUT, $buffer);
        fwrite(STDOUT, PHP_EOL);
    }
}
