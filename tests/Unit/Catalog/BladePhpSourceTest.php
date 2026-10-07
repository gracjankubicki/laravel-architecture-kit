<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Catalog\BladeCatalogExtractor;
use GracjanKubicki\ArchitectureKit\Catalog\PhpCatalogExtractor;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PHPUnit\Framework\TestCase;

final class BladePhpSourceTest extends TestCase
{
    public function test_multiline_echo_and_php_blocks_keep_original_lines_and_byte_offsets(): void
    {
        $source = <<<'SOURCE'
<div>{{
    \App\Read::run()
}}</div>
@php
\App\Write::save();
@endphp
<?php
class Later { public function action() {} }
?>
{!! \App\Raw::read() !!}
SOURCE;
        $file = BladeCatalogExtractor::phpSource(new FileContext('resources/views/mapped.blade.php', $source));
        $this->assertNotNull($file->ast());
        $this->assertSame($source, $file->contents);
        $calls = [];
        $pending = $file->ast();
        while ($pending !== []) {
            $node = array_pop($pending);
            if ($node instanceof Expr\StaticCall) {
                $calls[$node->class->toString()] = [$node->getStartLine(), $node->getStartFilePos(), $node->getEndFilePos()];
            }
            foreach ($node->getSubNodeNames() as $key) {
                foreach (is_array($node->$key) ? $node->$key : [$node->$key] as $child) {
                    if ($child instanceof Node) {
                        $pending[] = $child;
                    }
                }
            }
        }
        foreach (['App\Read' => [2, '\App\Read::run()'], 'App\Write' => [5, '\App\Write::save()'], 'App\Raw' => [10, '\App\Raw::read()']] as $name => [$line, $expression]) {
            $offset = strpos($source, $expression);
            $this->assertSame([$line, $offset, $offset + strlen($expression) - 1], $calls[$name]);
        }
        $facts = (new PhpCatalogExtractor)->extract($file);
        $byName = array_column(array_map(fn ($element) => $element->toArray(), $facts->elements), null, 'name');
        $this->assertSame(8, $byName['Later']['line']);
        $this->assertSame(strpos($source, 'class Later'), $byName['Later']['offset']);
        $this->assertSame(strpos($source, 'public function action'), $byName['Later::action']['offset']);
    }

    public function test_comments_verbatim_and_escaped_directives_do_not_introduce_php_declarations(): void
    {
        $source = <<<'SOURCE'
{{-- <?php class CommentDecoy {} ?> --}}
@verbatim
<?php class VerbatimDecoy {} ?>
{{ \App\Fake::run() }}
@endverbatim
@@php \App\Escaped::run(); @@endphp
<?php class Actual {} ?>
SOURCE;
        $file = BladeCatalogExtractor::phpSource(new FileContext('resources/views/comments.blade.php', $source));
        $this->assertNotNull($file->ast());
        $facts = (new PhpCatalogExtractor)->extract($file);
        $classes = array_values(array_filter($facts->elements, fn ($element) => $element->kind === 'class'));
        $this->assertCount(1, $classes);
        $this->assertSame('Actual', $classes[0]->name);
        $this->assertSame(7, $classes[0]->line);
        $this->assertSame(strpos($source, 'class Actual'), $classes[0]->offset);
    }

    public function test_php_block_strings_with_blade_delimiters_stay_php_strings(): void
    {
        $source = <<<'SOURCE'
@php
$value = '{{ \App\Fake::run() }}';
\App\Actual::run();
@endphp
SOURCE;
        $file = BladeCatalogExtractor::phpSource(new FileContext('resources/views/strings.blade.php', $source));
        $this->assertNotNull($file->ast());
        $facts = (new PhpCatalogExtractor)->extract($file);
        $this->assertSame([], $facts->diagnostics);
        $pending = $file->ast();
        $calls = [];
        while ($pending !== []) {
            $node = array_pop($pending);
            if ($node instanceof Expr\StaticCall) {
                $calls[] = $node;
            }
            foreach ($node->getSubNodeNames() as $key) {
                foreach (is_array($node->$key) ? $node->$key : [$node->$key] as $child) {
                    if ($child instanceof Node) {
                        $pending[] = $child;
                    }
                }
            }
        }
        $this->assertCount(1, $calls);
        $this->assertSame('App\\Actual', $calls[0]->class->toString());
        $this->assertSame(strpos($source, '\\App\\Actual'), $calls[0]->getStartFilePos());
    }
}
