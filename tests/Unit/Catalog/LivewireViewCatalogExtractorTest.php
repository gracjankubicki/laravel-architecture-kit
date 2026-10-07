<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\LivewireViewCatalogExtractor;
use PHPUnit\Framework\TestCase;

final class LivewireViewCatalogExtractorTest extends TestCase
{
    public function test_selectors_preserve_locations_without_retaining_arguments_or_html_decoys(): void
    {
        $source = <<<'BLADE'
{{-- <button wire:click="comment"> --}}
<!-- <button wire:click="htmlComment"> -->
@verbatim <button wire:click="verbatim"> @endverbatim
@php $example = '<button wire:click="phpBlock">'; @endphp
<?php $example = '<button wire:click="nativePhp">'; ?>
<script>const example = '<button wire:click="javascript">';</script>
<style>/* <button wire:click="css"> */</style>
<div title='wire:click="title"' wire:model="name">
<button wire:click="save">Save</button>
<form wire:submit.prevent="save(1, 'private-payload-sentinel', {nested: [1]})"></form>
<div wire:init="load" wire:poll.5s="refresh"></div>
<button wire:click="$parent.save()"></button>
<button wire:click="save(); other()"></button>
<button wire:click="{{ $action }}"></button>
BLADE;
        $facts = (new LivewireViewCatalogExtractor)->extract(new FileContext('resources/views/orders.blade.php', $source));
        $this->assertCount(7, $facts->elements);
        $this->assertSame(['save', 'save', 'load', 'refresh', null, null, null], array_map(fn ($element) => $element->metadata['method'], $facts->elements));
        $this->assertSame(9, $facts->elements[0]->line);
        $this->assertSame(strpos($source, 'wire:click="save"'), $facts->elements[0]->offset);
        $this->assertCount(3, $facts->diagnostics);
        $serialized = json_encode($facts->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('private-payload-sentinel', $serialized);
        $this->assertSame($facts->toArray(), CatalogFacts::fromArray($facts->path, $facts->toArray())->toArray());
    }

    public function test_invalid_or_overly_nested_expressions_remain_unresolved(): void
    {
        foreach (['save([)]', 'save(`template`)', 'save(/* comment */ 1)', 'save('.str_repeat('[', 33).str_repeat(']', 33).')', 'save('.str_repeat('x', 10001).')'] as $expression) {
            $facts = (new LivewireViewCatalogExtractor)->extract(new FileContext('resources/views/orders.blade.php', '<button wire:click="'.$expression.'"></button>'));
            $this->assertCount(1, $facts->elements);
            $this->assertFalse($facts->elements[0]->metadata['resolved']);
            $this->assertCount(1, $facts->diagnostics);
        }
    }

    public function test_inline_php_directive_does_not_hide_following_html(): void
    {
        $source = <<<'BLADE'
@php($value = 1)
<button wire:click="save"></button>
@php $example = '<button wire:click="decoy">'; @endphp
BLADE;
        $facts = (new LivewireViewCatalogExtractor)->extract(new FileContext('resources/views/orders.blade.php', $source));
        $this->assertCount(1, $facts->elements);
        $this->assertSame('save', $facts->elements[0]->metadata['method']);
        $this->assertSame(2, $facts->elements[0]->line);
    }
}
