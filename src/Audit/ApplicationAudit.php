<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit;

use Closure;
use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphLoader;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectRuleSet;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\ControllerReadAudit;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap;
use GracjanKubicki\ArchitectureKit\Audit\Suppression\Baseline;
use GracjanKubicki\ArchitectureKit\Audit\Suppression\InlineIgnores;
use GracjanKubicki\ArchitectureKit\Support\MemoryLimit;
use GracjanKubicki\ArchitectureKit\Support\ProjectPath;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Process\Process;
use Throwable;

final class ApplicationAudit
{
    /**
     * Upper bound of syntax-tree memory per source byte, used as a fast path.
     *
     * Source size alone is a poor predictor because cost follows node density, not
     * byte count: measured across 3244 real files and synthetic extremes, the ratio
     * spans 1.5x for a file dominated by one long string up to 685x for a dense
     * array of short literals. When a file fits even at that upper bound there is no
     * need to inspect it further.
     */
    private const AST_MEMORY_PER_SOURCE_BYTE_CEILING = 700;

    /**
     * Upper bound of tokenizer memory per source byte. Counting tokens allocates too,
     * so the cheap byte-based estimate guards the tokenizer itself. Measured peak was
     * 134x for a dense array of short literals.
     */
    private const TOKENIZER_MEMORY_PER_SOURCE_BYTE = 150;

    /**
     * Syntax-tree memory per token. Token count tracks node count closely: measured
     * 243x to 685x per token across the same samples, so this covers the worst case
     * while staying far below the byte-based ceiling for ordinary code.
     */
    private const AST_MEMORY_PER_TOKEN = 750;

    /**
     * Syntax-tree memory per source byte, added on top of the per-token cost to cover
     * literal payloads that live inside few nodes, such as one very long string.
     */
    private const AST_MEMORY_PER_SOURCE_BYTE = 4;

    public function __construct(
        private readonly Filesystem $files,
        private readonly string $basePath,
        private readonly ?int $memoryLimitBytes = null,
        private readonly float $memoryBudgetRatio = 0.8,
        private readonly ?Closure $memoryUsage = null,
    ) {
        if ($memoryBudgetRatio <= 0 || $memoryBudgetRatio > 1) {
            throw new InvalidArgumentException('Application audit memory budget ratio must be greater than 0 and no greater than 1.');
        }
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     * @param  array<int, string>  $exclude
     * @param  array<int, class-string>|CustomRuleSet  $customRules
     * @param  array<int, string>  $cacheConfiguration
     */
    public function run(
        array $enabled,
        bool $changedOnly,
        ?string $baseRef = null,
        array $exclude = [],
        array|CustomRuleSet $customRules = [],
        bool $useBaseline = true,
        bool $updateBaseline = false,
        ?AuditScope $scope = null,
        MissingTestLevel $missingTestLevel = MissingTestLevel::Off,
        ?ProjectGraphCache $cache = null,
        array $cacheConfiguration = [],
        ?RouteMap $routes = null,
    ): ApplicationAuditResult {
        // The two cannot be set independently: a scope without tests plus an enabled
        // rule would report every class as untested, so the audit resolves the pair
        // itself rather than trusting each caller to keep them consistent.
        $auditScope = ($scope ?? AuditScope::default());
        $auditScope = $missingTestLevel->isEnabled() ? $auditScope->withTests() : $auditScope;
        [$scopeLabel, $focusPaths] = $this->applicationFiles($changedOnly, $baseRef, $auditScope);
        $focusPaths = $this->excludePaths($focusPaths, $exclude);
        $findings = [];
        $suppressedInline = 0;
        $customRuleSet = $customRules instanceof CustomRuleSet
            ? $customRules
            : CustomRuleSet::fromGlobal($customRules);
        $customAuditRules = (new RuleRegistry($customRuleSet->rulesFor($enabled)))->customRules();
        $knownRules = $this->knownRules($customRuleSet);
        $rules = array_merge($this->builtInRules($enabled), $customAuditRules);
        $changedFocusAvailable = $changedOnly && str_starts_with($scopeLabel, 'changed application files');
        $focusPathSet = array_fill_keys($focusPaths, true);
        $focusFiles = [];
        $graphBuilder = new ProjectGraphBuilder;
        $memoryLimitBytes = $this->configuredMemoryLimitBytes();
        $processedFiles = 0;

        /** @var array<string, array<int, AuditFinding>> $findingsByPath */
        $findingsByPath = [];

        $loader = new ProjectGraphLoader($this->files, $this->basePath, $auditScope, $cache, $cacheConfiguration);
        // Decided from stat alone, before a single file is opened. Without a cache every
        // path lands in `toParse`, which is the behaviour this loop always had.
        $plan = $loader->plan($exclude);
        $changed = array_fill_keys($plan->toParse, true);
        $entries = $plan->reusable;

        foreach (array_keys($plan->files) as $path) {
            $inFocus = ! $changedFocusAvailable || isset($focusPathSet[$path]);

            // A file is opened when a rule has to read it or when the graph no longer
            // knows it. `guard --changed` edits a handful of files, so the rest of a
            // large project is restored rather than parsed.
            if (! $inFocus && ! isset($changed[$path])) {
                continue;
            }

            $absolute = $plan->absolutePath($path);

            if ($absolute === null) {
                continue;
            }

            $file = new FileContext($path, $this->files->get($absolute));

            $this->assertMemoryBudget($memoryLimitBytes, $processedFiles);
            $this->assertAstHeadroom($memoryLimitBytes, $file);

            if ($inFocus) {
                $parseFindings = $this->unparseableFileFindings($file);

                if ($parseFindings !== []) {
                    $findingsByPath[$file->path] = $parseFindings;
                } else {
                    $fileFindings = [];

                    foreach ($rules as $rule) {
                        if ($this->appliesTo($rule, $file->path, $auditScope) && $rule->supports($file->path, $enabled)) {
                            array_push($fileFindings, ...$rule->check($file));
                        }
                    }

                    $findingsByPath[$file->path] = $fileFindings;
                }

                $focusFiles[$file->path] = $file;
            }

            $entries[$path] = $graphBuilder->collect($file);

            $processedFiles++;
            $this->assertMemoryBudget($memoryLimitBytes, $processedFiles);
        }

        $graph = $loader->compose($graphBuilder, $plan, $entries);
        // A cache hit can skip the loop entirely, so without this the run would never
        // check its budget on the path where the whole graph arrives at once.
        $this->assertMemoryBudget($memoryLimitBytes, $processedFiles);

        foreach ((new ProjectRuleSet($missingTestLevel))->rules() as $rule) {
            foreach ($rule->check($graph, $enabled, $changedFocusAvailable ? $focusPaths : null) as $finding) {
                $findingsByPath[$finding->path][] = $finding;
            }
        }

        if (in_array(Architecture::ThinControllers, $enabled, true) && in_array(Architecture::Actions, $enabled, true)) {
            $endpointInputs = $changedFocusAvailable
                ? $this->changedApplicationFiles($baseRef, new AuditScope([...$auditScope->directories, 'routes', 'bootstrap', 'config']), includeDeleted: true)
                : null;
            foreach ((new ControllerReadAudit($this->files, $this->basePath))->check($graph, $enabled, $endpointInputs, $routes) as $finding) {
                $findingsByPath[$finding->path][] = $finding;
                // An unchanged controller may be affected by an edited dependency.
                // Keep inline and baseline suppression on the same shared path.
                $focusFiles[$finding->path] ??= new FileContext($finding->path, $this->files->get($this->absolute($finding->path)));
            }
        }

        foreach ($focusFiles as $path => $file) {
            $inlineResult = (new InlineIgnores)->apply($path, $file->contents, $findingsByPath[$path] ?? [], $knownRules);
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
            scope: $scopeLabel,
            findings: $findings,
            suppressedInline: $suppressedInline,
            suppressedBaseline: $suppressedBaseline,
            cacheStatus: $plan->cacheStatus,
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
     * A rule written for application code must not fire inside a test file. Most rules
     * never check the path, so the decision cannot be left to each supports().
     */
    private function appliesTo(AuditRule $rule, string $path, AuditScope $scope): bool
    {
        return ! $scope->isTestPath($path) || $rule instanceof RunsOnTestFiles;
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
        return BuiltInRules::all($this->files, $this->basePath, $enabled);
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
    private function applicationFiles(bool $changedOnly, ?string $baseRef, AuditScope $scope): array
    {
        if ($changedOnly) {
            $changed = $this->changedApplicationFiles($baseRef, $scope);

            if ($changed !== null) {
                $label = $baseRef === null
                    ? 'changed application files'
                    : 'changed application files since '.$baseRef;

                return [$label, $changed];
            }
        }

        $paths = [];

        foreach ($scope->directories as $directory) {
            $absolute = $this->basePath.'/'.$directory;

            if (! $this->files->isDirectory($absolute)) {
                continue;
            }

            array_push($paths, ...array_map(
                fn (SplFileInfo $file): string => $this->relative($file->getPathname()),
                array_filter(
                    $this->files->allFiles($absolute),
                    fn (SplFileInfo $file): bool => $file->getExtension() === 'php',
                ),
            ));
        }

        return [$changedOnly ? 'all application files (changed scope unavailable)' : 'all application files', $paths];
    }

    /**
     * @return array<int, string>|null
     */
    private function changedApplicationFiles(?string $baseRef, AuditScope $scope, bool $includeDeleted = false): ?array
    {
        $prefixOutput = $this->runProcess(['git', '-C', $this->basePath, 'rev-parse', '--show-prefix']);

        if ($prefixOutput === null) {
            return null;
        }

        $prefix = $prefixOutput[0] ?? '';

        $commands = [];
        $pathspec = $scope->directories;
        $filter = $includeDeleted ? '--diff-filter=ACDMRTUXB' : '--diff-filter=ACMRTUXB';

        if ($baseRef !== null && $baseRef !== '') {
            $mergeBase = $this->mergeBase($baseRef);

            if ($mergeBase === null) {
                return null;
            }

            $commands[] = ['git', '-C', $this->basePath, 'diff', '--name-only', $filter, $mergeBase.'...HEAD', '--', ...$pathspec];
            $commands[] = ['git', '-C', $this->basePath, 'diff', '--name-only', $filter, 'HEAD', '--', ...$pathspec];
        } else {
            $commands[] = ['git', '-C', $this->basePath, 'diff', '--name-only', $filter, 'HEAD', '--', ...$pathspec];
        }

        $commands[] = ['git', '-C', $this->basePath, 'ls-files', '--others', '--exclude-standard', '--', ...$pathspec];

        $paths = [];

        foreach ($commands as $command) {
            $output = $this->runProcess($command);

            if ($output === null) {
                return null;
            }

            foreach ($output as $path) {
                if ($prefix !== '' && str_starts_with($path, $prefix)) {
                    $path = substr($path, strlen($prefix));
                }

                if (str_ends_with($path, '.php') && ($includeDeleted || $this->files->exists($this->absolute($path)))) {
                    $paths[$path] = $path;
                }
            }
        }

        ksort($paths);

        return array_values($paths);
    }

    private function mergeBase(string $baseRef): ?string
    {
        $output = $this->runProcess(['git', '-C', $this->basePath, 'merge-base', $baseRef, 'HEAD']);

        if ($output === null || ($output[0] ?? '') === '') {
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
        return ProjectPath::relative($this->basePath, $path);
    }

    /**
     * @param  array<int, string>  $command
     * @return array<int, string>|null
     */
    private function runProcess(array $command): ?array
    {
        try {
            $process = new Process($command, $this->basePath);
            $process->setTimeout(null);
            $process->run();

            if (! $process->isSuccessful()) {
                return null;
            }

            $output = trim($process->getOutput());

            return $output === '' ? [] : (preg_split('/\R/', $output) ?: []);
        } catch (Throwable) {
            return null;
        }
    }

    private function configuredMemoryLimitBytes(): ?int
    {
        return MemoryLimit::bytes($this->memoryLimitBytes);
    }

    private function assertMemoryBudget(?int $memoryLimitBytes, int $processedFiles): void
    {
        if ($memoryLimitBytes === null) {
            return;
        }

        $usageReader = $this->memoryUsage ?? static fn (): int => memory_get_usage(true);
        $usage = $usageReader();
        $budget = (int) floor($memoryLimitBytes * $this->memoryBudgetRatio);

        if ($usage <= $budget) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Audit memory budget exceeded after processing %d file%s (%d bytes used, budget %d). Increase PHP memory_limit or run a smaller audit scope.',
            $processedFiles,
            $processedFiles === 1 ? '' : 's',
            $usage,
            $budget,
        ));
    }

    /**
     * Reject a file whose syntax tree would not fit in the remaining budget, so a
     * single large file cannot exhaust the PHP memory limit while it is parsed.
     */
    private function assertAstHeadroom(?int $memoryLimitBytes, FileContext $file): void
    {
        if ($memoryLimitBytes === null) {
            return;
        }

        $usageReader = $this->memoryUsage ?? static fn (): int => memory_get_usage(true);
        $budget = (int) floor($memoryLimitBytes * $this->memoryBudgetRatio);
        $headroom = $budget - $usageReader();
        $bytes = strlen($file->contents);

        if ($bytes * self::AST_MEMORY_PER_SOURCE_BYTE_CEILING <= $headroom) {
            return;
        }

        $this->assertHeadroomFits(
            $bytes * self::TOKENIZER_MEMORY_PER_SOURCE_BYTE,
            $headroom,
            $budget,
            $file->path,
        );

        $tokens = @token_get_all($file->contents);
        $tokenCount = count($tokens);
        unset($tokens);

        // Tokenizing raises usage, and PHP does not necessarily hand that memory back
        // once the token array is freed, so measure the headroom the parser will really
        // get instead of reusing the value from before tokenization.
        $this->assertHeadroomFits(
            $tokenCount * self::AST_MEMORY_PER_TOKEN + $bytes * self::AST_MEMORY_PER_SOURCE_BYTE,
            $budget - $usageReader(),
            $budget,
            $file->path,
        );
    }

    private function assertHeadroomFits(int $estimate, int $headroom, int $budget, string $path): void
    {
        if ($estimate <= $headroom) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Audit stopped before parsing [%s]: it is estimated to need %d bytes but only %d bytes of the %d byte budget remain. Increase PHP memory_limit, exclude this path, or run a smaller audit scope.',
            $path,
            $estimate,
            max(0, $headroom),
            $budget,
        ));
    }
}
