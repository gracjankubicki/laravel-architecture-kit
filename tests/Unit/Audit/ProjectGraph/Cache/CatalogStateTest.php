<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\ProjectGraph\Cache;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\CatalogState;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CatalogStateTest extends TestCase
{
    /** @return array<string, array<mixed>> */
    private function state(): array
    {
        return [
            'elements' => ['element:a' => ['id' => 'element:a', 'name' => 'A', 'kind' => 'class', 'path' => 'app/A.php',
                'line' => 1, 'end_line' => 1, 'offset' => 0, 'parent' => null, 'roles' => [], 'metadata' => [],
                'sources' => [], 'role_evidence' => [], 'knowledge' => 'static']],
            'relations' => [['from' => 'element:a', 'to' => 'element:a', 'kind' => 'uses', 'path' => 'app/A.php',
                'line' => 1, 'end_line' => 1, 'resolution' => 'resolved', 'metadata' => []]],
            'diagnostics' => [['code' => 'fixture', 'message' => 'Fixture', 'subject' => 'element:a']],
            'descriptorReceivers' => [], 'names' => ['a' => ['element:a']], 'out' => ['element:a' => [0]],
            'in' => ['element:a' => [0]], 'hierarchyLimits' => [],
        ];
    }

    /** @return iterable<string, array{string, string, int, bool}> */
    public static function boundaries(): iterable
    {
        foreach (['elements', 'relations', 'diagnostics'] as $field) {
            foreach ([['plain', 30, true], ['plain', 31, false], ['@return:[]', 29, true], ['@return:[]', 30, false]] as [$leaf, $levels, $accepted]) {
                yield $field.'-'.$leaf.'-'.$levels => [$field, $leaf, $levels, $accepted];
            }
        }
    }

    #[DataProvider('boundaries')]
    public function test_unknown_record_fields_keep_the_original_field_relative_depth(string $field, string $leaf, int $levels, bool $accepted): void
    {
        $state = $this->state();
        $value = $leaf;
        for ($i = 0; $i < $levels; $i++) {
            $value = ['nested' => $value];
        }
        $state[$field][array_key_first($state[$field])]['future_field'] = $value;
        if (! $accepted) {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('plain_values');
        }
        CatalogState::validate($state);
        if ($accepted) {
            $this->addToAssertionCount(1);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidValues(): iterable
    {
        foreach (['elements', 'relations', 'diagnostics'] as $field) {
            foreach (['object', 'resource', '@descriptor:absent', '@property:@descriptor:absent#field', '@return:["@descriptor:absent"]', '@method-callable:["@descriptor:absent"]', '@after-argument:["@descriptor:absent"]'] as $value) {
                yield $field.'-'.$value => [$field, $value];
            }
        }
    }

    #[DataProvider('invalidValues')]
    public function test_unknown_record_fields_still_reject_nonplain_data_and_missing_descriptors(string $field, string $kind): void
    {
        $value = match ($kind) {
            'object' => new \stdClass,
            'resource' => fopen('php://memory', 'r+'),
            default => $kind,
        };
        $state = $this->state();
        $state[$field][array_key_first($state[$field])]['future_field'] = ['nested' => $value];
        try {
            CatalogState::validate($state);
            $this->fail('Invalid nested value was accepted');
        } catch (InvalidArgumentException $error) {
            $this->assertStringContainsString('Invalid ready catalog state:', $error->getMessage());
        } finally {
            if (is_resource($value)) {
                fclose($value);
            }
        }
    }

    public function test_shared_descriptors_remain_usable_inside_extra_fields_and_receiver_records(): void
    {
        $state = $this->state();
        $receiver = '@return:["External"]';
        $hash = hash('sha256', $receiver);
        $state['descriptorReceivers'][$hash] = $receiver;
        foreach (['elements', 'relations', 'diagnostics'] as $field) {
            $state[$field][array_key_first($state[$field])]['future_field'] = [
                '@descriptor:'.$hash, '@property:@descriptor:'.$hash.'#field',
                '@return:["@descriptor:'.$hash.'"]', '@method-callable:["@descriptor:'.$hash.'"]',
                '@after-argument:["@descriptor:'.$hash.'"]', null, 1, 1.5, true, '',
            ];
        }
        CatalogState::validate($state);
        $this->addToAssertionCount(1);
    }
}
