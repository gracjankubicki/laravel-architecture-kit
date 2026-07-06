<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Taqie\ArchitectureKit\Architecture;

class ArchitecturePlacementTest extends TestCase
{
    public function test_default_placement_is_complete_for_every_architecture(): void
    {
        $expected = [
            Architecture::ThinControllers->value => 'app/Http/Controllers',
            Architecture::FormRequests->value => 'app/Http/Requests',
            Architecture::Actions->value => 'app/Actions',
            Architecture::Services->value => 'app/Services',
            Architecture::QueryObjects->value => 'app/Queries',
            Architecture::CustomEloquentBuilders->value => 'app/Models/Builders',
            Architecture::DataObjects->value => 'app/Data',
            Architecture::ValueObjects->value => 'app/ValueObjects',
            Architecture::Enums->value => null,
            Architecture::ApiResources->value => 'app/Http/Resources',
            Architecture::EloquentLifecycle->value => 'app/Observers, app/Lifecycle',
            Architecture::Saloon->value => 'app/Http/Integrations',
            Architecture::PortsAndAdapters->value => null,
            Architecture::ModernPhp85->value => null,
            Architecture::LaravelAi->value => 'app/Ai',
            Architecture::LaravelBestPractices->value => null,
        ];

        foreach (Architecture::cases() as $architecture) {
            $placement = $architecture->defaultPlacement();

            $this->assertArrayHasKey($architecture->value, $expected);
            $this->assertSame($expected[$architecture->value], $placement);

            if ($placement !== null) {
                $this->assertStringStartsWith('app/', $placement);
            }
        }
    }

    public function test_only_cross_cutting_architectures_have_no_default_placement(): void
    {
        $withoutPlacement = array_values(array_map(
            fn (Architecture $architecture): string => $architecture->value,
            array_filter(
                Architecture::cases(),
                fn (Architecture $architecture): bool => $architecture->defaultPlacement() === null,
            ),
        ));

        $this->assertSame([
            Architecture::Enums->value,
            Architecture::PortsAndAdapters->value,
            Architecture::ModernPhp85->value,
            Architecture::LaravelBestPractices->value,
        ], $withoutPlacement);
    }
}
