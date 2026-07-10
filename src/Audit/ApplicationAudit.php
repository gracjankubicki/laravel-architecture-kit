<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Actions\ActionsRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\ApiResources\ApiResourcesRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\CustomEloquentBuilders\CustomEloquentBuildersRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\DataObjects\DataObjectsRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\EloquentLifecycle\EloquentLifecycleRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Enums\EnumsRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\FormRequests\FormRequestsRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\LaravelAi\LaravelAiRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\ModernPhp85\ModernPhp85Rule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\PortsAndAdapters\PortsAndAdaptersRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\QueryObjects\QueryObjectsRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Saloon\SaloonRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Services\ServicesRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Shared\FolderPurityRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Shared\ServiceLocatorRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Shared\TestabilityRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\Shared\UnenabledPatternRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\ThinControllers\ThinControllerRule;
use GracjanKubicki\ArchitectureKit\Audit\Rules\ValueObjects\ValueObjectsRule;
use GracjanKubicki\ArchitectureKit\Audit\Suppression\Baseline;
use GracjanKubicki\ArchitectureKit\Audit\Suppression\InlineIgnores;
use Illuminate\Filesystem\Filesystem;
use SplFileInfo;

final class ApplicationAudit
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly string $basePath,
    ) {}

    /**
     * @param  array<int, Architecture|string>  $enabled
     * @param  array<int, string>  $exclude
     * @param  array<int, class-string>|CustomRuleSet  $customRules
     */
    public function run(
        array $enabled,
        bool $changedOnly,
        ?string $baseRef = null,
        array $exclude = [],
        array|CustomRuleSet $customRules = [],
        bool $useBaseline = true,
        bool $updateBaseline = false,
    ): ApplicationAuditResult {
        [$scope, $paths] = $this->applicationFiles($changedOnly, $baseRef);
        $paths = $this->excludePaths($paths, $exclude);
        $findings = [];
        $suppressedInline = 0;
        $customRuleSet = $customRules instanceof CustomRuleSet
            ? $customRules
            : CustomRuleSet::fromGlobal($customRules);
        $customAuditRules = (new RuleRegistry($customRuleSet->rulesFor($enabled)))->customRules();
        $knownRules = $this->knownRules($customRuleSet);
        $rules = array_merge($this->builtInRules($enabled), $customAuditRules);

        foreach ($paths as $path) {
            $contents = $this->files->get($this->absolute($path));
            $file = new FileContext($path, $contents);
            $parseFindings = $this->unparseableFileFindings($file);

            if ($parseFindings !== []) {
                $inlineResult = (new InlineIgnores)->apply($path, $contents, $parseFindings, $knownRules);
                $suppressedInline += $inlineResult->inline;
                array_push($findings, ...$inlineResult->findings);

                continue;
            }

            $fileFindings = [];

            foreach ($rules as $rule) {
                if ($rule->supports($path, $enabled)) {
                    array_push($fileFindings, ...$rule->check($file));
                }
            }

            $inlineResult = (new InlineIgnores)->apply($path, $contents, $fileFindings, $knownRules);
            $suppressedInline += $inlineResult->inline;
            array_push($findings, ...$inlineResult->findings);
        }

        if ($updateBaseline) {
            (new Baseline($this->files, $this->basePath))->write($findings);
        }

        $suppressedBaseline = 0;

        if ($useBaseline) {
            $baselineResult = (new Baseline($this->files, $this->basePath))->apply($findings);
            $findings = $baselineResult->findings;
            $suppressedBaseline = $baselineResult->baseline;
        }

        $findings = $this->withOccurrences($findings);

        usort($findings, function (AuditFinding $left, AuditFinding $right): int {
            return [$left->severityRank(), $left->path, $left->line, $left->rule]
                <=> [$right->severityRank(), $right->path, $right->line, $right->rule];
        });

        return new ApplicationAuditResult(
            scope: $scope,
            findings: $findings,
            suppressedInline: $suppressedInline,
            suppressedBaseline: $suppressedBaseline,
        );
    }

    /**
     * @param  array<int, string>  $paths
     * @param  array<int, string>  $exclude
     * @return array<int, string>
     */
    private function excludePaths(array $paths, array $exclude): array
    {
        if ($exclude === []) {
            return $paths;
        }

        return array_values(array_filter(
            $paths,
            fn (string $path): bool => ! $this->isExcluded($path, $exclude),
        ));
    }

    /**
     * @param  array<int, string>  $exclude
     */
    private function isExcluded(string $path, array $exclude): bool
    {
        foreach ($exclude as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, AuditFinding>
     */
    private function unparseableFileFindings(FileContext $file): array
    {
        if ($file->parseError() === null) {
            return [];
        }

        return [
            $this->finding('warn', 'unparseable-file', $file->path, 1, 'PHP file could not be parsed by Architecture Kit AST audit: '.$file->parseError()),
        ];
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     * @return array<int, AuditRule>
     */
    private function builtInRules(array $enabled): array
    {
        return [
            new FolderPurityRule($enabled),
            new ThinControllerRule($enabled),
            new ServicesRule,
            new ActionsRule,
            new QueryObjectsRule,
            new CustomEloquentBuildersRule,
            new DataObjectsRule,
            new ValueObjectsRule,
            new FormRequestsRule($enabled),
            new EnumsRule($this->files, $this->basePath, $enabled),
            new ApiResourcesRule,
            new PortsAndAdaptersRule($this->files, $this->basePath, $enabled),
            new ModernPhp85Rule,
            new LaravelAiRule,
            new EloquentLifecycleRule($this->files, $this->basePath),
            new SaloonRule($this->files, $this->basePath),
            new ServiceLocatorRule,
            new TestabilityRule,
            new UnenabledPatternRule($enabled),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function knownRules(CustomRuleSet $customRules): array
    {
        $rules = FindingCodeRegistry::ruleIds();

        foreach ($customRules->knownRuleClasses() as $rule) {
            if (! class_exists($rule)) {
                continue;
            }

            $rules[] = str($rule)->classBasename()->kebab()->toString();
        }

        return array_values(array_unique($rules));
    }

    /**
     * @param  array<int, AuditFinding>  $findings
     * @return array<int, AuditFinding>
     */
    private function withOccurrences(array $findings): array
    {
        $counts = [];

        return array_map(function (AuditFinding $finding) use (&$counts): AuditFinding {
            $key = $finding->rule.'|'.$finding->path.'|'.$finding->message;
            $counts[$key] = ($counts[$key] ?? 0) + 1;

            return new AuditFinding(
                severity: $finding->severity,
                rule: $finding->rule,
                path: $finding->path,
                line: $finding->line,
                message: $finding->message,
                occurrence: $counts[$key],
                code: $finding->code,
            );
        }, $findings);
    }

    /**
     * @return array{0: string, 1: array<int, string>}
     */
    private function applicationFiles(bool $changedOnly, ?string $baseRef): array
    {
        if ($changedOnly) {
            $changed = $this->changedApplicationFiles($baseRef);

            if ($changed !== null) {
                $scope = $baseRef === null
                    ? 'changed application files'
                    : 'changed application files since '.$baseRef;

                return [$scope, $changed];
            }
        }

        if (! $this->files->isDirectory($this->basePath.'/app')) {
            return [$changedOnly ? 'all application files (changed scope unavailable)' : 'all application files', []];
        }

        $paths = array_values(array_map(
            fn (SplFileInfo $file): string => $this->relative($file->getPathname()),
            array_filter(
                $this->files->allFiles($this->basePath.'/app'),
                fn (SplFileInfo $file): bool => $file->getExtension() === 'php',
            ),
        ));

        return [$changedOnly ? 'all application files (changed scope unavailable)' : 'all application files', $paths];
    }

    /**
     * @return array<int, string>|null
     */
    private function changedApplicationFiles(?string $baseRef): ?array
    {
        exec('git -C '.escapeshellarg($this->basePath).' rev-parse --show-toplevel', $rootOutput, $rootExitCode);

        if ($rootExitCode !== 0) {
            return null;
        }

        exec('git -C '.escapeshellarg($this->basePath).' rev-parse --show-prefix', $prefixOutput, $prefixExitCode);

        if ($prefixExitCode !== 0) {
            return null;
        }

        $prefix = $prefixOutput[0] ?? '';

        $commands = [];

        if ($baseRef !== null && $baseRef !== '') {
            $mergeBase = $this->mergeBase($baseRef);

            if ($mergeBase === null) {
                return null;
            }

            $commands[] = 'git -C '.escapeshellarg($this->basePath).' diff --name-only --diff-filter=ACMRTUXB '.escapeshellarg($mergeBase).'...HEAD -- app';
            $commands[] = 'git -C '.escapeshellarg($this->basePath).' diff --name-only --diff-filter=ACMRTUXB HEAD -- app';
        } else {
            $commands[] = 'git -C '.escapeshellarg($this->basePath).' diff --name-only --diff-filter=ACMRTUXB HEAD -- app';
        }

        $commands[] = 'git -C '.escapeshellarg($this->basePath).' ls-files --others --exclude-standard -- app';

        $paths = [];

        foreach ($commands as $command) {
            $output = [];
            $exitCode = 0;
            exec($command, $output, $exitCode);

            if ($exitCode !== 0) {
                return null;
            }

            foreach ($output as $path) {
                if ($prefix !== '' && str_starts_with($path, $prefix)) {
                    $path = substr($path, strlen($prefix));
                }

                if (str_ends_with($path, '.php') && $this->files->exists($this->absolute($path))) {
                    $paths[$path] = $path;
                }
            }
        }

        ksort($paths);

        return array_values($paths);
    }

    private function mergeBase(string $baseRef): ?string
    {
        $command = 'git -C '.escapeshellarg($this->basePath).' merge-base '.escapeshellarg($baseRef).' HEAD';
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        if ($exitCode !== 0 || ($output[0] ?? '') === '') {
            return null;
        }

        return $output[0];
    }

    private function finding(string $severity, string $rule, string $path, int $line, string $message): AuditFinding
    {
        return new AuditFinding($severity, $rule, $path, $line, $message);
    }

    private function absolute(string $path): string
    {
        return $this->basePath.'/'.$path;
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace($this->basePath, '', $path), '/');
    }
}
