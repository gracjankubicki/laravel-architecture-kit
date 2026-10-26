<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use GracjanKubicki\ArchitectureKit\Analysis\ControlPolicy;
use RuntimeException;
use Throwable;

/** Client-specific protocol adapters, with no transcript or prompt persistence. */
final readonly class AgentHooks
{
    public function __construct(private string $home, private ?string $profile = null, private ?string $project = null, private bool $runtimeRoutes = false, private ?ControlPolicy $policy = null) {}

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function handle(string $client, array $input): array
    {
        if (! in_array($client, ['codex', 'claude'], true)) {
            throw new RuntimeException('Supported clients are codex and claude.');
        }
        $event = $input['hook_event_name'] ?? '';
        $session = $input['session_id'] ?? '';
        $cwd = $input['cwd'] ?? '';
        $stop = $event === 'Stop';
        $retry = ($input['stop_hook_active'] ?? false) === true;
        try {
            if (! is_string($cwd) || $cwd === '') {
                throw new RuntimeException('Hook working directory is missing.');
            }
            try {
                $root = Projects::discover($cwd);
            } catch (RuntimeException) {
                return [];
            }
            if (($this->project !== null && $root !== Projects::discover($this->project)) || ! (new Projects($this->home))->registered($root)) {
                return [];
            }
            if (! is_string($session) || $session === '') {
                throw new RuntimeException('Hook session identifier is missing.');
            }
            $context = new RepositoryContext($root, $this->home, $this->profile, runtimeRoutes: $this->runtimeRoutes);
            RepositoryContext::activate($context);
            $sessions = new StateStore($context->storage.'/sessions', $context->home);
            $sessionKey = hash('sha256', $client.':'.$session);
            $binding = $sessions->read($sessionKey);
            $task = $binding['task'] ?? $client.':'.$session;
            if (! is_string($task)) {
                throw new RuntimeException('Session task binding is corrupt.');
            }
            $policy = $this->policy ?? $context->controlPolicy();
            $tasks = new TaskControl($context, $policy);
            if (in_array($event, ['SessionStart', 'UserPromptSubmit'], true)) {
                $status = $tasks->status($task);
                if ($status['status'] === 'missing') {
                    // A resumed/compacted/forked context cannot invent a new pre-edit beginning.
                    $source = $input['source'] ?? '';
                    if ($event !== 'SessionStart' || $source !== 'startup') {
                        throw new RuntimeException('Resumed task has no beginning. Bind its existing task explicitly.');
                    }
                    $status = $tasks->begin($task);
                }
                $sessions->update($sessionKey, static fn (array $old): array => [...$old, 'task' => $task, 'client' => $client, 'last_event' => $event, 'observed_at' => gmdate('c')]);
                $state = StandaloneAudit::state($context);
                $guideline = $state->resources->fullGuideline($state->enabled);
                $text = 'Architecture Kit task '.$task.'. Status '.$status['status'].'. '.str_replace(' Do not edit until a valid beginning exists.', '', ($status['reason'] ?? '')).' Keep the same task through corrections/resume. Before editing read enabled-architectures and file-rules. Before finishing task-control check and inspect analysis. Basic analysis is advisory by default, including failed or incomplete checks. Try to correct new errors and report remaining errors and unverified checks in the handoff; never repair other authors\' changes automatically. Start another task explicitly, not on every prompt. '.$guideline;
                if (strlen($text) > 16000) {
                    $text = substr($text, 0, 16000)."\nRead architecture-kit://guideline for the full selected guidance.";
                }

                return ['hookSpecificOutput' => ['hookEventName' => $event, 'additionalContext' => $text]];
            }
            if (! $stop) {
                return [];
            }
            $status = $tasks->check($task);
            $sessions->update($sessionKey, static fn (array $old): array => [...$old, 'last_event' => 'Stop', 'observed_at' => gmdate('c'), 'check_status' => $status['status']]);
            $block = ($status['control']['blocking'] ?? false) && $status['status'] !== 'skipped' && ! $retry && ($status['attempts'] ?? 0) < 3;
            $message = 'Architecture Kit: '.$status['status'].($status['ok'] ? '' : ' (unverified)').'. '.str_replace(' Do not edit until a valid beginning exists.', '', ($status['reason'] ?? '')).' Run audit --agent for evidence. Correct new errors or report the blocker. Explicit task skip stays unverified.';

            return $block ? ['decision' => 'block', 'reason' => $message] : ['systemMessage' => $message];
        } catch (Throwable $error) {
            $policy ??= $this->policy ?? ControlPolicy::Advisory;
            $message = 'Architecture Kit unverified: '.$error->getMessage().'. Inspect source and report the remaining uncertainty in the handoff.';

            return $stop
                ? ($policy === ControlPolicy::Enforce && ! $retry ? ['decision' => 'block', 'reason' => $message] : ['systemMessage' => $message])
                : ['hookSpecificOutput' => ['hookEventName' => $event, 'additionalContext' => $message]];
        } finally {
            RepositoryContext::activate(null);
        }
    }

    /** @return array<string, mixed> */
    public function status(string $client, RepositoryContext $context): array
    {
        if (! in_array($client, ['codex', 'claude'], true)) {
            throw new RuntimeException('Unsupported client.');
        }
        $export = (new StateStore($this->home.'/integrations/'.$client.'/'.$context->id, $this->home))->read('status');
        $observed = [];
        foreach (glob($context->storage.'/sessions/*.json') ?: [] as $path) {
            $row = (new StateStore(dirname($path)))->read(basename($path, '.json'));
            if (($row['client'] ?? null) === $client && isset($row['observed_at'])) {
                $observed[] = ['last_event' => $row['last_event'] ?? null, 'observed_at' => $row['observed_at'], 'check_status' => $row['check_status'] ?? null];
            }
        }

        return ['state' => $observed !== [] ? 'observed' : ($export['state'] ?? 'not_exported'), 'active' => false,
            'observations' => $observed, 'reason' => 'Historical observations do not prove hooks are currently installed, trusted or enabled. Verify a real client session.'];
    }

    /** Export data only. It never installs client settings or hooks.
     * @return array<string, mixed>
     */
    public function exportFamily(string $client, string $binary, string $root): array
    {
        if (! in_array($client, ['codex', 'claude'], true)) {
            throw new RuntimeException('Supported clients are codex and claude.');
        }
        if ($this->profile !== null && ! preg_match('/^[a-zA-Z0-9_-]+$/D', $this->profile)) {
            throw new RuntimeException('Invalid local profile name.');
        }
        $family = GitFamily::inspect($root);
        Runner::externalHome($this->home, $family->codeRoot);
        new StateStore($this->home);
        $mcp = ['command' => PHP_BINARY, 'args' => [$binary, '--tool-home', $this->home, '--family', $family->codeRoot,
            ...($this->profile === null ? [] : ['--profile', $this->profile]), 'mcp']];
        $directory = $this->home.'/integrations/'.$client.'/family-'.hash('sha256', $family->commonDirectory);
        $store = new StateStore($directory, $this->home);
        $store->update('mcp', static fn (): array => $mcp);
        $store->update('status', static fn (): array => ['state' => 'exported', 'active' => false, 'mode' => 'family',
            'reason' => 'Install the family MCP explicitly in user settings. Export does not activate it or install hooks.']);

        return ['ok' => true, 'directory' => $directory, 'mode' => 'family', 'state' => 'exported', 'active' => false,
            'family_root' => $family->codeRoot, 'mcp' => $mcp, 'hooks' => []];
    }

    /** @return array<string, mixed> */
    public function familyStatus(string $client, string $root): array
    {
        if (! in_array($client, ['codex', 'claude'], true)) {
            throw new RuntimeException('Supported clients are codex and claude.');
        }
        $family = GitFamily::inspect($root);
        Runner::externalHome($this->home, $family->codeRoot);
        new StateStore($this->home);
        $directory = $this->home.'/integrations/'.$client.'/family-'.hash('sha256', $family->commonDirectory);
        $status = (new StateStore($directory, $this->home))->read('status');

        return ['state' => $status['state'] ?? 'not_exported', 'active' => false, 'mode' => 'family',
            'reason' => 'An export does not prove client installation or a live binding. Verify repository-context status in the actual client.'];
    }

    /** @return array<string, mixed> */
    public function export(string $client, string $binary, string $root): array
    {
        if (! in_array($client, ['codex', 'claude'], true)) {
            throw new RuntimeException('Supported clients are codex and claude.');
        }
        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($binary).' --tool-home '.escapeshellarg($this->home).' --project '.escapeshellarg($root).($this->profile === null ? '' : ' --profile '.escapeshellarg($this->profile)).($this->runtimeRoutes ? ' --runtime-routes' : '').($this->policy === ControlPolicy::Enforce ? ' --enforce' : '').' hook '.$client;
        $hooks = [];
        foreach (['SessionStart', 'UserPromptSubmit', 'Stop'] as $event) {
            $hooks[$event] = [['hooks' => [['type' => 'command', 'command' => $command, 'timeout' => $this->runtimeRoutes ? 300 : 120]]]];
        }
        $mcp = ['command' => PHP_BINARY, 'args' => [$binary, '--tool-home', $this->home, '--project', $root, ...($this->profile === null ? [] : ['--profile', $this->profile]), ...($this->runtimeRoutes ? ['--runtime-routes'] : []), 'mcp']];
        $directory = $this->home.'/integrations/'.$client.'/'.hash('sha256', $root);
        $store = new StateStore($directory, $this->home);
        $store->update('hooks', static fn (): array => ['hooks' => $hooks]);
        $store->update('mcp', static fn (): array => $mcp);
        $store->update('status', static fn (): array => ['state' => 'exported', 'active' => false, 'reason' => 'Install in user settings; Codex hook trust and an observed real session are required. Export is not activation.']);

        return ['ok' => true, 'directory' => $directory, 'state' => 'exported', 'active' => false, 'mcp' => $mcp];
    }
}
