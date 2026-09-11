<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\Ast;

use GracjanKubicki\ArchitectureKit\Audit\Ast\PhpAst;
use PhpParser\Node;
use PHPUnit\Framework\TestCase;

final class PhpAstTest extends TestCase
{
    public function test_it_finds_a_node_anywhere_in_a_node_list(): void
    {
        $nodes = PhpAst::parse('<?php function first() {} enum Status: string { case Draft = "draft"; }');

        $this->assertNotNull($nodes);
        $this->assertTrue(PhpAst::containsAny($nodes, fn (Node $node): bool => $node instanceof Node\Stmt\Enum_));
    }

    public function test_it_reports_no_match_without_visiting_a_matching_node(): void
    {
        $nodes = PhpAst::parse('<?php function first() {}');

        $this->assertNotNull($nodes);
        $this->assertFalse(PhpAst::containsAny($nodes, fn (Node $node): bool => $node instanceof Node\Stmt\Enum_));
    }

    public function test_an_empty_node_list_never_matches(): void
    {
        $this->assertFalse(PhpAst::containsAny([], fn (Node $node): bool => true));
    }

    public function test_it_stops_at_the_first_match(): void
    {
        $nodes = PhpAst::parse('<?php enum First: string { case A = "a"; } enum Second: string { case B = "b"; }');
        $this->assertNotNull($nodes);

        $visited = 0;
        $found = PhpAst::containsAny($nodes, function (Node $node) use (&$visited): bool {
            $visited++;

            return $node instanceof Node\Stmt\Enum_;
        });

        $this->assertTrue($found);
        $this->assertSame(1, $visited, 'Traversal continued after the first match.');
    }

    public function test_contains_delegates_to_the_node_list_search(): void
    {
        $nodes = PhpAst::parse('<?php enum Status: string { case Draft = "draft"; }');

        $this->assertNotNull($nodes);
        $this->assertTrue(PhpAst::contains($nodes[0], fn (Node $node): bool => $node instanceof Node\Stmt\Enum_));
    }
}
