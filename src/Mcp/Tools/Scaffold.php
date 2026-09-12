<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Mcp\Tools;

use GracjanKubicki\ArchitectureKit\Mcp\Concerns\UsesArchitectureKitState;
use GracjanKubicki\ArchitectureKit\Mcp\Concerns\ValidatesMcpInput;
use GracjanKubicki\ArchitectureKit\Output\AgentOutput;
use GracjanKubicki\ArchitectureKit\Scaffolding\Scaffolder;
use GracjanKubicki\ArchitectureKit\Scaffolding\ScaffoldException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Name('scaffold')]
#[Description('Return the files and skeletons needed for a new element of an enabled architecture, following project conventions. Writes nothing; the agent decides what to create.')]
#[IsReadOnly]
class Scaffold extends Tool
{
    use UsesArchitectureKitState;
    use ValidatesMcpInput;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'architecture' => $schema->string()->description('Enabled architecture slug, for example actions.')->required(),
            'name' => $schema->string()->description('Element name, for example SendInvoice or Billing/SendInvoice.')->required(),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        if (($message = $this->invalidInput($request, ['architecture' => 'string', 'name' => 'string'])) !== null) {
            return $this->inputError('make', $message);
        }

        $agent = new AgentOutput;
        $architecture = $request->get('architecture');
        $name = $request->get('name');

        if (! is_string($architecture) || trim($architecture) === '' || ! is_string($name) || trim($name) === '') {
            return $this->inputError('make', 'Arguments [architecture] and [name] are both required.');
        }

        try {
            $state = $this->projectState();
            $plan = (new Scaffolder($this->files(), base_path(), $state->catalog))
                ->plan($architecture, $name, $state->enabled);

            return Response::structured($agent->make($plan, written: false));
        } catch (ScaffoldException $exception) {
            return Response::structured($agent->error('make', $exception->getMessage(), $exception->errorCode));
        } catch (Throwable $exception) {
            return Response::structured($agent->error('make', $exception->getMessage()));
        }
    }
}
