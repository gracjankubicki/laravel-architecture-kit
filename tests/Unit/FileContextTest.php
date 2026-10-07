<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use PhpParser\Node;
use PHPUnit\Framework\TestCase;

final class FileContextTest extends TestCase
{
    public function test_parse_observer_counts_real_attempts_and_resolved_views_do_not_parse_again(): void
    {
        $calls = [];
        $file = new FileContext('app/Observed.php', '<?php class Observed {}', static function (string $path) use (&$calls): void {
            $calls[] = $path;
        });
        $nodes = $file->ast();
        $this->assertNotNull($nodes);
        $file->ast();
        $file->parseError();
        $view = $file->withAst($nodes);
        $this->assertSame($nodes, $view->ast());
        $this->assertSame(['app/Observed.php'], $calls);

        $view->releaseAst();
        $this->assertNotNull($view->ast());
        $this->assertSame(['app/Observed.php', 'app/Observed.php'], $calls);
    }

    public function test_parse_observer_counts_an_invalid_source_attempt_once(): void
    {
        $calls = 0;
        $file = new FileContext('app/Invalid.php', '<?php class {', static function () use (&$calls): void {
            $calls++;
        });
        $this->assertNull($file->ast());
        $this->assertNotNull($file->parseError());
        $this->assertNull($file->ast());
        $this->assertSame(1, $calls);
    }

    public function test_it_resolves_imported_class_names_once_per_parsed_file(): void
    {
        $file = new FileContext('app/Http/Controllers/DocumentController.php', <<<'PHP'
<?php

use Illuminate\Support\Facades\Http as Client;

Client::get('https://example.test');
Client::class;
PHP);

        $nodes = $file->ast();
        $call = $this->firstStaticCall($nodes ?? []);
        $classConstFetch = $this->firstClassConstFetch($nodes ?? []);

        $this->assertNotNull($call);
        $this->assertInstanceOf(Node\Name::class, $call->class);
        $this->assertSame('Illuminate\Support\Facades\Http', $file->resolvedName($call->class));
        $this->assertSame('Illuminate\Support\Facades\Http', $file->resolvedClassName($call));
        $this->assertNotNull($classConstFetch);
        $this->assertSame('Illuminate\Support\Facades\Http', $file->resolvedClassName($classConstFetch));
        $this->assertSame($nodes, $file->ast());
    }

    public function test_release_ast_allows_a_context_to_be_reparsed(): void
    {
        $file = new FileContext('app/Models/Document.php', '<?php final class Document {}');

        $this->assertNotNull($file->ast());

        $file->releaseAst();

        $this->assertNotNull($file->ast());
        $this->assertNull($file->parseError());
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    private function firstStaticCall(array $nodes): ?Node\Expr\StaticCall
    {
        foreach ($nodes as $node) {
            foreach ($node->getSubNodeNames() as $name) {
                $value = $node->{$name};

                if ($value instanceof Node\Expr\StaticCall) {
                    return $value;
                }

                if (is_array($value)) {
                    $call = $this->firstStaticCall(array_values(array_filter($value, fn (mixed $item): bool => $item instanceof Node)));

                    if ($call !== null) {
                        return $call;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    private function firstClassConstFetch(array $nodes): ?Node\Expr\ClassConstFetch
    {
        foreach ($nodes as $node) {
            foreach ($node->getSubNodeNames() as $name) {
                $value = $node->{$name};

                if ($value instanceof Node\Expr\ClassConstFetch) {
                    return $value;
                }

                if (is_array($value)) {
                    $classConstFetch = $this->firstClassConstFetch(array_values(array_filter($value, fn (mixed $item): bool => $item instanceof Node)));

                    if ($classConstFetch !== null) {
                        return $classConstFetch;
                    }
                }
            }
        }

        return null;
    }
}
