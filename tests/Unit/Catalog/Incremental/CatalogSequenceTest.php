<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog\Incremental;

use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogDependencyState;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogSequence;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogUpdater;
use RuntimeException;

final class CatalogSequenceTest extends IncrementalFixture
{
    public function test_large_watermarks_roundtrip_exactly_and_maximum_refuses_overflow(): void
    {
        $files = ['app/A.php' => '<?php class A {}'];
        $updater = new CatalogUpdater;
        $base = $updater->prepare($this->collect($files), $this->identity(), 'peer', '9000000000000000000');
        $reader = fn ($path) => $files[$path] ?? null;
        $patch = $updater->patch($base, [], [], $this->identity(), 'peer', '9000000000000000001', '9223372036854775807', $reader);
        $next = $patch->apply($base, 'peer', $reader);
        $this->assertSame('9223372036854775807', $next->sequence);
        $codec = new CatalogDependencyState;
        $bytes = $codec->encode($next);
        $this->assertStringContainsString('"sequence":"9223372036854775807"', $bytes);
        $this->assertSame($next->sequence, $codec->decode($bytes, $this->identity())->sequence);
        try {
            $patch->apply($base, 'peer-other-prefix', $reader);
            $this->fail('Peer prefix matched instead of exact session.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('session', $error->getMessage());
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sequence_exhausted');
        $updater->patch($next, [], [], $this->identity(), 'peer', '9223372036854775807', '9223372036854775807', $reader);
    }

    public function test_noncanonical_or_out_of_range_sequence_never_becomes_valid_watermark(): void
    {
        foreach (['01', '-1', '1.0', '1e18', '9223372036854775808', '9000000000000000000garbage', 1, 9e18, null] as $bad) {
            try {
                CatalogSequence::validate($bad);
                $this->fail('Malformed watermark accepted.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('sequence', $error->getMessage());
            }
        }
        $this->assertSame('1000000000000000000', CatalogSequence::next('999999999999999999'));
        $this->assertLessThan(0, CatalogSequence::compare('9', '10'));
        $this->assertLessThan(0, CatalogSequence::compare('9000000000000000000', '9223372036854775807'));
    }
}
