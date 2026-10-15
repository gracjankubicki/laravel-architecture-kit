<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\Rules;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Enums\EnumsRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\PortsAndAdapters\PortsAndAdaptersRule;
use GracjanKubicki\ArchitectureKit\Classification\ProjectClassification;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

final class DirectoryLookupTest extends TestCase
{
    public function test_enum_lookup_scans_once_and_a_new_audit_observes_file_changes(): void
    {
        $files = $this->files();
        $files->ensureDirectoryExists($this->tempPath.'/app/Enums');
        $files->put($this->tempPath.'/app/Enums/OrderStatus.php', '<?php enum OrderStatus {}');
        $rule = new EnumsRule($files, $this->tempPath, [Architecture::Enums]);
        $order = new FileContext('app/Models/Order.php', '<?php class Order { protected $fillable = ["status", "type"]; }');
        self::assertCount(1, $rule->check($order));
        self::assertCount(1, $rule->check($order));
        self::assertSame(1, $files->scans);
        $files->delete($this->tempPath.'/app/Enums/OrderStatus.php');
        $next = new EnumsRule($files, $this->tempPath, [Architecture::Enums]);
        self::assertSame([], $next->check($order));
        self::assertSame(2, $files->scans);
    }

    public function test_mirror_lookup_preserves_duplicate_file_detection_with_one_scan(): void
    {
        $files = $this->files();
        $files->ensureDirectoryExists($this->tempPath.'/app/Services');
        $files->put($this->tempPath.'/app/Services/Billing.php', '<?php class Billing {}');
        $rule = new PortsAndAdaptersRule($files, $this->tempPath, [Architecture::PortsAndAdapters]);
        $interface = new FileContext('app/Contracts/BillingInterface.php', '<?php interface BillingInterface { public function run(): void; }');
        $first = $rule->check($interface);
        self::assertNotEmpty($first);
        self::assertEquals($first, $rule->check($interface));
        self::assertSame(1, $files->scans);
        $files->ensureDirectoryExists($this->tempPath.'/app/Other');
        $files->put($this->tempPath.'/app/Other/Billing.php', '<?php class Billing {}');
        $next = new PortsAndAdaptersRule($files, $this->tempPath, [Architecture::PortsAndAdapters]);
        self::assertNotEquals($first, $next->check($interface));
        self::assertSame(2, $files->scans);
    }

    public function test_lookup_uses_configured_roots_files_and_deduplicates_overlaps_without_vendor_or_symlinks(): void
    {
        $files = $this->files();
        $files->ensureDirectoryExists($this->tempPath.'/lib/Domain');
        $files->ensureDirectoryExists($this->tempPath.'/lib/Tests');
        $files->ensureDirectoryExists($this->tempPath.'/lib/vendor');
        $files->ensureDirectoryExists($this->tempPath.'/other');
        $files->put($this->tempPath.'/lib/Domain/Port.php', '<?php interface Port {}');
        $files->put($this->tempPath.'/lib/Tests/Double.php', '<?php class Double {}');
        $files->put($this->tempPath.'/lib/vendor/Dependency.php', '<?php class Dependency {}');
        $files->put($this->tempPath.'/other/Included.php', '<?php class Included {}');
        $files->put($this->tempPath.'/other/Excluded.php', '<?php class Excluded {}');
        symlink($this->tempPath.'/other/Excluded.php', $this->tempPath.'/lib/Linked.php');
        $classification = new ProjectClassification($files, $this->tempPath,
            AuditScope::package(['lib', 'lib/Domain', 'other/Included.php']));
        $paths = $classification->sourcePaths();
        sort($paths);
        self::assertSame(['lib/Domain/Port.php', 'other/Included.php'], $paths);
        self::assertEqualsCanonicalizing($paths, $classification->sourcePaths());
        self::assertSame(2, $files->scans);
    }

    private function files(): Filesystem
    {
        return new class extends Filesystem
        {
            public int $scans = 0;

            public function allFiles($directory, $hidden = false)
            {
                $this->scans++;

                return parent::allFiles($directory, $hidden);
            }
        };
    }
}
