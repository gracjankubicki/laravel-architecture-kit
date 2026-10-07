<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog;

use GracjanKubicki\ArchitectureKit\Catalog\CatalogElement;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class LivewireListenerFactsTest extends TestCase
{
    public function test_corrupt_cached_listener_selectors_are_rejected(): void
    {
        $metadata = ['form' => 'property', 'mode' => 'literal', 'listeners' => [['event' => 'saved', 'method' => 'reload']], 'resolved' => true];
        $element = new CatalogElement('listener-map', 'listeners', 'livewire-listeners', 1, 1, 0, 'property', metadata: $metadata);
        $facts = new CatalogFacts('app/Orders.php', [$element]);
        $this->assertSame($facts->toArray(), CatalogFacts::fromArray($facts->path, $facts->toArray())->toArray());
        foreach ([['resolved' => 'yes'], ['mode' => 'property'], ['mode' => 'dynamic'], ['listeners' => [null]],
            ['listeners' => [['event' => 'orders.{id}', 'method' => 'reload']]], ['listeners' => [['event' => 'saved', 'method' => '$destroy']]],
            ['listeners' => array_fill(0, 129, ['event' => 'saved', 'method' => 'reload'])], ['form' => 'unknown']] as $change) {
            $corrupt = $facts->toArray();
            $corrupt['elements'][0]['metadata'] = array_replace($metadata, $change);
            try {
                CatalogFacts::fromArray($facts->path, $corrupt);
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('Invalid Livewire', $exception->getMessage());

                continue;
            }
            $this->fail('Corrupt listener map was accepted.');
        }
    }
}
