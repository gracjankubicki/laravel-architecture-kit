<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\ProjectGraph;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\GraphCacheSignature;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\PackageFingerprint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GraphCacheSignatureTest extends TestCase
{
    public function test_the_same_configuration_produces_the_same_fingerprint(): void
    {
        $this->assertTrue(
            $this->signature(['app'], [])->isCompatibleWith($this->signature(['app'], [])),
        );
    }

    public function test_a_change_that_reshapes_the_graph_makes_the_entry_incompatible(): void
    {
        // Each of these changes what the builder records, so an entry written before it
        // describes a project that no longer exists in that form. Reparsing a few files
        // cannot repair that, which is why it is a fingerprint and not a file signature.
        $base = $this->signature(['app'], []);

        $this->assertFalse($base->isCompatibleWith($this->signature(['app', 'routes'], [])));
        $this->assertFalse($base->isCompatibleWith($this->signature(['app'], ['thin-controllers'])));
        $this->assertFalse($base->isCompatibleWith($this->signature(['app'], [], 'other-package-version')));
    }

    public function test_the_stat_signature_moves_with_either_half(): void
    {
        // Both halves matter: a rewrite within the same second is common in an agent
        // loop and moves the size even when the timestamp stands still.
        $this->assertNotSame(GraphCacheSignature::stat(100, 10), GraphCacheSignature::stat(101, 10));
        $this->assertNotSame(GraphCacheSignature::stat(100, 10), GraphCacheSignature::stat(100, 11));
        $this->assertSame(GraphCacheSignature::stat(100, 10), GraphCacheSignature::stat(100, 10));
    }

    public function test_a_signature_survives_the_round_trip_through_storage(): void
    {
        $signature = $this->signature(['app'], [], files: ['app/A.php' => '100:10']);
        $restored = GraphCacheSignature::fromArray($signature->toArray());

        $this->assertNotNull($restored);
        $this->assertTrue($signature->isCompatibleWith($restored));
        $this->assertSame($signature->files, $restored->files);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function malformedSignatures(): array
    {
        return [
            'no fingerprint' => [['files' => []]],
            'fingerprint is not a string' => [['fingerprint' => 1, 'files' => []]],
            'no files' => [['fingerprint' => 'abc']],
            'files is not an array' => [['fingerprint' => 'abc', 'files' => 'nope']],
            'a stat is not a string' => [['fingerprint' => 'abc', 'files' => ['app/A.php' => 100]]],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('malformedSignatures')]
    public function test_a_malformed_stored_signature_is_refused_rather_than_guessed(array $data): void
    {
        // A half-read entry must look like no entry at all. Accepting one would mean
        // comparing against a partial file list, and every file missing from it would be
        // treated as unchanged.
        $this->assertNull(GraphCacheSignature::fromArray($data));
    }

    public function test_editing_any_package_source_changes_the_fingerprint(): void
    {
        // The first version of this hashed five hand-picked files and had already missed
        // two that shape the graph. Hashing the tree removes the list, and this proves
        // the mechanism rather than the list: any source, anywhere, at any depth.
        $directory = $this->temporaryPackage();
        $before = PackageFingerprint::of($directory);

        file_put_contents($directory.'/Audit/Deep/Nested/Helper.php', "<?php\n// edited\n");

        $this->assertNotSame($before, PackageFingerprint::of($directory));
    }

    public function test_adding_and_removing_a_source_changes_the_fingerprint(): void
    {
        $directory = $this->temporaryPackage();
        $before = PackageFingerprint::of($directory);

        file_put_contents($directory.'/Audit/Extra.php', "<?php\n");
        $added = PackageFingerprint::of($directory);

        $this->assertNotSame($before, $added);

        unlink($directory.'/Audit/Extra.php');

        $this->assertSame($before, PackageFingerprint::of($directory));
    }

    public function test_the_fingerprint_does_not_depend_on_where_the_package_sits(): void
    {
        // Two checkouts of the same version must agree, or a project would rebuild on
        // every machine and CI would never see a hit.
        $one = $this->temporaryPackage();
        $two = $this->temporaryPackage();

        $this->assertSame(PackageFingerprint::of($one), PackageFingerprint::of($two));
    }

    public function test_the_fingerprint_is_computed_once_per_run(): void
    {
        $current = PackageFingerprint::current();

        $this->assertNotSame('', $current);
        $this->assertSame($current, PackageFingerprint::current());

        PackageFingerprint::forget();

        $this->assertSame($current, PackageFingerprint::current());
    }

    private function temporaryPackage(): string
    {
        $directory = sys_get_temp_dir().'/ak-fingerprint-'.uniqid('', true);
        mkdir($directory.'/Audit/Deep/Nested', 0o777, true);
        file_put_contents($directory.'/Audit/Deep/Nested/Helper.php', "<?php\n// original\n");
        file_put_contents($directory.'/Audit/Top.php', "<?php\n");
        file_put_contents($directory.'/Audit/notes.txt', 'not a source');

        $this->directories[] = $directory;

        return $directory;
    }

    /** @var array<int, string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            $this->deleteDirectory($directory);
        }

        $this->directories = [];

        parent::tearDown();
    }

    private function deleteDirectory(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $entry) {
            is_dir($entry) ? $this->deleteDirectory($entry) : unlink($entry);
        }

        @rmdir($directory);
    }

    /**
     * @param  array<int, string>  $directories
     * @param  array<int, string>  $architectures
     * @param  array<string, string>  $files
     */
    private function signature(
        array $directories,
        array $architectures,
        string $package = 'package-version',
        array $files = [],
    ): GraphCacheSignature {
        return GraphCacheSignature::create(
            [$package, implode(',', $directories), implode(',', $architectures)],
            $files,
        );
    }
}
