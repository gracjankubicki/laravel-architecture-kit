<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Mcp\Tools;

use GracjanKubicki\ArchitectureKit\Guidance\FileGuidance;
use GracjanKubicki\ArchitectureKit\Mcp\Concerns\UsesArchitectureKitState;
use GracjanKubicki\ArchitectureKit\Mcp\Concerns\ValidatesMcpInput;
use GracjanKubicki\ArchitectureKit\Output\AgentOutput;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Name('file-rules')]
#[Description('Return only the architecture rules that govern one file path, including whether each is enforced or advisory. The file does not have to exist yet.')]
#[IsReadOnly]
class FileRules extends Tool
{
    use UsesArchitectureKitState;
    use ValidatesMcpInput;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('Application-relative path, for example app/Actions/SendInvoice.php.')->required(),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        if (($message = $this->invalidInput($request, ['path' => 'string'])) !== null) {
            return $this->inputError('file-rules', $message);
        }

        $agent = new AgentOutput;
        $path = $request->get('path');

        if (! is_string($path) || trim($path) === '') {
            return $this->inputError('file-rules', 'Argument [path] must be a non-empty application-relative path.');
        }

        try {
            $state = $this->projectState();
            $guidance = (new FileGuidance($this->files(), base_path(), $state->catalog, $state->auditScope))->for(
                path: trim($path),
                enabled: $state->enabled,
                customRules: $state->customRules,
            );

            return Response::structured($agent->fileRules($guidance));
        } catch (Throwable $exception) {
            return Response::structured($agent->error('file-rules', $exception->getMessage()));
        }
    }
}
