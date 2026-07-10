<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use PhpParser\Node;
use PHPUnit\Framework\TestCase;

final class FileContextTest extends TestCase
{
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
