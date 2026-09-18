<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use ArrayAccess;
use Composer\InstalledVersions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Orchestra\Testbench\TestCase;

if (trait_exists(Promptable::class) && interface_exists(Agent::class) && interface_exists(HasStructuredOutput::class)) {
    final class RealLaravelAiTextAgent implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return 'Return the prepared test response.';
        }
    }

    final class RealLaravelAiStructuredAgent implements Agent, HasStructuredOutput
    {
        use Promptable;

        public function instructions(): string
        {
            return 'Return a periodic table symbol.';
        }

        public function schema(JsonSchema $schema): array
        {
            return [
                'symbol' => $schema->string()->required(),
            ];
        }
    }

    final readonly class RealLaravelAiStructuredResult
    {
        public function __construct(public string $symbol) {}

        public static function fromResponse(StructuredAgentResponse $response): self
        {
            $symbol = $response['symbol'];

            if (! is_string($symbol)) {
                throw new \UnexpectedValueException('The structured symbol must be a string.');
            }

            return new self($symbol);
        }
    }
}

final class RealLaravelAiBehaviorTest extends TestCase
{
    public function test_real_sdk_executes_prompt_stream_and_structured_fakes_without_provider_traffic(): void
    {
        $this->requireRealPackage();
        Http::preventStrayRequests();

        RealLaravelAiTextAgent::fake(['Prompt response', 'Stream response'])
            ->preventStrayPrompts();

        $prompt = (new RealLaravelAiTextAgent)->prompt('Prompt input');
        $this->assertSame('Prompt response', $prompt->text);

        $stream = (new RealLaravelAiTextAgent)->stream('Stream input');
        $stream->each(fn (): true => true);
        $this->assertSame('Stream response', $stream->text);

        RealLaravelAiStructuredAgent::fake([['symbol' => 'Au']])
            ->preventStrayPrompts();

        $structured = (new RealLaravelAiStructuredAgent)->prompt('Gold input');
        $this->assertInstanceOf(StructuredAgentResponse::class, $structured);
        $this->assertInstanceOf(ArrayAccess::class, $structured);
        $this->assertSame(['symbol' => 'Au'], $structured->toArray());
        $this->assertSame('Au', RealLaravelAiStructuredResult::fromResponse($structured)->symbol);
    }

    public function test_real_sdk_preserves_version_specific_fake_queue_behavior(): void
    {
        $this->requireRealPackage();
        Http::preventStrayRequests();

        RealLaravelAiTextAgent::fake(['Queued response'])
            ->preventStrayPrompts();

        $resultKey = 'architecture-kit-real-laravel-ai-queue-result';
        $GLOBALS[$resultKey] = null;

        try {
            (new RealLaravelAiTextAgent)->queue('Queued input')->then(
                function (AgentResponse $response) use ($resultKey): void {
                    $GLOBALS[$resultKey] = $response->text;
                },
            );

            RealLaravelAiTextAgent::assertQueued('Queued input');

            if (version_compare($this->installedVersion(), '0.11.0', '>=')) {
                $this->assertSame('Queued response', $GLOBALS[$resultKey]);
            } else {
                $this->assertNull($GLOBALS[$resultKey]);
            }
        } finally {
            unset($GLOBALS[$resultKey]);
        }
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('queue.connections.sync', ['driver' => 'sync']);
    }

    protected function getPackageProviders($app): array
    {
        return class_exists(AiServiceProvider::class) ? [AiServiceProvider::class] : [];
    }

    private function requireRealPackage(): void
    {
        if (InstalledVersions::isInstalled('laravel/ai') && class_exists(RealLaravelAiTextAgent::class)) {
            return;
        }

        if (getenv('ARCHITECTURE_KIT_LARAVEL_AI_CONTRACT') === '1') {
            $this->fail('The dedicated laravel-ai-contract job must install laravel/ai before behavior tests run.');
        }

        $this->markTestSkipped('Real Laravel AI behavior runs in the dedicated 0.8 through 0.11 CI matrix.');
    }

    private function installedVersion(): string
    {
        $version = InstalledVersions::getPrettyVersion('laravel/ai');
        $this->assertNotNull($version);

        return ltrim($version, 'v');
    }
}
