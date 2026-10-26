<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature\Standalone;

use GracjanKubicki\ArchitectureKit\Analysis\ControlPolicy;
use GracjanKubicki\ArchitectureKit\Standalone\AnalysisConsent;
use GracjanKubicki\ArchitectureKit\Standalone\ConfigurationResolver;
use GracjanKubicki\ArchitectureKit\Standalone\GitFamily;
use GracjanKubicki\ArchitectureKit\Standalone\Projects;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use GracjanKubicki\ArchitectureKit\Standalone\StandaloneAudit;
use PHPUnit\Framework\TestCase;

/** Real Git fixture must be created explicitly by the main agent/operator. */
final class WorktreeConfigurationTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $lab;

    protected function setUp(): void
    {
        $path = getenv('ARCHITECTURE_KIT_WORKTREE_MANIFEST');
        if (! is_string($path) || ! is_file($path)) {
            self::markTestSkipped('OPEN: real Git worktree manifest is required; tests never create worktrees.');
        }
        $this->lab = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    protected function tearDown(): void
    {
        RepositoryContext::activate(null);
    }

    private function root(string $group): string
    {
        return $this->lab['roots'][$group]['crm-backend'];
    }

    public function test_real_git_inherits_parent_rules_while_audit_reads_only_worktree(): void
    {
        $a = $this->root('a');
        $main = $this->root('main');
        $family = GitFamily::inspect($a);
        self::assertSame($main, $family->mainRoot);
        self::assertSame($a, Projects::discover($a.'/app'));
        $context = new RepositoryContext($a, $this->lab['home']);
        self::assertTrue($context->selection->inherited());
        self::assertSame($main, $context->selection->ownerRoot);
        RepositoryContext::activate($context);
        $result = StandaloneAudit::run($context);
        self::assertContains('app/Witness.php', $result->auditedPaths);
        self::assertNotContains('app/ParentOnly.php', $result->auditedPaths);
        self::assertStringContainsString('"A"', file_get_contents(RepositoryContext::root('app/Witness.php')));
    }

    public function test_local_named_profile_wins_and_missing_never_uses_default(): void
    {
        $home = $this->lab['home'];
        $mainProfile = (new Projects($home))->register($this->root('main'), ['services'], 'personal')['config'];
        $localProfile = $home.'/profiles/'.hash('sha256', $this->root('a')).'/personal.json';
        $resolver = new ConfigurationResolver($home);
        try {
            self::assertSame($mainProfile, $resolver->resolve($this->root('a'), 'personal')->source);
            (new Projects($home))->register($this->root('a'), ['data-objects'], 'personal');
            self::assertSame(['data-objects'], $resolver->resolve($this->root('a'), 'personal')->settings['enabled']);
            self::assertSame($localProfile, $resolver->resolve($this->root('a'), 'personal')->source);
        } finally {
            @unlink($mainProfile);
            @unlink($localProfile);
        }
    }

    public function test_inherited_policy_and_grants_do_not_cross_roots(): void
    {
        $main = new RepositoryContext($this->root('main'), $this->lab['home']);
        $source = $main->configSource;
        $before = file_get_contents($source);
        try {
            $settings = $main->settings;
            $settings['control'] = ['policy' => 'enforce'];
            file_put_contents($source, json_encode($settings, JSON_THROW_ON_ERROR));
            $main = new RepositoryContext($this->root('main'), $this->lab['home']);
            $a = new RepositoryContext($this->root('a'), $this->lab['home']);
            self::assertSame(ControlPolicy::Enforce, $main->controlPolicy());
            self::assertSame(ControlPolicy::Advisory, $a->controlPolicy());
            RepositoryContext::activate($a);
            self::assertSame(ControlPolicy::Advisory, StandaloneAudit::state($a)->controlPolicy);
            self::assertFalse(StandaloneAudit::state($a)->blockIncompleteAnalysis);
            self::assertFalse($a->execute || $a->write || $a->runtimeRoutes);
            self::assertNotSame($main->storage, $a->storage);
            (new AnalysisConsent($main))->grantDeep('fixture-isolation');
            self::assertFalse((new AnalysisConsent($a))->hasDeep('fixture-isolation'));
        } finally {
            file_put_contents($source, $before);
        }
    }

    public function test_invalid_inherited_policies_are_not_hidden_by_advisory_defaults(): void
    {
        $main = new RepositoryContext($this->root('main'), $this->lab['home']);
        $before = file_get_contents($main->configSource);
        try {
            foreach ([['control' => ['policy' => 'typo']], ['audit' => ['incomplete_analysis' => 'typo']]] as $invalid) {
                file_put_contents($main->configSource, json_encode(array_replace($main->settings, $invalid), JSON_THROW_ON_ERROR));
                try {
                    new RepositoryContext($this->root('a'), $this->lab['home']);
                    self::fail('Invalid source policies must not be silently normalized.');
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('must be', $error->getMessage());
                }
            }
        } finally {
            file_put_contents($main->configSource, $before);
        }
    }

    public function test_external_symlink_is_not_reported_as_audited(): void
    {
        $a = $this->root('a');
        $link = $a.'/app/QAOutside.php';
        symlink($this->root('main').'/app/ParentOnly.php', $link);
        try {
            $context = new RepositoryContext($a, $this->lab['home']);
            RepositoryContext::activate($context);
            $result = StandaloneAudit::run($context);
            self::assertNotContains('app/QAOutside.php', $result->auditedPaths);
            self::assertFalse($result->checksComplete);
            self::assertContains('source_omitted', array_map(static fn ($notice) => $notice->reason, $result->notices));
        } finally {
            unlink($link);
        }
    }

    public function test_git_environment_cannot_redirect_worktree_lookup(): void
    {
        $previous = getenv('GIT_DIR');
        putenv('GIT_DIR='.$this->root('main').'/.git');
        try {
            self::assertSame($this->root('a'), GitFamily::inspect($this->root('a'))->codeRoot);
        } finally {
            $previous === false ? putenv('GIT_DIR') : putenv('GIT_DIR='.$previous);
        }
    }
}
