<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Scaffolding;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\ArchitectureCatalog;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Shared\FolderPurityRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\ValueObjects\ValueObjectsRule;
use GracjanKubicki\ArchitectureKit\Scaffolding\Scaffolder;
use GracjanKubicki\ArchitectureKit\Scaffolding\ScaffoldException;
use GracjanKubicki\ArchitectureKit\Scaffolding\ScaffoldPlan;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

final class ScaffolderTest extends TestCase
{
    /**
     * A name reaches the scaffolder straight from an agent prompt, so it is untrusted
     * input that decides a filesystem path.
     */
    public function test_it_rejects_a_name_that_would_escape_the_target_folder(): void
    {
        $this->expectException(ScaffoldException::class);
        $this->expectExceptionMessageMatches('/not a valid PHP identifier/');

        $this->plan('actions', '../../../../tmp/Evil');
    }

    public function test_it_rejects_a_name_that_is_not_a_php_identifier(): void
    {
        try {
            $this->plan('actions', 'Bad.Name');
            $this->fail('A name that cannot become a class name must be rejected.');
        } catch (ScaffoldException $exception) {
            $this->assertSame('E_SCAFFOLD_INVALID_NAME', $exception->errorCode);
        }
    }

    public function test_it_rejects_a_data_suffix_for_an_architecture_that_holds_behaviour(): void
    {
        // app/Actions/CreateInvoiceData.php would be written and then immediately fail
        // the folder purity rule, so the generator must refuse it up front.
        try {
            $this->plan('actions', 'CreateInvoiceData');
            $this->fail('A Data suffix inside app/Actions must be rejected.');
        } catch (ScaffoldException $exception) {
            $this->assertSame('E_SCAFFOLD_FORBIDDEN_NAME', $exception->errorCode);
        }
    }

    public function test_it_rejects_a_value_suffix_for_a_value_object(): void
    {
        try {
            $this->plan('value-objects', 'MoneyValue');
            $this->fail('A Value suffix inside app/ValueObjects must be rejected.');
        } catch (ScaffoldException $exception) {
            $this->assertSame('E_SCAFFOLD_FORBIDDEN_NAME', $exception->errorCode);
        }
    }

    /**
     * The Value Object rules reject three suffixes; the generator has to reject the same
     * three, or it emits a file that fails the audit it was built to satisfy.
     */
    public function test_it_rejects_every_suffix_the_value_object_rule_rejects(): void
    {
        foreach (ValueObjectsRule::FORBIDDEN_SUFFIXES as $suffix) {
            try {
                $this->plan('value-objects', 'Money'.$suffix);
                $this->fail("A [{$suffix}] suffix inside app/ValueObjects must be rejected.");
            } catch (ScaffoldException $exception) {
                $this->assertSame('E_SCAFFOLD_FORBIDDEN_NAME', $exception->errorCode);
            }
        }
    }

    public function test_it_rejects_every_suffix_the_folder_purity_rule_reads_as_data(): void
    {
        foreach (FolderPurityRule::NON_BEHAVIOUR_SUFFIXES as $suffix) {
            try {
                $this->plan('actions', 'CreateInvoice'.$suffix);
                $this->fail("A [{$suffix}] suffix inside app/Actions must be rejected.");
            } catch (ScaffoldException $exception) {
                $this->assertSame('E_SCAFFOLD_FORBIDDEN_NAME', $exception->errorCode);
            }
        }
    }

    public function test_it_rejects_a_reserved_php_word(): void
    {
        // `class Class {}` is a parse error, not a rule violation, so it must never be
        // written or handed to an agent as a skeleton.
        try {
            $this->plan('actions', 'Class');
            $this->fail('A reserved PHP word must be rejected.');
        } catch (ScaffoldException $exception) {
            $this->assertSame('E_SCAFFOLD_INVALID_NAME', $exception->errorCode);
        }
    }

    public function test_it_rejects_a_reserved_php_word_in_a_sub_namespace(): void
    {
        try {
            $this->plan('actions', 'List/SendInvoice');
            $this->fail('A reserved PHP word in a sub-namespace must be rejected.');
        } catch (ScaffoldException $exception) {
            $this->assertSame('E_SCAFFOLD_INVALID_NAME', $exception->errorCode);
        }
    }

    public function test_it_rejects_a_name_that_leaves_nothing_to_call_the_class(): void
    {
        // `_` passes the identifier check and then studly removes it entirely.
        try {
            $this->plan('actions', '_');
            $this->fail('A name that yields no class name must be rejected.');
        } catch (ScaffoldException $exception) {
            $this->assertSame('E_SCAFFOLD_INVALID_NAME', $exception->errorCode);
        }
    }

    public function test_it_rejects_a_sub_namespace_that_collapses_to_nothing(): void
    {
        // Accepting it would quietly drop the sub-namespace and write the file to
        // app/Actions/SendInvoice.php, which is not where the caller asked for it.
        try {
            $this->plan('actions', '_/SendInvoice');
            $this->fail('A sub-namespace that yields no segment must be rejected.');
        } catch (ScaffoldException $exception) {
            $this->assertSame('E_SCAFFOLD_INVALID_NAME', $exception->errorCode);
        }
    }

    public function test_a_forbidden_suffix_of_one_architecture_stays_allowed_in_another(): void
    {
        $plan = $this->plan('data-objects', 'CreateInvoice');

        $this->assertSame('CreateInvoiceData', $plan->class);
        $this->assertSame(['app/Data/CreateInvoiceData.php'], $plan->paths());
    }

    public function test_a_sub_namespace_keeps_the_class_inside_the_architecture_folder(): void
    {
        $plan = $this->plan('actions', 'Billing/SendInvoice');

        $this->assertSame('App\\Actions\\Billing', $plan->namespace);
        $this->assertSame(['app/Actions/Billing/SendInvoice.php'], $plan->paths());
    }

    public function test_a_backslash_separator_is_accepted_like_a_slash(): void
    {
        $plan = $this->plan('actions', 'Billing\\SendInvoice');

        $this->assertSame(['app/Actions/Billing/SendInvoice.php'], $plan->paths());
    }

    public function test_it_requires_a_name(): void
    {
        try {
            $this->plan('actions', '   ');
            $this->fail('An empty name must be rejected.');
        } catch (ScaffoldException $exception) {
            $this->assertSame('E_SCAFFOLD_NAME_REQUIRED', $exception->errorCode);
        }
    }

    private function plan(string $architecture, string $name): ScaffoldPlan
    {
        $files = new Filesystem;

        return (new Scaffolder($files, $this->tempPath, new ArchitectureCatalog($files, $this->tempPath)))
            ->plan($architecture, $name, [
                Architecture::Actions,
                Architecture::DataObjects,
                Architecture::ValueObjects,
            ]);
    }
}
