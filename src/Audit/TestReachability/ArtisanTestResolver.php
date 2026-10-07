<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\TestReachability;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use GracjanKubicki\ArchitectureKit\Impact\ExecutionLinks;
use GracjanKubicki\ArchitectureKit\Impact\ExecutionSources;
use Illuminate\Filesystem\Filesystem;

/** Uses the same source registration and handler selection as execution impact. */
final class ArtisanTestResolver
{
    private ExecutionLinks $links;

    /** @var list<string> */
    public readonly array $paths;

    private bool $uncertain = false;

    private ?string $uncertainReason = null;

    public function __construct(Filesystem $files, string $basePath, ProjectGraphSnapshot $graph)
    {
        $facts = (new ExecutionSources($files, $basePath))->discover($graph, []);
        $this->paths = array_keys($facts['inputs']);
        $this->links = new ExecutionLinks($facts, externalBoundaries: true);
        $this->uncertain = $facts['limited'] || $this->links->limited;
        foreach ([...$facts['notices'], ...$this->links->notices] as $notice) {
            // Caller uncertainty belongs to that invocation, not to the registry.
            // Known registration conflicts are checked per selected command below.
            if (preg_match('/^(?:Registered console|Console closure|Dynamic console registration|Console command name|Console registration source|Execution |Duplicate execution|Source changed|Only PHP)|memory|unparseable/i', $notice['reason'] ?? '')) {
                $this->uncertain = true;
                $this->uncertainReason ??= $notice['reason'];
            }
        }
    }

    /** @return array{string, string}|string Runtime class and selected method, or uncertainty. */
    public function resolve(TestInvocation $call): array|string
    {
        if ($call->reason !== null || $call->command === null || trim($call->command) === '') {
            return $call->reason ?? 'Artisan command selector is unavailable.';
        }
        if ($this->uncertain) {
            return 'Console registration sources are incomplete or ambiguous. '.($this->uncertainReason ?? 'Source analysis budget exceeded.');
        }
        $name = preg_split('/\s+/', trim($call->command))[0];
        $handlers = [];
        foreach ($this->links->seeds as $seed) {
            if ($seed['kind'] !== 'console' || $seed['command'] !== $name) {
                continue;
            }
            if (! is_string($seed['runtime_class'] ?? null)) {
                return 'Artisan callback command requires unresolved callback reachability.';
            }
            foreach ($this->links->out[strtolower($seed['symbol'])] ?? [] as $edge) {
                if ($edge['kind'] !== 'console-handler') {
                    continue;
                }
                // Conditional declarations cannot prove which handler a test selects.
                if (in_array('Source control-flow condition is not evaluated.', $edge['conditions'], true)
                    || in_array('Source expression condition is not evaluated.', $edge['conditions'], true)) {
                    return 'Artisan command registration is conditional.';
                }
                $handler = $edge['to'];
                if (! str_contains($handler, '::') || str_starts_with($handler, '(')) {
                    return 'Artisan callback command requires unresolved callback reachability.';
                }
                [$class, $method] = explode('::', $handler, 2);
                $runtime = $seed['runtime_class'];
                $handlers[$runtime.'::'.$method] = [$runtime, $method];
            }
        }
        if (count($handlers) !== 1) {
            return $handlers === [] ? 'Artisan command registration or handler is unavailable: '.$name.'.' : 'Artisan command name has conflicting handlers: '.$name.'.';
        }

        return array_values($handlers)[0];
    }
}
