<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use RuntimeException;
use Throwable;

/** Process-local root binding. Settings may change, the selected root never does. */
final class RepositoryBinding
{
    private static ?self $active = null;

    private ?string $root = null;

    public readonly string $instanceId;

    public function __construct(
        public readonly string $familyRoot,
        public readonly string $home,
        public readonly ?string $profile = null,
        public readonly bool $execute = false,
        public readonly bool $write = false,
        public readonly bool $runtimeRoutes = false,
    ) {
        GitFamily::inspect($familyRoot);
        Runner::externalHome($home, $familyRoot);
        $this->instanceId = bin2hex(random_bytes(16));
    }

    public static function activate(?self $binding): void
    {
        self::$active = $binding;
    }

    public static function current(): ?self
    {
        return self::$active;
    }

    public function root(): ?string
    {
        return $this->root;
    }

    /** A failed selection/configuration never partially commits a binding. */
    public function bind(string $workspace): void
    {
        $root = (new WorkspaceResolver($this->familyRoot))->resolve($workspace);
        if ($this->root !== null && $this->root !== $root) {
            throw new RuntimeException('context_conflict: this process is already bound to another code root.');
        }
        $context = $this->context($root);
        Host::refresh($context);
        $this->root = $root;
    }

    public function refresh(): RepositoryContext
    {
        if ($this->root === null) {
            throw new RuntimeException('context_required: call repository-context bind with the absolute task workspace first.');
        }
        // Clear the old selection before resolving. Invalid rules cannot reuse it.
        RepositoryContext::activate(null);
        $context = $this->context($this->root);
        Host::refresh($context);

        return $context;
    }

    private function context(string $root): RepositoryContext
    {
        $member = GitFamily::inspect($root);
        if ($member->commonDirectory !== GitFamily::inspect($this->familyRoot)->commonDirectory) {
            throw new RuntimeException('context_invalid: bound root no longer belongs to this family.');
        }

        return new RepositoryContext($root, $this->home, $this->profile, $this->execute, $this->write, $this->runtimeRoutes, advisoryOnly: true);
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $status = ['ok' => true, 'status' => $this->root === null ? 'unbound' : 'bound',
            'code_root' => $this->root, 'instance_id' => $this->instanceId];
        if ($this->root === null) {
            return $status;
        }
        try {
            $context = $this->refresh();

            return [...$status, ...$context->selection->describe(), 'fingerprint' => $context->fingerprint(),
                'permissions' => ['policy' => $context->controlPolicy()->value, 'execute' => $context->execute,
                    'write' => $context->write, 'runtime_routes' => $context->runtimeRoutes, 'deep' => 'requires_repository_task_consent']];
        } catch (Throwable $error) {
            return [...$status, 'ok' => false, 'status' => 'unverified', 'reason' => $error->getMessage()];
        }
    }
}
