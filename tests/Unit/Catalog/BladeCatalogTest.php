<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use PHPUnit\Framework\TestCase;

final class BladeCatalogTest extends TestCase
{
    private function index(string $source): CatalogIndex
    {
        return new CatalogIndex((new ProjectGraphBuilder(catalog: true))->build([new FileContext('resources/views/billing/invoice.blade.php', $source)])->catalogFacts);
    }

    public function test_blade_dependencies_and_exposed_php_keep_source_lines_without_rendering(): void
    {
        $index = $this->index(<<<'BLADE'
@extends('layouts.app')
{{-- @include('comment.decoy') --}}
@includeWhen($enabled && check('a,b'), 'billing.footer')
<x-invoice-card />
{{ view('billing.summary') }}
@php
throw new \RuntimeException('must never execute Blade');
config('billing.currency');
@endphp
@include($dynamic)
<x-dynamic-component :component="$component" />
BLADE);
        $this->assertArrayHasKey(strtolower('billing.invoice'), $index->names);
        $this->assertArrayNotHasKey(strtolower('comment.decoy'), $index->names);
        $extends = array_values(array_filter($index->relations, fn ($row) => $row['kind'] === 'extends-view'))[0];
        $this->assertSame(1, $extends['line']);
        $this->assertSame('layouts.app', $index->elements[$extends['to']]['name']);
        $include = array_values(array_filter($index->relations, fn ($row) => $row['kind'] === 'includes-view'))[0];
        $this->assertSame(3, $include['line']);
        $this->assertSame('conditional', $include['resolution']);
        $this->assertSame('billing.footer', $index->elements[$include['to']]['name']);
        $render = array_values(array_filter($index->relations, fn ($row) => $row['kind'] === 'renders'))[0];
        $this->assertSame(5, $render['line']);
        $this->assertSame('billing.summary', $index->elements[$render['to']]['name']);
        $config = array_values(array_filter($index->relations, fn ($row) => $row['kind'] === 'reads'))[0];
        $this->assertSame(8, $config['line']);
        $this->assertContains('dynamic_view', array_column($index->diagnostics, 'code'));
        $this->assertContains('dynamic_component', array_column($index->diagnostics, 'code'));
        $this->assertNotContains('parse_error', array_column($index->diagnostics, 'code'));
    }

    public function test_escaped_echo_and_native_php_strings_are_not_reinterpreted_as_blade(): void
    {
        $index = $this->index(<<<'BLADE'
@{{ view('escaped.decoy') }}
@@include('escaped.include')
<?php $literal = "@include('php-string.decoy') {{ view('php-echo.decoy') }} <x-decoy />"; ?>
{{ view('actual.view') }}
BLADE);
        $this->assertArrayHasKey('actual.view', $index->names);
        foreach (['escaped.decoy', 'escaped.include', 'php-string.decoy', 'php-echo.decoy', 'decoy'] as $name) {
            $this->assertArrayNotHasKey($name, $index->names);
        }
    }

    public function test_broken_php_fragment_retains_the_known_view_and_reports_partial_analysis(): void
    {
        $index = $this->index("<h1>Invoice</h1>\n{{ invalid + }}");
        $this->assertArrayHasKey('billing.invoice', $index->names);
        $this->assertContains('parse_error', array_column($index->diagnostics, 'code'));
    }
}
