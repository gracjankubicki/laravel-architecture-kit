<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Resources;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\ArchitectureCatalog;
use GracjanKubicki\ArchitectureKit\EnabledArchitecture;
use GracjanKubicki\ArchitectureKit\LaravelAi\LaravelAiCompatibilityResult;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use SplFileInfo;

final class ArchitectureResources
{
    public const MARKER_PREFIX = GeneratedResourceMarker::PREFIX;

    private readonly ArchitectureCatalog $catalog;

    public function __construct(
        private readonly string $packagePath,
        private readonly string $projectPath,
        private readonly Filesystem $files = new Filesystem,
        ?ArchitectureCatalog $catalog = null,
        private readonly ?LaravelAiCompatibilityResult $laravelAi = null,
    ) {
        $this->catalog = $catalog ?? new ArchitectureCatalog($this->files, $this->projectPath);
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function guideline(array $enabled): GeneratedFile
    {
        $body = implode("\n\n", [
            '## Architecture Kit',
            $this->compactIntro(),
            $this->composition($enabled),
            $this->architectureIndex($enabled),
            $this->compactGlobalRules(),
            $this->beforeFinishing(),
        ]);

        $contents = GeneratedResourceMarker::top($body);

        return new GeneratedFile(
            path: $this->projectPath.'/.ai/guidelines/architecture-kit.md',
            contents: $contents,
        );
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function fullGuideline(array $enabled): string
    {
        return GeneratedResourceMarker::top(implode("\n\n", [
            '## Architecture Kit',
            $this->globalRules(),
            $this->packageFirstRule(),
            $this->testabilityRule(),
            $this->enabledArchitectures($enabled),
            $this->composition($enabled),
            $this->architectureRules($enabled),
        ]));
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function architectureGuideline(EnabledArchitecture|Architecture|string $architecture, array $enabled): string
    {
        $architecture = $this->enabledArchitecture($architecture);

        return implode("\n\n", [
            '## '.$architecture->label(),
            'Status: '.(in_array($architecture->slug(), $this->enabledSlugs($enabled), true) ? 'enabled globally.' : 'available, not enabled globally.'),
            'Skill: `'.$architecture->skillName().'`',
            trim($this->sourceContents($architecture, 'guideline', $enabled)),
        ]);
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     * @return array<string, GeneratedFile>
     */
    public function skills(array $enabled): array
    {
        $skills = [];

        foreach ($this->ordered($enabled) as $architecture) {
            $contents = $this->sourceExists($this->skillSource($architecture))
                ? $this->sourceContents($architecture, 'skill', $enabled)
                : $this->defaultSkill($architecture);
            $this->assertSkillName($architecture, $contents);

            $skills[$architecture->slug()] = new GeneratedFile(
                path: $this->projectPath.'/.ai/skills/'.$architecture->skillName().'/SKILL.md',
                contents: GeneratedResourceMarker::skill($contents),
            );
        }

        return array_merge(
            $skills,
            (new UpgradeGuideResources($this->packagePath, $this->projectPath, $this->files))->skills($enabled),
        );
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     * @return array<int, EnabledArchitecture>
     */
    public function ordered(array $enabled): array
    {
        return $this->catalog->ordered($enabled);
    }

    public function guidelineSource(EnabledArchitecture|Architecture|string $architecture): string
    {
        $architecture = $this->enabledArchitecture($architecture);

        if ($architecture->value === Architecture::LaravelAi) {
            return $this->laravelAiSource('guideline');
        }

        return $this->resolvedSource($architecture->guidelineSource($this->packagePath), 'guideline');
    }

    public function skillSource(EnabledArchitecture|Architecture|string $architecture): string
    {
        $architecture = $this->enabledArchitecture($architecture);

        if ($architecture->value === Architecture::LaravelAi) {
            return $this->laravelAiSource('skill');
        }

        return $this->resolvedSource($architecture->skillSource($this->packagePath), 'skill');
    }

    public function summarySource(EnabledArchitecture|Architecture|string $architecture): string
    {
        $architecture = $this->enabledArchitecture($architecture);

        if ($architecture->value === Architecture::LaravelAi) {
            return $this->laravelAiSource('summary');
        }

        return $this->resolvedSource($architecture->summarySource($this->packagePath), 'summary');
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function summaryFor(EnabledArchitecture|Architecture|string $architecture, array $enabled): string
    {
        $architecture = $this->enabledArchitecture($architecture);
        $source = $this->summarySource($architecture);

        if ($this->sourceExists($source)) {
            return $this->singleLine(trim($this->sourceContents($architecture, 'summary', $enabled)));
        }

        if ($architecture->value instanceof Architecture) {
            throw new RuntimeException("Missing Architecture Kit source resource: {$source}");
        }

        $fallback = $this->firstNonEmptyLine($this->sourceContents($architecture, 'guideline', $enabled));

        return $fallback === ''
            ? 'Custom architecture - expand for details.'
            : $this->truncate($this->singleLine($fallback), 160);
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    public function assertSourcesExist(array $enabled): void
    {
        foreach ($this->ordered($enabled) as $architecture) {
            if (! $this->sourceExists($this->guidelineSource($architecture))) {
                throw new RuntimeException("Missing Architecture Kit source resource: {$this->guidelineSource($architecture)}");
            }

            if ($architecture->value instanceof Architecture && ! $this->sourceExists($this->skillSource($architecture))) {
                throw new RuntimeException("Missing Architecture Kit source resource: {$this->skillSource($architecture)}");
            }

            if ($architecture->value instanceof Architecture && ! $this->sourceExists($this->summarySource($architecture))) {
                throw new RuntimeException("Missing Architecture Kit source resource: {$this->summarySource($architecture)}");
            }
        }

        (new UpgradeGuideResources($this->packagePath, $this->projectPath, $this->files))->skills($enabled);
    }

    /**
     * @return array<string, string>
     */
    public function existingGeneratedSkillPaths(): array
    {
        $basePath = $this->projectPath.'/.ai/skills';

        if (! $this->files->isDirectory($basePath)) {
            return [];
        }

        $paths = [];

        foreach ($this->files->directories($basePath) as $directory) {
            $name = basename($directory);

            if (! str_starts_with($name, 'architecture-kit-')) {
                continue;
            }

            $skillPath = $directory.'/SKILL.md';

            if ($this->files->exists($skillPath) && $this->isGenerated($skillPath)) {
                $paths[$name] = $skillPath;
            }
        }

        return $paths;
    }

    public function isGenerated(string $path): bool
    {
        return $this->files->exists($path)
            && str_contains($this->files->get($path), self::MARKER_PREFIX);
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    private function architectureRules(array $enabled): string
    {
        $sections = ['## Architecture Rules'];

        foreach ($this->ordered($enabled) as $architecture) {
            $guideline = trim($this->sourceContents($architecture, 'guideline', $enabled));

            $sections[] = implode("\n\n", [
                '### '.$architecture->label(),
                'Status: enabled globally.',
                'Skill: `'.$architecture->skillName().'`',
                $guideline,
            ]);
        }

        return implode("\n\n", $sections);
    }

    private function compactIntro(): string
    {
        return implode("\n", [
            'This file is a compact index of enabled Architecture Kit rules. Full rules are available on demand through skills, the MCP tool `architecture-rules`, the MCP resource `architecture-kit://guideline`, or `php artisan architecture-kit:guidelines {slug} --agent`.',
            'Before coding, the first Architecture Kit MCP call MUST be `enabled-architectures`. If MCP is unavailable, read this file or run `php artisan architecture-kit:guidelines --agent` before coding.',
            'Violations are blocked by deterministic audit rules. Load the relevant skill or expanded guideline before implementing or refactoring that architecture.',
        ]);
    }

    private function globalRules(): string
    {
        return <<<'MARKDOWN'
This project MUST follow the enabled Architecture Kit patterns globally.

Before changing application architecture:

- Follow the enabled architecture sections in this file.
- Before coding, call MCP `enabled-architectures` first. Use it to identify enabled patterns and relevant `architecture-kit-*` skills.
- If MCP is unavailable, read `.ai/guidelines/architecture-kit.md` or run `php artisan architecture-kit:guidelines --agent` before coding.
- Do not introduce architecture patterns that are not listed here.
- Follow existing project structure when it is more specific than the default paths below.
- Keep framework adapters thin and keep business decisions in the architecture boundary selected for that behavior.
- Load the listed Architecture Kit skill before implementing or refactoring code for that architecture.
- Before finishing a code change, run `php artisan architecture-kit:guard --changed --strict`.
- In CI or after committing, run `php artisan architecture-kit:guard --changed --base=origin/main --strict`.
- You MUST fix all Architecture Kit guard errors before final response.
- Do not claim the work is done while the guard reports errors.

Architecture folder purity:

- Architecture folders MUST stay type-pure.
- Do not place supporting classes inside another architecture folder.
- `app/Actions/**` contains Actions only.
- `app/Data/**` contains Data Objects, DTOs, and Result objects only.
- `app/ValueObjects/**` contains Value Objects only.
- `app/Enums/**` contains Enums only.
- `app/Queries/**` contains Query Objects only.
- `app/Http/Resources/**` contains API Resources and Resource Collections only.
- `app/Exceptions/**` contains Exceptions only.
- If the project uses domain-first structure, keep the same purity under the domain folder, for example `app/Documents/Actions`, `app/Documents/Data`, `app/Documents/Enums`, and `app/Documents/Exceptions`.
MARKDOWN;
    }

    private function testabilityRule(): string
    {
        return <<<'MARKDOWN'
## Testability Architecture Rule

Architecture Kit code MUST keep dependencies explicit and testable.

- Do not replace `app(SomeClass::class)` with private static factory helpers that return `new SomeClass()`.
- Do not hide collaborators behind `private static function collaborator(): Collaborator`.
- Prefer constructor injection, method injection, or an enabled architecture boundary such as an Action or Query Object.
- If object creation is real domain construction, keep it local and obvious. If it is a service/collaborator dependency, inject it.
- Code should be easy to test by passing test doubles or focused inputs without reaching into the service container or hidden factories.

Bad replacement:

```php
private static function documentTemplate(): ResolveWorkingCaseDocumentTemplate
{
    return new ResolveWorkingCaseDocumentTemplate();
}
```

Better:

```php
public function __construct(
    private ResolveWorkingCaseDocumentTemplate $documentTemplate,
) {
}
```
MARKDOWN;
    }

    private function packageFirstRule(): string
    {
        return <<<'MARKDOWN'
## Package-First Architecture Rule

AI agents MUST NOT implement custom infrastructure before checking existing options.

Before writing new infrastructure, integrations, parsers, validators, clients, workflow engines, importers, exporters, or reusable technical abstractions:

1. Search for an existing Laravel feature that already solves the problem.
2. Search for maintained Laravel ecosystem packages.
3. Search for maintained third-party PHP packages.
4. Prefer the existing feature or package when it fits the project constraints.
5. Implement custom code only when no suitable package exists, the package is not maintained, it does not fit the project constraints, or it cannot safely provide the required behavior.
6. When custom code is chosen, state the reason in the handoff or implementation notes.

This rule does not replace the enabled architecture rules. Any package integration or custom implementation must still follow the enabled Architecture Kit patterns.
MARKDOWN;
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    private function enabledArchitectures(array $enabled): string
    {
        $lines = ['## Enabled Architectures'];

        foreach ($this->ordered($enabled) as $architecture) {
            $lines[] = '- '.$architecture->label().' (`'.$architecture->skillName().'`)';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    private function architectureIndex(array $enabled): string
    {
        $lines = [
            '## Enabled Architecture Index',
            '| Pattern | Folder | Hard rules |',
            '| --- | --- | --- |',
        ];

        foreach ($this->ordered($enabled) as $architecture) {
            $lines[] = '| '.$architecture->label().' | '.$this->placementCell($architecture).' | '.$this->summaryFor($architecture, $enabled).' |';
        }

        return implode("\n", $lines);
    }

    private function placementCell(EnabledArchitecture $architecture): string
    {
        $placement = $architecture->defaultPlacement();

        return $placement === null ? '—' : '`'.$placement.'`';
    }

    private function compactGlobalRules(): string
    {
        return <<<'MARKDOWN'
## Global Rules
- Package-first: check Laravel features, maintained Laravel ecosystem packages, and maintained PHP packages before custom infrastructure; document why custom code was needed.
- Testability: inject collaborators; do not replace `app()` with private static factories or hidden `new SomeClass()` helpers.
- Folder purity: each architecture folder contains only that architecture type; use matching domain subfolders when the project is domain-first.
- Follow the existing project structure when it is more specific than these defaults.
- Keep framework adapters thin and business decisions inside the enabled architecture boundary.
- Before coding, call MCP `enabled-architectures` first; if MCP is unavailable, read this generated guideline or run `php artisan architecture-kit:guidelines --agent`.
- Load the relevant `architecture-kit-*` skill or expanded guideline before changing that pattern.
MARKDOWN;
    }

    private function beforeFinishing(): string
    {
        return <<<'MARKDOWN'
## Before Finishing
Run `php artisan architecture-kit:guard --changed --strict` before handing off work. Fix all errors, and use `php artisan architecture-kit:explain {CODE} --agent` for finding details.
MARKDOWN;
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    private function composition(array $enabled): string
    {
        $enabledValues = array_map(
            fn (Architecture|string $architecture): string => $architecture instanceof Architecture ? $architecture->value : $architecture,
            $enabled,
        );

        $writeFlow = $this->flow([
            Architecture::ThinControllers->value => 'Route -> Controller',
            Architecture::FormRequests->value => 'FormRequest',
            Architecture::DataObjects->value => 'Data Object',
            Architecture::Actions->value => 'Action',
            Architecture::ValueObjects->value => 'Eloquent/Value Objects',
            Architecture::ApiResources->value => 'API Resource',
        ], $enabledValues);

        $readFlow = $this->flow([
            Architecture::ThinControllers->value => 'Route -> Controller',
            Architecture::FormRequests->value => 'FormRequest/Search Data',
            Architecture::DataObjects->value => 'Data Object',
            Architecture::QueryObjects->value => 'Query Object',
            Architecture::CustomEloquentBuilders->value => 'Custom Eloquent Builder',
            Architecture::ApiResources->value => 'API Resource',
        ], $enabledValues);

        return implode("\n\n", [
            '## How These Architectures Compose',
            'Write flow: '.$writeFlow,
            'Read flow: '.$readFlow,
            'Only use a step when the corresponding architecture is enabled and the endpoint needs that responsibility.',
        ]);
    }

    /**
     * @param  array<string, string>  $steps
     * @param  array<int, string>  $enabled
     */
    private function flow(array $steps, array $enabled): string
    {
        return implode(' -> ', array_values(array_filter(
            $steps,
            fn (string $key): bool => in_array($key, $enabled, true),
            ARRAY_FILTER_USE_KEY,
        )));
    }

    private function assertSkillName(EnabledArchitecture $architecture, string $contents): void
    {
        if (! str_contains($contents, 'name: '.$architecture->skillName())) {
            throw new RuntimeException("Skill for [{$architecture->slug()}] must be named [{$architecture->skillName()}].");
        }
    }

    private function defaultSkill(EnabledArchitecture $architecture): string
    {
        return implode("\n", [
            '---',
            'name: '.$architecture->skillName(),
            'description: Follow the '.$architecture->label().' project architecture rules generated by Architecture Kit.',
            '---',
            '',
            '# '.$architecture->label(),
            '',
            trim($this->sourceContents($architecture, 'guideline', [$architecture->value])),
            '',
        ]);
    }

    private function enabledArchitecture(EnabledArchitecture|Architecture|string $architecture): EnabledArchitecture
    {
        return $this->catalog->resolve($architecture);
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    private function sourceContents(EnabledArchitecture $architecture, string $kind, array $enabled): string
    {
        $source = match ($kind) {
            'skill' => $this->skillSource($architecture),
            'summary' => $this->summarySource($architecture),
            default => $this->guidelineSource($architecture),
        };

        if ($this->files->isDirectory($source)) {
            $contents = $this->fragmentContents($source, $enabled);

            if ($kind === 'skill' && ! str_starts_with(trim($contents), "---\n")) {
                throw new RuntimeException("Architecture Kit skill fragment directory [{$source}] must start with a frontmatter fragment.");
            }

            return $this->profiledContents($architecture, $kind, $contents);
        }

        return $this->profiledContents($architecture, $kind, $this->files->get($source));
    }

    private function laravelAiSource(string $kind): string
    {
        if ($kind === 'summary' && ($this->laravelAi === null || ! $this->laravelAi->supported())) {
            return $this->packagePath.'/resources/architectures/laravel-ai/shared/summary.md';
        }

        if ($this->laravelAi === null || ! $this->laravelAi->supported() || $this->laravelAi->profile === null) {
            throw new RuntimeException('Laravel AI resources require a supported compatibility profile. Run php artisan architecture-kit:doctor for diagnostics.');
        }

        $filename = match ($kind) {
            'skill' => 'SKILL.md',
            'summary' => 'summary.md',
            default => 'guideline.md',
        };

        return $this->packagePath.'/resources/architectures/laravel-ai/profiles/'.$this->laravelAi->profile->value.'/'.$filename;
    }

    private function profiledContents(EnabledArchitecture $architecture, string $kind, string $contents): string
    {
        if ($architecture->value !== Architecture::LaravelAi || $kind === 'summary' || $this->laravelAi?->profile === null) {
            return $contents;
        }

        $provenance = implode("\n", [
            '## Compatibility Provenance',
            '',
            'Profile: `'.$this->laravelAi->profile->key().'`',
            'Supported constraint: `'.$this->laravelAi->profile->constraint().'`',
            'Installed version: `'.$this->laravelAi->installedVersion.'`',
        ]);

        if ($kind !== 'skill' || ! str_starts_with(trim($contents), "---\n")) {
            return $provenance."\n\n".ltrim($contents);
        }

        $contents = trim($contents);
        $end = strpos($contents, "\n---", 4);

        if ($end === false) {
            return $provenance."\n\n".$contents;
        }

        $frontmatterEnd = $end + 4;

        return substr($contents, 0, $frontmatterEnd)."\n\n{$provenance}\n\n".ltrim(substr($contents, $frontmatterEnd));
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    private function fragmentContents(string $directory, array $enabled): string
    {
        $fragments = array_values(array_filter(
            array_map(fn (SplFileInfo $file): string => $file->getFilename(), $this->files->files($directory)),
            fn (string $name): bool => str_ends_with($name, '.md'),
        ));

        if ($fragments === []) {
            throw new RuntimeException("Architecture Kit resource fragment directory [{$directory}] must contain at least one .md file.");
        }

        $selected = (new ResourceFragments)->select($this->enabledSlugs($enabled), $fragments);

        return implode("\n\n", array_map(
            fn (string $name): string => trim($this->files->get($directory.'/'.$name)),
            $selected,
        ));
    }

    private function sourceExists(string $source): bool
    {
        if ($this->files->isDirectory($source)) {
            return count(array_filter(
                $this->files->files($source),
                fn (SplFileInfo $file): bool => $file->getExtension() === 'md',
            )) > 0;
        }

        if ($this->files->isFile($source)) {
            return true;
        }

        return false;
    }

    private function resolvedSource(string $fileSource, string $directoryName): string
    {
        if ($this->files->exists($fileSource)) {
            return $fileSource;
        }

        $directorySource = dirname($fileSource).'/'.$directoryName;

        if ($this->files->isDirectory($directorySource)) {
            return $directorySource;
        }

        return $fileSource;
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     * @return array<int, string>
     */
    private function enabledSlugs(array $enabled): array
    {
        return array_map(
            fn (Architecture|string $architecture): string => $architecture instanceof Architecture ? $architecture->value : $architecture,
            $enabled,
        );
    }

    private function singleLine(string $contents): string
    {
        return preg_replace('/\s+/', ' ', $contents) ?? $contents;
    }

    private function firstNonEmptyLine(string $contents): string
    {
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '') {
                return $line;
            }
        }

        return '';
    }

    private function truncate(string $contents, int $limit): string
    {
        if (strlen($contents) <= $limit) {
            return $contents;
        }

        return rtrim(substr($contents, 0, $limit - 3)).'...';
    }
}
