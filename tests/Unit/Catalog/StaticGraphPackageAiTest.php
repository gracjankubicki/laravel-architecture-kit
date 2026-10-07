<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogAiGatewayTypes;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogKinds;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogPackageVersions;
use GracjanKubicki\ArchitectureKit\Catalog\ComposerCatalogExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StaticGraphPackageAiTest extends TestCase
{
    #[DataProvider('inspectedAiVersions')]
    public function test_all_eight_verified_setter_channels_preserve_their_by_value_gateway_argument(string $version, string $reference): void
    {
        $source = 'namespace App;';
        $body = '';
        foreach (['Audio', 'Embedding', 'File', 'Image', 'Reranking', 'Store', 'Text', 'Transcription'] as $channel) {
            $contract = $channel === 'Text' && version_compare($version, '0.9.0', '>=') ? 'StepText' : $channel;
            $source .= 'class '.$channel.'Gateway implements \\Laravel\\Ai\\Contracts\\Gateway\\'.$contract.'Gateway { public function marker() {} }';
            $provider = $channel === 'Reranking' ? '$jina' : '$openai';
            $body .= '$gateway = new '.$channel.'Gateway; '.$provider.'->use'.$channel.'Gateway(gateway: $gateway); $gateway->marker();';
        }
        $source .= 'function run(\\Laravel\\Ai\\Providers\\OpenAiProvider $openai, \\Laravel\\Ai\\Providers\\JinaProvider $jina) { '.$body.' }';
        $index = new CatalogIndex([...$this->facts($source), $this->package($version, reference: $reference)]);
        $markers = array_filter($index->relations, fn ($edge) => $edge['kind'] === 'calls' && str_ends_with($index->elements[$edge['to']]['name'] ?? '', 'Gateway::marker'));
        $this->assertCount(8, $markers);
        $this->assertCount(8, $this->edges($index, 'passes-ai-provider-gateway'));
    }

    #[DataProvider('inspectedAiVersions')]
    public function test_verified_sdk_setter_by_value_argument_survives_repeated_calls_and_source_overrides(string $version, string $reference): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Gateway implements \Laravel\Ai\Contracts\Gateway\FileGateway { public function getFile($provider, $fileId) {} }
class Safe extends \Laravel\Ai\Providers\OpenAiProvider {}
class ByReference extends \Laravel\Ai\Providers\OpenAiProvider { public function useFileGateway(&$gateway) {} }
function run(Safe $provider, ByReference $custom) {
    $gateway = new Gateway;
    $provider->useFileGateway($gateway);
    $provider->useFileGateway(gateway: $gateway);
    $gateway->getFile(null, null);
    $reference = new Gateway;
    $custom->useFileGateway($reference);
    $reference->getFile(null, null);
    $invalid = new Gateway;
    $provider->useFileGateway(wrong: $invalid);
    $invalid->getFile(null, null);
}
SOURCE);
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$cached, $this->package($version, reference: $reference)]);
        $this->assertCount(2, $this->edges($index, 'passes-ai-provider-gateway'));
        $this->assertCount(1, $this->edges($index, 'calls-ai-gateway'));
    }

    public function test_sdk_typed_setter_argument_proof_rejects_known_source_subtype_reference_override(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Gateway implements \Laravel\Ai\Contracts\Gateway\FileGateway { public function getFile($provider, $fileId) {} }
class Override extends \Laravel\Ai\Providers\OpenAiProvider { public function useFileGateway(&$gateway) {} }
function run(\Laravel\Ai\Providers\OpenAiProvider $typed) {
    $gateway = new Gateway;
    $typed->useFileGateway($gateway);
    $gateway->getFile(null, null);
    $exactGateway = new Gateway;
    (new \Laravel\Ai\Providers\OpenAiProvider(null, [], null))->useFileGateway($exactGateway);
    $exactGateway->getFile(null, null);
}
SOURCE);
        $index = new CatalogIndex([...$facts, $this->package()]);
        $operations = $this->edges($index, 'calls-ai-gateway');
        $this->assertCount(1, $operations);
        $this->assertSame(10, $operations[0]['line']);
    }

    public function test_sdk_setter_argument_preservation_requires_verified_package_metadata(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Gateway implements \Laravel\Ai\Contracts\Gateway\FileGateway { public function marker() {} }
function run(\Laravel\Ai\Providers\OpenAiProvider $provider) {
    $gateway = new Gateway;
    $provider->useFileGateway($gateway);
    $gateway->marker();
}
SOURCE);
        $count = fn ($index) => count(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'calls' && ($index->elements[$edge['to']]['name'] ?? null) === 'App\\Gateway::marker'));
        $this->assertSame(1, $count(new CatalogIndex([...$facts, $this->package()])));
        $this->assertSame(0, $count(new CatalogIndex($facts)));
        $this->assertSame(0, $count(new CatalogIndex([...$facts, $this->package('0.12.0')])));
        $this->assertSame(0, $count(new CatalogIndex([...$facts, $this->package(reference: str_repeat('a', 40))])));
    }

    public function test_fluent_channel_history_limit_is_explicit_without_selecting_an_arbitrary_gateway(): void
    {
        $source = 'namespace App;';
        $fileReturns = $audioReturns = '';
        for ($number = 1; $number <= 65; $number++) {
            $source .= 'class File'.$number.' implements \\Laravel\\Ai\\Contracts\\Gateway\\FileGateway {}';
            $source .= 'class Audio'.$number.' implements \\Laravel\\Ai\\Contracts\\Gateway\\AudioGateway {}';
            $fileReturns .= 'if ($choice === '.$number.') { return new File'.$number.'; }';
            $audioReturns .= 'if ($choice === '.$number.') { return new Audio'.$number.'; }';
        }
        $source .= 'function fileChoice($choice) { '.$fileReturns.' } function audioChoice($choice) { '.$audioReturns.' }';
        $source .= 'function run(\\Laravel\\Ai\\Providers\\OpenAiProvider $provider, $choice) { $provider->useFileGateway(fileChoice($choice))->useAudioGateway(audioChoice($choice))->fileGateway(); }';
        $index = new CatalogIndex([...$this->facts($source), $this->package()]);
        $this->assertSame([], $this->edges($index, 'uses-ai-provider-setter-gateway'));
        $selections = $this->edges($index, 'selects-ai-provider-gateway');
        $this->assertCount(1, $selections);
        $this->assertTrue($selections[0]['metadata']['channel_configuration_history_limited']);
        $this->assertContains('AI provider getter configuration history exceeded its source budget.', array_column($index->diagnostics, 'message'));
        $this->assertCount(130, $this->edges($index, 'passes-ai-provider-gateway'));
    }

    #[DataProvider('inspectedAiVersions')]
    public function test_fluent_configuration_keeps_other_channels_replaces_same_channel_and_preserves_factory_alternatives(string $version, string $reference): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class First implements \Laravel\Ai\Contracts\Gateway\FileGateway {}
class Second implements \Laravel\Ai\Contracts\Gateway\FileGateway {}
class Audio implements \Laravel\Ai\Contracts\Gateway\AudioGateway {}
function gateway($condition) { if ($condition) { return new First; } return new Second; }
function run(\Laravel\Ai\Providers\OpenAiProvider $provider) {
    $provider->useFileGateway(new First)->useAudioGateway(new Audio)->fileGateway()->getFile(null, null);
    $saved = $provider->useFileGateway(new First)->useAudioGateway(new Audio)->useFileGateway(new Second)->useAudioGateway(new Audio);
    $copy = $saved;
    $copy->fileGateway()->getFile(null, null);
    $provider->useFileGateway(gateway(false))->useAudioGateway(new Audio)->fileGateway()->getFile(null, null);
}
SOURCE);
        $index = new CatalogIndex([...$facts, $this->package($version, reference: $reference)]);
        $links = $this->edges($index, 'uses-ai-provider-setter-gateway');
        $this->assertCount(4, $links);
        $this->assertSame(['App\\First', 'App\\Second', 'App\\First', 'App\\Second'], array_column(array_column($links, 'metadata'), 'gateway_type'));
        $this->assertCount(3, $this->edges($index, 'calls-ai-gateway'));
        $this->assertSame($links[2]['from'], $links[3]['from']);
        foreach ($links as $edge) {
            $this->assertFalse($edge['metadata']['channel_configuration_history_limited']);
            $this->assertTrue($edge['metadata']['channel_configuration_unchanged_required']);
            $this->assertFalse($edge['metadata']['gateway_selection_proven']);
        }
        foreach (array_slice($links, 2) as $edge) {
            $this->assertNotEmpty($index->elements[$edge['to']]['metadata']['gateway_return_sources']);
        }
    }

    #[DataProvider('inspectedAiVersions')]
    public function test_aggregate_gateway_inherits_only_verified_channel_contracts_and_respects_source_shadow(string $version, string $reference): void
    {
        $facts = $this->facts('namespace App; class Gateway implements \\Laravel\\Ai\\Contracts\\Gateway\\Gateway {} class Fake {}');
        $index = new CatalogIndex([...$facts, $this->package($version, reference: $reference)]);
        $prefix = 'Laravel\\Ai\\Contracts\\Gateway\\';
        $text = version_compare($version, '0.9.0', '<') ? 'Text' : 'StepText';
        foreach (['Audio', 'Embedding', 'Image', 'Transcription', $text] as $channel) {
            $this->assertTrue(CatalogAiGatewayTypes::matches($index, 'App\\Gateway', $prefix.$channel.'Gateway', $version));
            $this->assertTrue(CatalogAiGatewayTypes::matches($index, $prefix.'Gateway', $prefix.$channel.'Gateway', $version));
        }
        foreach (['File', 'Store', 'Reranking', $text === 'Text' ? 'StepText' : 'Text'] as $channel) {
            $this->assertFalse(CatalogAiGatewayTypes::matches($index, 'App\\Gateway', $prefix.$channel.'Gateway', $version));
        }
        $this->assertFalse(CatalogAiGatewayTypes::matches($index, 'App\\Fake', $prefix.$text.'Gateway', $version));
        $shadow = $this->facts('namespace Laravel\\Ai\\Contracts\\Gateway; interface Gateway {}');
        $shadowed = new CatalogIndex([...$facts, ...$shadow, $this->package($version, reference: $reference)]);
        $this->assertFalse(CatalogAiGatewayTypes::matches($shadowed, 'App\\Gateway', $prefix.$text.'Gateway', $version));
    }

    public function test_fluent_channel_override_excludes_constructor_gateway_for_that_channel(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Gateway implements \Laravel\Ai\Contracts\Gateway\Gateway {}
function run() {
    (new \Laravel\Ai\Providers\OpenAiProvider(new Gateway, [], null))->useTextGateway(new Gateway)->textGateway();
    (new \Laravel\Ai\Providers\OpenAiProvider(new Gateway, [], null))->useTextGateway(new Gateway)->imageGateway();
}
SOURCE);
        $index = new CatalogIndex([...$facts, $this->package()]);
        $this->assertCount(1, $this->edges($index, 'uses-ai-provider-setter-gateway'));
        $fallback = $this->edges($index, 'uses-ai-provider-constructor-gateway');
        $this->assertCount(1, $fallback);
        $this->assertSame('imagegateway', strtolower($fallback[0]['metadata']['method']));
        $this->assertNotNull($fallback[0]['metadata']['provider_instance_origin']);
        $this->assertTrue($fallback[0]['metadata']['channel_override_absent_required']);
    }

    #[DataProvider('inspectedAiVersions')]
    public function test_standard_setter_returns_keep_chained_and_saved_provider_receivers(string $version, string $reference): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class FileGateway implements \Laravel\Ai\Contracts\Gateway\FileGateway {}
class AudioGateway implements \Laravel\Ai\Contracts\Gateway\AudioGateway {}
class Fake {}
class Override extends \Laravel\Ai\Providers\OpenAiProvider { public function useFileGateway($gateway) { return new Fake; } }
function run(\Laravel\Ai\Providers\OpenAiProvider $provider, Override $custom) {
    $provider->useFileGateway(new FileGateway)->fileGateway()->getFile(null, null);
    $saved = $provider->useFileGateway(gateway: new FileGateway);
    $copy = $saved;
    $copy->fileGateway()->getFile(null, null);
    $provider->useAudioGateway(new AudioGateway)->useFileGateway(new FileGateway)->fileGateway()->getFile(null, null);
    $provider->useFileGateway(wrong: new FileGateway)->fileGateway()->getFile(null, null);
    $provider->useFileGateway(new Fake)->fileGateway()->getFile(null, null);
    $custom->useFileGateway(new FileGateway)->fileGateway()->getFile(null, null);
    consume($saved);
    $saved->fileGateway()->getFile(null, null);
}
SOURCE);
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$cached, $this->package($version, reference: $reference)]);
        $links = $this->edges($index, 'uses-ai-provider-setter-gateway');
        $this->assertCount(3, $links);
        $this->assertCount(3, $this->edges($index, 'calls-ai-gateway'));
        $this->assertCount(3, $this->edges($index, 'uses-ai-provider-selected-gateway'));
        foreach ($links as $edge) {
            $this->assertSame('App\\FileGateway', $edge['metadata']['gateway_type']);
            $this->assertSame('usefilegateway', strtolower($index->elements[$edge['to']]['metadata']['method']));
            $this->assertTrue($edge['metadata']['setter_return_success_required']);
            $this->assertTrue($edge['metadata']['channel_configuration_unchanged_required']);
            $this->assertFalse($edge['metadata']['gateway_selection_proven']);
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
        $this->assertContains('uses-ai-provider-setter-gateway', CatalogKinds::STRUCTURAL_RELATIONS);
    }

    #[DataProvider('inspectedAiVersions')]
    public function test_constructor_gateway_fallback_requires_same_source_instance_and_standard_channel_getter(string $version, string $reference): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class GatewayA implements \Laravel\Ai\Contracts\Gateway\Gateway {}
class GatewayB implements \Laravel\Ai\Contracts\Gateway\Gateway {}
class GetterOverride extends \Laravel\Ai\Providers\OpenAiProvider { public function textGateway() {} }
class ConstructorOverride extends \Laravel\Ai\Providers\OpenAiProvider { public function __construct($gateway, $config, $events) {} }
function run(\Laravel\Ai\Providers\OpenAiProvider $parameter) {
    $first = new \Laravel\Ai\Providers\OpenAiProvider(new GatewayA, ['key' => 'private-key'], null);
    $copy = $first;
    $second = new \Laravel\Ai\Providers\OpenAiProvider(new GatewayB, [], null);
    $first->textGateway();
    $copy->embeddingGateway();
    $second->textGateway();
    $first->fileGateway();
    $parameter->textGateway();
    $first = new \Laravel\Ai\Providers\OpenAiProvider(new GatewayB, [], null);
    $first->transcriptionGateway();
    (new \Laravel\Ai\Providers\GeminiProvider(new GatewayA, [], null))->imageGateway();
    (new GetterOverride(new GatewayA, [], null))->textGateway();
    (new ConstructorOverride(new GatewayA, [], null))->textGateway();
    (new \Laravel\Ai\Providers\DeepSeekProvider([], null))->textGateway();
}
SOURCE);
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$cached, $this->package($version, reference: $reference)]);
        $links = $this->edges($index, 'uses-ai-provider-constructor-gateway');
        $this->assertCount(5, $links);
        $this->assertSame(['App\\GatewayA', 'App\\GatewayA', 'App\\GatewayB', 'App\\GatewayB', 'App\\GatewayA'], array_column(array_column($links, 'metadata'), 'gateway_type'));
        $this->assertSame($links[0]['to'], $links[1]['to']);
        $this->assertNotSame($links[0]['to'], $links[2]['to']);
        $this->assertNotSame($links[2]['to'], $links[3]['to']);
        foreach ($links as $edge) {
            $this->assertSame('ai-provider-gateway-selection', $index->elements[$edge['from']]['kind']);
            $this->assertSame('ai-provider-gateway-binding', $index->elements[$edge['to']]['kind']);
            $this->assertSame($edge['metadata']['provider_instance_origin'], $index->elements[$edge['to']]['metadata']['provider_instance_origin']);
            $this->assertTrue($edge['metadata']['channel_override_absent_required']);
            $this->assertTrue($edge['metadata']['constructor_return_success_required']);
            $this->assertFalse($edge['metadata']['gateway_selection_proven']);
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertSame('conditional', $edge['resolution']);
        }
        $this->assertContains('uses-ai-provider-constructor-gateway', CatalogKinds::STRUCTURAL_RELATIONS);
        $this->assertStringNotContainsString('private-key', json_encode([$cached, $index], JSON_THROW_ON_ERROR));
    }

    public function test_source_provider_getter_return_body_and_declared_type_keep_distinct_provenance(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Untyped implements \Laravel\Ai\Contracts\Providers\FileProvider {
    public function fileGateway() { return new Gateway; }
}
class Typed implements \Laravel\Ai\Contracts\Providers\FileProvider {
    public $factory;
    public function fileGateway(): \Laravel\Ai\Contracts\Gateway\FileGateway { return ($this->factory)(); }
}
class Gateway implements \Laravel\Ai\Contracts\Gateway\FileGateway {
    public function getFile($provider, $fileId) {}
}
function run(Untyped $untyped, Typed $typed, $remote) {
    $untyped->fileGateway()->getFile($remote, null);
    $typed->fileGateway()->putFile($remote, null);
    $typed->fileGateway(unexpected: null)->deleteFile($remote, null);
}
SOURCE);
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$cached, $this->package()]);
        $this->assertCount(2, $this->edges($index, 'calls-ai-gateway'));
        $links = $this->edges($index, 'uses-ai-provider-selected-gateway');
        $this->assertCount(2, $links);
        $this->assertNotEmpty($links[0]['metadata']['getter_return_sources']);
        $this->assertSame('App\\Untyped::fileGateway', $index->elements[$links[0]['metadata']['getter_return_sources'][0]['producer']]['name']);
        $this->assertSame([], $links[1]['metadata']['getter_return_sources']);
        foreach ($links as $edge) {
            $this->assertTrue($edge['metadata']['getter_return_success_required']);
            $this->assertFalse($edge['metadata']['runtime_gateway_configuration_required']);
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
        $this->assertContains('AI provider getter return lacks a compatible source selection.', array_column($index->diagnostics, 'message'));
    }

    public function test_provider_getter_return_links_chained_and_saved_gateway_calls_without_payloads(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
function run(\Laravel\Ai\Providers\OpenAiProvider $provider, \Laravel\Ai\Contracts\Providers\FileProvider $remote) {
    $provider->fileGateway()->putFile($remote, 'gateway-private-payload');
    $gateway = $provider->fileGateway();
    $copy = $gateway;
    $copy->getFile($remote, 'gateway-private-file-id');
    $provider->fileGateway(unexpected: null)->deleteFile($remote, null);
}
SOURCE);
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$cached, $this->package()]);
        $this->assertCount(2, $this->edges($index, 'calls-ai-gateway'));
        $links = $this->edges($index, 'uses-ai-provider-selected-gateway');
        $this->assertCount(2, $links);
        foreach ($links as $edge) {
            $this->assertSame('ai-provider-gateway-selection', $index->elements[$edge['from']]['kind']);
            $this->assertSame('ai-gateway-operation', $index->elements[$edge['to']]['kind']);
            $this->assertTrue($edge['metadata']['getter_return_success_required']);
            $this->assertFalse($edge['metadata']['gateway_selection_proven']);
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
        $this->assertStringNotContainsString('gateway-private-payload', json_encode(array_map(fn ($fact) => $fact->toArray(), $cached), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('gateway-private-file-id', json_encode($index, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('inspectedAiVersions')]
    public function test_provider_contract_getter_includes_current_sdk_inherited_implementations(string $version, string $reference): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class OpenAi extends \Laravel\Ai\Providers\OpenAiProvider {}
class Azure extends \Laravel\Ai\Providers\AzureOpenAiProvider {}
class Custom implements \Laravel\Ai\Contracts\Providers\FileProvider { public function fileGateway($mode = null) {} }
function run(\Laravel\Ai\Contracts\Providers\FileProvider $provider) {
    $provider->fileGateway();
    $provider->fileGateway(mode: null);
}
SOURCE);
        $index = new CatalogIndex([...$facts, $this->package($version, reference: $reference)]);
        $selected = $this->edges($index, 'selects-ai-provider-gateway');
        $hasAzureFiles = version_compare($version, '0.9.0', '>=');
        $this->assertCount($hasAzureFiles ? 4 : 3, $selected);
        $standard = array_values(array_filter($selected, fn ($edge) => $edge['metadata']['runtime_standard_provider_required']));
        $this->assertCount($hasAzureFiles ? 2 : 1, $standard);
        $expected = ['Laravel\\Ai\\Gateway\\OpenAi\\OpenAiFileGateway'];
        if ($hasAzureFiles) {
            $expected[] = 'Laravel\\Ai\\Gateway\\AzureOpenAi\\AzureOpenAiFileGateway';
        }
        $this->assertSame($expected, array_map(fn ($edge) => $edge['metadata']['default_gateway_candidate']['type'], $standard));
        $this->assertCount(2, $this->edges($index, 'invokes-ai-provider-getter'));
        foreach ($selected as $edge) {
            $this->assertTrue($edge['metadata']['provider_contract_runtime_implementation_required']);
            $this->assertSame('Laravel\\Ai\\Contracts\\Providers\\FileProvider', $edge['metadata']['receiver_provider_contract']);
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
    }

    public function test_provider_base_getter_keeps_source_subtype_helper_dispatch_alternatives(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Base extends \Laravel\Ai\Providers\AzureOpenAiProvider {}
class First extends Base { protected function azureGateway() { return null; } }
class Second extends Base { protected function azureGateway() { return null; } }
function run(\Laravel\Ai\Providers\AzureOpenAiProvider $sdk, Base $base) {
    $sdk->textGateway();
    $base->textGateway();
    (new \Laravel\Ai\Providers\AzureOpenAiProvider([], null))->textGateway();
}
SOURCE);
        $index = new CatalogIndex([...$facts, $this->package()]);
        $this->assertCount(7, $this->edges($index, 'selects-ai-provider-gateway'));
        $factories = $this->edges($index, 'invokes-ai-provider-gateway-factory');
        $this->assertCount(4, $factories);
        foreach ($factories as $edge) {
            $this->assertTrue($edge['metadata']['default_creation_requires_unconfigured_channel']);
            $this->assertTrue($edge['metadata']['source_method_body_controls_default_gateway']);
            $this->assertNull($edge['metadata']['default_gateway_candidate']);
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertContains($edge['metadata']['runtime_provider_type_required'], ['App\\First', 'App\\Second']);
        }
    }

    public function test_provider_contract_getter_selects_compatible_source_implementations_not_similar_names(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class First implements \Laravel\Ai\Contracts\Providers\FileProvider { public function fileGateway($mode = null) {} }
class Second implements \Laravel\Ai\Contracts\Providers\FileProvider { public function fileGateway($fallback = null) {} }
class Child extends First {}
class Decoy { public function fileGateway($mode = null) {} }
class Hidden implements \Laravel\Ai\Contracts\Providers\FileProvider { private function fileGateway() {} }
class Required implements \Laravel\Ai\Contracts\Providers\FileProvider { public function fileGateway($required) {} }
function run(\Laravel\Ai\Contracts\Providers\FileProvider $provider) {
    $provider->fileGateway();
    $provider->fileGateway(mode: null);
}
SOURCE);
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$cached, $this->package()]);
        $selected = $this->edges($index, 'selects-ai-provider-gateway');
        $this->assertCount(3, $selected);
        $source = $this->edges($index, 'invokes-ai-provider-getter');
        $this->assertSame(['App\\First::fileGateway', 'App\\Second::fileGateway', 'App\\First::fileGateway'], array_map(fn ($edge) => $index->elements[$edge['to']]['name'], $source));
        foreach ($selected as $edge) {
            $this->assertTrue($edge['metadata']['provider_contract_runtime_implementation_required']);
            $this->assertTrue($edge['metadata']['source_method_body_controls_selection']);
            $this->assertFalse($edge['metadata']['runtime_standard_provider_required']);
            $this->assertNull($edge['metadata']['default_gateway_candidate']);
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
    }

    public function test_sdk_provider_source_subtype_budget_reports_partial_dispatch(): void
    {
        $facts = [];
        for ($file = 0; $file < 51; $file++) {
            $source = 'namespace App;';
            for ($position = 0; $position < 20 && $file * 20 + $position < 1001; $position++) {
                $source .= 'class Provider'.($file * 20 + $position).' extends \\Laravel\\Ai\\Providers\\OpenAiProvider { public function fileGateway() {} }';
            }
            $facts = [...$facts, ...$this->facts($source, 'app/Providers'.$file.'.php')];
        }
        $facts = [...$facts, ...$this->facts('namespace App; function run(\\Laravel\\Ai\\Providers\\OpenAiProvider $provider) { $provider->fileGateway(); }', 'app/Consumer.php')];
        $index = new CatalogIndex([...$facts, $this->package()]);
        $this->assertCount(1001, $this->edges($index, 'selects-ai-provider-gateway'));
        $this->assertCount(1000, $this->edges($index, 'invokes-ai-provider-getter'));
        $this->assertContains('AI provider source subtype candidates exceeded their 1000-class budget.', array_column($index->diagnostics, 'message'));
    }

    public function test_sdk_typed_provider_includes_current_source_overrides_but_exact_sdk_instance_does_not(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class First extends \Laravel\Ai\Providers\OpenAiProvider { public function fileGateway($mode = null) {} }
class Second extends \Laravel\Ai\Providers\OpenAiProvider { public function fileGateway($mode = null) {} }
function run(\Laravel\Ai\Providers\OpenAiProvider $provider) {
    $provider->fileGateway();
    $provider->fileGateway(mode: null);
    (new \Laravel\Ai\Providers\OpenAiProvider(null, [], null))->fileGateway();
}
SOURCE);
        $index = new CatalogIndex([...$facts, $this->package()]);
        $this->assertCount(6, $this->edges($index, 'selects-ai-provider-gateway'));
        $this->assertCount(4, $this->edges($index, 'invokes-ai-provider-getter'));
        $selected = $this->edges($index, 'selects-ai-provider-gateway');
        $this->assertCount(2, array_filter($selected, fn ($edge) => $edge['metadata']['runtime_standard_provider_required']));
    }

    public function test_provider_getter_subtype_fanout_uses_global_selection_budget(): void
    {
        $source = 'namespace App; class Base extends \\Laravel\\Ai\\Providers\\OpenAiProvider {}';
        for ($type = 0; $type < 10; $type++) {
            $source .= 'class Override'.$type.' extends Base { public function fileGateway() { return null; } }';
        }
        $source .= 'function run(Base $provider) { '.str_repeat('$provider->fileGateway();', 410).' }';
        $index = new CatalogIndex([...$this->facts($source), $this->package()]);
        $this->assertCount(4096, $this->edges($index, 'selects-ai-provider-gateway'));
        $this->assertContains('AI provider getter selections exceeded their source budget.', array_column($index->diagnostics, 'message'));
    }

    public function test_provider_getter_sdk_and_source_subtype_branches_have_separate_conditions(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Base extends \Laravel\Ai\Providers\OpenAiProvider {}
class First extends Base { public function fileGateway($mode = null) { return null; } }
class Second extends Base { public function fileGateway($mode = null) { return null; } }
function run(Base $provider) {
    $provider->fileGateway();
    $provider->fileGateway(mode: null);
    (new Base([], [], null))->fileGateway();
}
SOURCE);
        $index = new CatalogIndex([...$facts, $this->package()]);
        $selected = $this->edges($index, 'selects-ai-provider-gateway');
        $this->assertCount(6, $selected);
        $standard = array_values(array_filter($selected, fn ($edge) => $edge['metadata']['runtime_standard_provider_required']));
        $this->assertCount(2, $standard);
        $source = $this->edges($index, 'invokes-ai-provider-getter');
        $this->assertCount(4, $source);
        $this->assertSame(['App\\First::fileGateway', 'App\\Second::fileGateway', 'App\\First::fileGateway', 'App\\Second::fileGateway'], array_map(fn ($edge) => $index->elements[$edge['to']]['name'], $source));
        foreach ($source as $edge) {
            $this->assertTrue($edge['metadata']['source_method_body_controls_selection']);
            $this->assertFalse($edge['metadata']['runtime_standard_provider_required']);
            $this->assertNull($edge['metadata']['default_gateway_candidate']);
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
    }

    public function test_provider_default_gateway_source_shadow_is_explicit_and_not_an_sdk_factory(): void
    {
        $consumer = $this->facts('namespace App; function run(\\Laravel\\Ai\\Providers\\OpenAiProvider $provider) { $provider->fileGateway(); }', 'app/Consumer.php');
        $shadow = $this->facts('namespace Laravel\\Ai\\Gateway\\OpenAi; class OpenAiFileGateway {}', 'app/Gateway.php');
        $index = new CatalogIndex([...$consumer, ...$shadow, $this->package()]);
        $selected = $this->edges($index, 'selects-ai-provider-gateway');
        $this->assertCount(1, $selected);
        $this->assertNull($selected[0]['metadata']['default_gateway_candidate']);
        $this->assertTrue($selected[0]['metadata']['default_gateway_resolution_required']);
        $this->assertFalse($selected[0]['metadata']['default_creation_requires_unconfigured_channel']);
        $this->assertContains('AI provider default gateway class is source-shadowed.', array_column($index->diagnostics, 'message'));
        $this->assertSame([], $this->edges($index, 'invokes-ai-provider-gateway-factory'));
        $plain = new CatalogIndex([...$consumer, $this->package()]);
        $selected = $this->edges($plain, 'selects-ai-provider-gateway');
        $this->assertFalse($selected[0]['metadata']['default_gateway_resolution_required']);
        $this->assertSame('Laravel\\Ai\\Gateway\\OpenAi\\OpenAiFileGateway', $selected[0]['metadata']['default_gateway_candidate']['type']);
    }

    public function test_source_provider_getter_binds_named_positional_and_variadic_arguments_without_values(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Own extends \Laravel\Ai\Providers\OpenAiProvider {
    public function fileGateway($mode, $fallback = null) { return null; }
}
class Variadic extends \Laravel\Ai\Providers\OpenAiProvider {
    public function fileGateway($mode, ...$options) { return null; }
}
function run(Own $provider, Variadic $variadic, \Laravel\Ai\Providers\GeminiProvider $sdk, $args) {
    $provider->fileGateway('getter-private-mode');
    $provider->fileGateway(mode: 'getter-private-mode');
    $provider->fileGateway(fallback: null, mode: 'getter-private-mode');
    $variadic->fileGateway(mode: 'getter-private-mode', other: null);
    $variadic->fileGateway('getter-private-mode', null, null);
    $provider->fileGateway();
    $provider->fileGateway(unknown: null);
    $provider->fileGateway(...$args);
    $provider->fileGateway(null, null, null);
    $sdk->fileGateway(mode: null);
}
SOURCE);
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$cached, $this->package()]);
        $this->assertCount(5, $this->edges($index, 'selects-ai-provider-gateway'));
        $invoked = $this->edges($index, 'invokes-ai-provider-getter');
        $this->assertCount(5, $invoked);
        foreach ($invoked as $edge) {
            $this->assertTrue($edge['metadata']['source_parameter_names_verified']);
            $this->assertTrue($edge['metadata']['runtime_argument_binding_required']);
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertNull($edge['metadata']['default_gateway_candidate']);
        }
        $this->assertStringNotContainsString('getter-private-mode', json_encode(array_map(fn ($fact) => $fact->toArray(), $cached), JSON_THROW_ON_ERROR));
        $this->assertContains('package_ai_analysis', array_column($index->diagnostics, 'code'));

    }

    #[DataProvider('inspectedAiVersions')]
    public function test_default_provider_gateway_candidates_are_version_gated_and_conditional(string $version, string $reference): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
function run(\Laravel\Ai\Providers\OpenAiProvider $openai, \Laravel\Ai\Providers\GeminiProvider $gemini,
    \Laravel\Ai\Providers\AzureOpenAiProvider $azure, \Laravel\Ai\Providers\GroqProvider $groq, \Laravel\Ai\Providers\MistralProvider $mistral) {
    $openai->fileGateway();
    $gemini->storeGateway();
    $azure->fileGateway();
    $groq->transcriptionGateway();
    $mistral->audioGateway();
}
SOURCE);
        $index = new CatalogIndex([...$facts, $this->package($version, reference: $reference)]);
        $expected = ['Laravel\\Ai\\Gateway\\OpenAi\\OpenAiFileGateway', 'Laravel\\Ai\\Gateway\\Gemini\\GeminiStoreGateway'];
        if (version_compare($version, '0.9.0', '>=')) {
            $expected[] = 'Laravel\\Ai\\Gateway\\AzureOpenAi\\AzureOpenAiFileGateway';
        }
        if (version_compare($version, '0.11.0', '>=')) {
            $expected[] = 'Laravel\\Ai\\Gateway\\Groq\\GroqGateway';
        }
        if (version_compare($version, '0.11.1', '>=')) {
            $expected[] = 'Laravel\\Ai\\Gateway\\Mistral\\MistralGateway';
        }
        $selected = $this->edges($index, 'selects-ai-provider-gateway');
        $this->assertSame($expected, array_map(fn ($edge) => $edge['metadata']['default_gateway_candidate']['type'], $selected));
        foreach ($selected as $edge) {
            $this->assertTrue($edge['metadata']['default_creation_requires_unconfigured_channel']);
            $this->assertTrue($edge['metadata']['configured_gateway_reuse_possible']);
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
    }

    public function test_default_provider_gateway_helper_override_prevents_sdk_factory_inference(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Own extends \Laravel\Ai\Providers\AzureOpenAiProvider {
    protected function azureGateway() { return new CustomGateway; }
}
function run(Own $provider) { $provider->textGateway(); }
SOURCE);
        $index = new CatalogIndex([...$facts, $this->package()]);
        $selected = $this->edges($index, 'selects-ai-provider-gateway');
        $this->assertCount(1, $selected);
        $this->assertNull($selected[0]['metadata']['default_gateway_candidate']);
        $this->assertTrue($selected[0]['metadata']['default_gateway_resolution_required']);
        $factory = $this->edges($index, 'invokes-ai-provider-gateway-factory');
        $this->assertCount(1, $factory);
        $this->assertSame('App\\Own::azureGateway', $index->elements[$factory[0]['to']]['name']);
        $this->assertTrue($factory[0]['metadata']['default_creation_requires_unconfigured_channel']);
        $this->assertTrue($factory[0]['metadata']['source_method_body_controls_default_gateway']);
        $this->assertFalse($factory[0]['metadata']['execution_proven']);
        $this->assertContains('package_ai_analysis', array_column($index->diagnostics, 'code'));
        $shadow = $this->facts('namespace Laravel\\Ai\\Gateway\\AzureOpenAi; class AzureOpenAiGateway {}', 'app/AzureShadow.php');
        $withShadow = new CatalogIndex([...$facts, ...$shadow, $this->package()]);
        $factory = $this->edges($withShadow, 'invokes-ai-provider-gateway-factory');
        $this->assertCount(1, $factory);
        $this->assertSame('App\\Own::azureGateway', $withShadow->elements[$factory[0]['to']]['name']);
    }

    public function test_provider_gateway_factory_invalid_source_signature_is_not_invoked(): void
    {
        foreach (['private function azureGateway() {}', 'protected static function azureGateway() {}', 'protected function azureGateway($required) {}', 'abstract protected function azureGateway();'] as $method) {
            $facts = $this->facts('namespace App; abstract class Own extends \\Laravel\\Ai\\Providers\\AzureOpenAiProvider { '.$method.' } function run(Own $provider) { $provider->textGateway(); }');
            $index = new CatalogIndex([...$facts, $this->package()]);
            $this->assertSame([], $this->edges($index, 'invokes-ai-provider-gateway-factory'));
            $selected = $this->edges($index, 'selects-ai-provider-gateway');
            $this->assertCount(1, $selected);
            $this->assertNull($selected[0]['metadata']['default_gateway_candidate']);
            $this->assertTrue($selected[0]['metadata']['default_gateway_resolution_required']);
        }
    }

    public function test_provider_getter_budget_is_global_across_files(): void
    {
        $facts = [];
        for ($file = 0; $file < 65; $file++) {
            $calls = str_repeat('$provider->fileGateway();', 64);
            $facts = [...$facts, ...$this->facts('namespace App; function run'.$file.'(\\Laravel\\Ai\\Providers\\OpenAiProvider $provider) { '.$calls.' }', 'app/Consumer'.$file.'.php')];
        }
        $index = new CatalogIndex([...$facts, $this->package()]);
        $this->assertCount(4096, $this->edges($index, 'selects-ai-provider-gateway'));
        $this->assertContains('catalog_limit', array_column($index->diagnostics, 'code'));
    }

    #[DataProvider('inspectedAiVersions')]
    public function test_provider_getter_selection_keeps_source_overrides_and_runtime_configuration_explicit(string $version, string $reference): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Own extends \Laravel\Ai\Providers\OpenAiProvider {}
class Override extends Own { public function fileGateway($optional = null) { return new Gateway; } }
class Required extends Own { public function fileGateway($required) { return new Gateway; } }
class Hidden extends Own { private function fileGateway() { return new Gateway; } }
class Unrelated { public function fileGateway() { return new Gateway; } }
class Gateway implements \Laravel\Ai\Contracts\Gateway\FileGateway {}
function run(\Laravel\Ai\Providers\OpenAiProvider $standard, Own $inherited, Override $override, Required $required, Hidden $hidden, Unrelated $other) {
    $standard->fileGateway();
    $inherited->textGateway();
    $override->fileGateway();
    $required->fileGateway();
    $hidden->fileGateway();
    $other->fileGateway();
    $standard->fileGateway(unexpected: null);
}
SOURCE);
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$cached, $this->package($version, reference: $reference)]);
        $edges = $this->edges($index, 'selects-ai-provider-gateway');
        $this->assertCount(4, $edges);
        $standard = array_values(array_filter($edges, fn ($edge) => $edge['metadata']['runtime_standard_provider_required']));
        $this->assertCount(2, $standard);
        foreach ($standard as $edge) {
            $this->assertTrue($edge['metadata']['configured_gateway_reuse_possible']);
            $this->assertTrue($edge['metadata']['runtime_gateway_configuration_required']);
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertFalse($edge['metadata']['provider_traffic_proven']);
        }
        $source = $this->edges($index, 'invokes-ai-provider-getter');
        $this->assertCount(2, $source);
        $this->assertSame('App\\Override::fileGateway', $index->elements[$source[0]['to']]['name']);
        $this->assertTrue($source[0]['metadata']['source_method_body_controls_selection']);
        $this->assertContains('package_ai_analysis', array_column($index->diagnostics, 'code'));
        $this->assertSame([], $this->edges(new CatalogIndex($facts), 'selects-ai-provider-gateway'));
    }

    public function test_provider_getter_source_shapes_survive_cache_without_claiming_gateway_execution(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
function run(\Laravel\Ai\Providers\OpenAiProvider $provider, $method) {
    $provider->audioGateway();
    $provider->embeddingGateway();
    $provider->fileGateway();
    $provider->imageGateway();
    $provider->rerankingGateway();
    $provider->storeGateway();
    $provider->textGateway();
    $provider->transcriptionGateway(unexpected: 'getter-private-value');
    $provider->fileGateway(...);
    $provider->$method();
}
SOURCE);
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $sites = [];
        foreach ($cached as $fact) {
            foreach ($fact->elements as $element) {
                if ($element->kind === 'ai-gateway-call-site') {
                    $sites[] = $element;
                }
            }
        }
        $this->assertCount(8, $sites);
        $this->assertSame([], $sites[0]->metadata['arguments']);
        $this->assertSame([['name' => 'unexpected', 'unpack' => false, 'by_ref' => false, 'callback' => null]], $sites[7]->metadata['arguments']);
        $this->assertStringNotContainsString('getter-private-value', json_encode(array_map(fn ($fact) => $fact->toArray(), $cached), JSON_THROW_ON_ERROR));
        $index = new CatalogIndex([...$cached, $this->package()]);
        $this->assertSame([], $this->edges($index, 'calls-ai-gateway'));
        $this->assertSame([], $this->edges($index, 'passes-ai-provider-gateway'));
    }

    public function test_inherited_legacy_gateway_callbacks_keep_standard_and_custom_dispatch_branches_distinct(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Gateway extends \Laravel\Ai\Gateway\OpenAi\OpenAiGateway {}
class Override extends Gateway {
    public function onToolInvocation($before, $after) { $before(); return $this; }
}
function run(Gateway $gateway, Override $override) {
    $gateway->onToolInvocation(fn () => null, fn () => null);
    $gateway->onToolInvocation(invoking: fn () => null, invoked: fn () => null);
    $override->onToolInvocation(before: fn () => null, after: fn () => null);
}
SOURCE);
        foreach (['0.8.0', '0.8.1'] as $version) {
            $index = new CatalogIndex([...$facts, $this->package($version)]);
            $registered = $this->edges($index, 'registers-ai-gateway-callback');
            $executed = $this->edges($index, 'ai-gateway-tool-callback');
            $passed = $this->edges($index, 'passes-ai-gateway-callback');
            $this->assertCount(4, $registered);
            $this->assertCount(4, $executed);
            $this->assertCount(4, $passed);
            $this->assertCount(2, $this->edges($index, 'invokes-ai-gateway-method'));
            foreach ($executed as $edge) {
                $this->assertTrue($edge['metadata']['standard_sdk_method_candidate']);
                $this->assertTrue($edge['metadata']['runtime_standard_gateway_required']);
                $this->assertTrue($edge['metadata']['runtime_tool_invocation_required']);
                $this->assertFalse($edge['metadata']['execution_proven']);
                $this->assertContains('Laravel\Ai\Gateway\OpenAi\OpenAiGateway', $edge['metadata']['sdk_gateway_ancestors']);
            }
            $overlap = array_intersect(array_column($executed, 'from'), array_column($passed, 'from'));
            $this->assertNotEmpty($overlap);
            foreach ($passed as $edge) {
                $this->assertTrue($edge['metadata']['source_method_body_controls_callback_execution']);
                $this->assertArrayNotHasKey('runtime_tool_invocation_required', $edge['metadata']);
            }
        }
        $modern = new CatalogIndex([...$facts, $this->package()]);
        $this->assertSame([], $this->edges($modern, 'ai-gateway-tool-callback'));
    }

    public function test_provider_constructor_oversized_argument_name_is_unresolved_without_retaining_it(): void
    {
        $name = str_repeat('x', 1001);
        $facts = $this->facts('namespace App; function run($events) { new \\Laravel\\Ai\\Providers\\CohereProvider('.$name.': [], events: $events); }');
        $index = new CatalogIndex([...$facts, $this->package()]);
        $this->assertSame([], $this->edges($index, 'constructs-ai-provider'));
        $this->assertContains('package_ai_analysis', array_column($index->diagnostics, 'code'));
        $this->assertStringNotContainsString($name, json_encode([$facts, $index], JSON_THROW_ON_ERROR));
    }

    public function test_provider_config_constructors_do_not_imply_gateway_creation_or_provider_traffic(): void
    {
        foreach (array_keys(CatalogPackageVersions::AI_REFERENCES) as $version) {
            $source = 'namespace App; function run($events) {';
            foreach (['AzureOpenAi', 'Bedrock', 'Cohere', 'DeepSeek', 'ElevenLabs', 'Groq', 'Jina', 'Mistral', 'Ollama', 'OpenAiCompatible', 'OpenRouter', 'VoyageAi', 'Xai'] as $name) {
                $source .= 'new \\Laravel\\Ai\\Providers\\'.$name.'Provider(events: $events, config: ["key" => "private-key"]);';
            }
            $source .= <<<'SOURCE'
    new \Laravel\Ai\Providers\OpenAiProvider([], $events);
    new \Laravel\Ai\Providers\CohereProvider([], $events, null);
    new \Laravel\Ai\Providers\CohereProvider(settings: [], events: $events);
    new Own([], $events);
    new Override([], $events);
}
class Own extends \Laravel\Ai\Providers\CohereProvider {}
class Override extends Own { public function __construct($config, $events) {} }
SOURCE;
            $facts = $this->facts($source);
            $facts = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
            $index = new CatalogIndex([...$facts, $this->package($version)]);
            $constructors = $this->edges($index, 'constructs-ai-provider');
            $this->assertCount(version_compare($version, '0.9.0.0', '<') ? 13 : 14, $constructors);
            $this->assertSame([], $this->edges($index, 'passes-ai-provider-gateway'));
            foreach ($constructors as $edge) {
                $this->assertFalse($edge['metadata']['constructor_gateway_assignment']);
                $this->assertFalse($edge['metadata']['execution_proven']);
                $this->assertFalse($edge['metadata']['provider_traffic_proven']);
                $this->assertTrue($edge['metadata']['runtime_config_and_events_types_required']);
            }
            $this->assertStringNotContainsString('private-key', json_encode([$facts, $index], JSON_THROW_ON_ERROR));
            $this->assertContains('package_ai_analysis', array_column($index->diagnostics, 'code'));
        }
    }

    public function test_concrete_sdk_providers_have_versioned_setters_and_only_verified_inherited_gateway_constructors(): void
    {
        foreach (array_keys(CatalogPackageVersions::AI_REFERENCES) as $version) {
            $source = 'namespace App;';
            foreach (['Anthropic', 'AzureOpenAi', 'Bedrock', 'Cohere', 'DeepSeek', 'ElevenLabs', 'Gemini', 'Groq', 'Jina', 'Mistral', 'Ollama', 'OpenAiCompatible', 'OpenAi', 'OpenRouter', 'VoyageAi', 'Xai'] as $name) {
                $channel = in_array($name, ['Cohere', 'Jina', 'VoyageAi'], true) ? 'Reranking' : ($name === 'ElevenLabs' ? 'Audio' : 'Text');
                $gateway = $channel === 'Text' && version_compare($version, '0.9.0.0', '>=') ? 'StepText' : $channel;
                $source .= 'function run'.$name.'(\\Laravel\\Ai\\Providers\\'.$name.'Provider $provider, \\Laravel\\Ai\\Contracts\\Gateway\\'.$gateway.'Gateway $gateway) { $provider->use'.$channel.'Gateway($gateway); }';
            }
            $source .= <<<'SOURCE'
class Own extends \Laravel\Ai\Providers\OpenAiProvider {}
class Override extends Own { public function useFileGateway($transport) {} }
function construct($events) {
    new \Laravel\Ai\Providers\OpenAiProvider(new \Laravel\Ai\Gateway\OpenAi\OpenAiGateway($events), ['key' => 'private-key'], $events);
    new Own(new \Laravel\Ai\Gateway\Gemini\GeminiGateway($events), [], $events);
    new \Laravel\Ai\Providers\CohereProvider(new \Laravel\Ai\Gateway\OpenAi\OpenAiGateway($events), [], $events);
}
function own(Own $provider, Override $override) {
    $provider->useFileGateway(new \Laravel\Ai\Gateway\OpenAi\OpenAiFileGateway);
    $override->useFileGateway(transport: new \Laravel\Ai\Gateway\OpenAi\OpenAiFileGateway);
    $override->useFileGateway(gateway: new \Laravel\Ai\Gateway\OpenAi\OpenAiFileGateway);
}
SOURCE;
            $facts = $this->facts($source);
            $index = new CatalogIndex([...$facts, $this->package($version)]);
            $bindings = $this->edges($index, 'passes-ai-provider-gateway');
            $this->assertCount(version_compare($version, '0.9.0.0', '<') ? 19 : 20, $bindings);
            $constructors = array_values(array_filter($bindings, fn ($edge) => $edge['metadata']['method'] === '__construct'));
            $this->assertCount(2, $constructors);
            $this->assertCount(1, $this->edges($index, 'invokes-ai-provider-setter'));
            $this->assertStringNotContainsString('private-key', json_encode($index, JSON_THROW_ON_ERROR));
        }
    }

    public function test_sdk_provider_setter_capability_changes_are_patch_specific(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
function run(\Laravel\Ai\Providers\AzureOpenAiProvider $azure, \Laravel\Ai\Providers\BedrockProvider $bedrock,
    \Laravel\Ai\Providers\GroqProvider $groq, \Laravel\Ai\Providers\MistralProvider $mistral, \Laravel\Ai\Providers\OpenAiCompatibleProvider $compatible) {
    $azure->useFileGateway(new \Laravel\Ai\Gateway\OpenAi\OpenAiFileGateway);
    $bedrock->useRerankingGateway(new RankGateway);
    $groq->useTranscriptionGateway(new TranscriptionGateway);
    $mistral->useAudioGateway(new AudioGateway);
    $compatible->useEmbeddingGateway(new EmbeddingGateway);
    $compatible->useTranscriptionGateway(new TranscriptionGateway);
}
class RankGateway implements \Laravel\Ai\Contracts\Gateway\RerankingGateway {}
class TranscriptionGateway implements \Laravel\Ai\Contracts\Gateway\TranscriptionGateway {}
class AudioGateway implements \Laravel\Ai\Contracts\Gateway\AudioGateway {}
class EmbeddingGateway implements \Laravel\Ai\Contracts\Gateway\EmbeddingGateway {}
SOURCE);
        foreach (['0.8.1' => 0, '0.9.0' => 1, '0.10.2' => 1, '0.10.3' => 2, '0.11.0' => 4, '0.11.1' => 6, '0.11.2' => 6] as $version => $count) {
            $index = new CatalogIndex([...$facts, $this->package($version)]);
            $this->assertCount($count, $this->edges($index, 'passes-ai-provider-gateway'));
        }
    }

    public function test_concrete_sdk_gateway_ancestry_supports_calls_subclasses_and_provider_configuration(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class FileGateway extends \Laravel\Ai\Gateway\OpenAi\OpenAiFileGateway {}
class OwnGateway extends FileGateway { public function getFile($transport, $identifier) {} }
class Provider extends \Laravel\Ai\Providers\Provider {}
function run(\Laravel\Ai\Gateway\OpenAi\OpenAiGateway $openai, \Laravel\Ai\Gateway\Gemini\GeminiGateway $gemini,
    \Laravel\Ai\Gateway\Anthropic\AnthropicGateway $anthropic, \Laravel\Ai\Gateway\OpenAi\OpenAiStoreGateway $store,
    FileGateway $file, OwnGateway $own, \Laravel\Ai\Contracts\Providers\FileProvider $provider, $events) {
    $openai->generateImage(null, 'private', 'private');
    $gemini->generateImage(null, 'private', 'private');
    $anthropic->generateImage(null, 'private', 'private');
    $store->getStore(null, 'private');
    $file->getFile(null, 'private');
    $file->getFile(provider: null, fileId: 'private');
    $own->getFile(transport: null, identifier: 'private');
    $own->getFile(provider: null, fileId: 'private');
    $provider->useFileGateway(new \Laravel\Ai\Gateway\OpenAi\OpenAiFileGateway);
    new Provider(new \Laravel\Ai\Gateway\OpenAi\OpenAiGateway($events), [], $events);
}
SOURCE);
        foreach (array_keys(CatalogPackageVersions::AI_REFERENCES) as $version) {
            $index = new CatalogIndex([...$facts, $this->package($version)]);
            $this->assertCount(7, $this->edges($index, 'calls-ai-gateway'));
            $this->assertCount(2, $this->edges($index, 'invokes-ai-gateway-method'));
            $this->assertCount(2, $this->edges($index, 'passes-ai-provider-gateway'));
            $ids = $index->namedTypes('App\FileGateway');
            $this->assertContains('ai-gateway', $index->elements[$ids[0]]['roles']);
            $this->assertStringNotContainsString('"private"', json_encode($index, JSON_THROW_ON_ERROR));
        }
        $shadow = $this->facts('namespace Laravel\Ai\Gateway\OpenAi; class OpenAiFileGateway {}', 'app/Shadow.php');
        $index = new CatalogIndex([...$facts, ...$shadow, $this->package()]);
        $calls = $this->edges($index, 'calls-ai-gateway');
        $this->assertCount(4, $calls);
        $this->assertCount(1, $this->edges($index, 'passes-ai-provider-gateway'));
        $this->assertSame([], $this->edges(new CatalogIndex($facts), 'calls-ai-gateway'));
    }

    public function test_provider_inherited_constructor_gateway_binding_preserves_argument_shape_and_source_provenance(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Provider extends \Laravel\Ai\Providers\Provider {}
class Child extends Provider {}
class Custom extends Provider { public function __construct($gateway, $config, $events) {} }
abstract class AbstractProvider extends Provider {}
class Fake {}
class Gateway implements \Laravel\Ai\Contracts\Gateway\Gateway {}
function gatewayFactory() { return new Gateway; }
function run($events, $unknown) {
    new Provider(new Gateway, ['key' => 'private-credential'], $events);
    new Child(events: $events, gateway: gatewayFactory(), config: ['key' => 'private-credential']);
    new Custom(new Gateway, [], $events);
    new AbstractProvider(new Gateway, [], $events);
    new Fake(new Gateway, [], $events);
    new Provider($unknown, [], $events);
    new Provider(new Fake, [], $events);
    new Provider(new Gateway, []);
    new Provider(transport: new Gateway, config: [], events: $events);
    new Provider(gateway: new Gateway, gateway: new Gateway, events: $events);
    new Provider(...$unknown);
}
SOURCE);
        foreach (array_keys(CatalogPackageVersions::AI_REFERENCES) as $version) {
            $roundtrip = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
            $index = new CatalogIndex([...$roundtrip, $this->package($version)]);
            $bindings = $this->edges($index, 'passes-ai-provider-gateway');
            $this->assertCount(2, $bindings);
            $this->assertCount(2, $this->edges($index, 'references-ai-provider-gateway'));
            $this->assertFalse($bindings[0]['metadata']['execution_proven']);
            $this->assertFalse($bindings[0]['metadata']['provider_traffic_proven']);
            $this->assertTrue($bindings[0]['metadata']['runtime_config_and_events_types_required']);
            $this->assertTrue($bindings[1]['metadata']['gateway_return_path_choice_required']);
            $this->assertNotEmpty($bindings[1]['metadata']['gateway_return_sources']);
            $this->assertStringNotContainsString('private-credential', json_encode([$facts, $index], JSON_THROW_ON_ERROR));
            $this->assertContains('package_ai_analysis', array_column($index->diagnostics, 'code'));
        }
    }

    public function test_provider_standard_gateway_traits_respect_inheritance_overrides_and_adaptations(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
use Laravel\Ai\Contracts\Providers\FileProvider;
use Laravel\Ai\Providers\Concerns\HasFileGateway;
class Gateway implements \Laravel\Ai\Contracts\Gateway\FileGateway {}
class Standard implements FileProvider { use HasFileGateway; }
class Child extends Standard {}
class Override extends Standard { public function useFileGateway($transport) {} }
class PrivateOverride extends Standard { private function useFileGateway($gateway) {} }
class Adapted implements FileProvider { use HasFileGateway { useFileGateway as private; } }
trait Unrelated { public function helper() {} }
class UnrelatedAdaptation implements FileProvider { use HasFileGateway, Unrelated { helper as localHelper; } }
function run(Standard $standard, Child $child, Override $override, PrivateOverride $private, Adapted $adapted, UnrelatedAdaptation $unrelated) {
    $standard->useFileGateway(new Gateway);
    $child->useFileGateway(gateway: new Gateway);
    $override->useFileGateway(transport: new Gateway);
    $override->useFileGateway(gateway: new Gateway);
    $private->useFileGateway(new Gateway);
    $adapted->useFileGateway(new Gateway);
    $unrelated->useFileGateway(new Gateway);
}
SOURCE);
        foreach (array_keys(CatalogPackageVersions::AI_REFERENCES) as $version) {
            $index = new CatalogIndex([...$facts, $this->package($version)]);
            $bindings = $this->edges($index, 'passes-ai-provider-gateway');
            $this->assertCount(4, $bindings);
            $this->assertCount(1, $this->edges($index, 'invokes-ai-provider-setter'));
            $custom = array_values(array_filter($bindings, fn ($edge) => $edge['metadata']['source_method_body_controls_configuration']));
            $this->assertCount(1, $custom);
            $this->assertSame('App\Override', $custom[0]['metadata']['provider_type']);
            $this->assertContains('package_ai_analysis', array_column($index->diagnostics, 'code'));
        }
    }

    public function test_provider_gateway_setter_composition_budget_is_global_across_files(): void
    {
        $facts = [];
        foreach ([2048, 2049] as $number => $count) {
            $source = 'namespace App; function run'.$number.'(\\Laravel\\Ai\\Contracts\\Providers\\FileProvider $provider) { '
                .str_repeat('$provider->useFileGateway(new Gateway);', $count).' }';
            $source .= $number === 0 ? ' class Gateway implements \\Laravel\\Ai\\Contracts\\Gateway\\FileGateway {}' : '';
            array_push($facts, ...$this->facts($source, 'app/Provider'.$number.'.php'));
        }
        $index = new CatalogIndex([...$facts, $this->package()]);
        $this->assertCount(4096, $this->edges($index, 'passes-ai-provider-gateway'));
        $this->assertContains('catalog_limit', array_column($index->diagnostics, 'code'));
    }

    public function test_provider_gateway_setters_are_configuration_candidates_with_versioned_contracts(): void
    {
        foreach (array_keys(CatalogPackageVersions::AI_REFERENCES) as $version) {
            $source = 'namespace App;';
            foreach (['Audio', 'Embedding', 'File', 'Image', 'Reranking', 'Store', 'Text', 'Transcription'] as $channel) {
                $gateway = $channel === 'Text' && version_compare($version, '0.9.0.0', '>=') ? 'StepText' : $channel;
                $source .= 'class '.$channel.'Gateway implements \\Laravel\\Ai\\Contracts\\Gateway\\'.$gateway.'Gateway {} '
                    .'function run'.$channel.'(\\Laravel\\Ai\\Contracts\\Providers\\'.$channel.'Provider $provider) { $provider->use'.$channel.'Gateway(gateway: new '.$channel.'Gateway); }';
            }
            $facts = $this->facts($source);
            $index = new CatalogIndex([...$facts, $this->package($version)]);
            $bindings = $this->edges($index, 'passes-ai-provider-gateway');
            $this->assertCount(8, $bindings);
            $this->assertCount(8, $this->edges($index, 'references-ai-provider-gateway'));
            foreach ($bindings as $edge) {
                $this->assertFalse($edge['metadata']['execution_proven']);
                $this->assertFalse($edge['metadata']['provider_traffic_proven']);
                $this->assertTrue($edge['metadata']['runtime_standard_provider_required']);
                $this->assertSame('gateway-configuration-candidate', $edge['metadata']['operation_stage']);
            }
        }
    }

    public function test_provider_setters_use_source_parameter_names_and_reject_lookalikes_and_unknown_arguments(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
use Laravel\Ai\Contracts\Providers\FileProvider as Provider;
use Laravel\Ai\Contracts\Gateway\FileGateway as GatewayContract;
class Gateway implements GatewayContract {}
class Fake { public function useFileGateway($gateway) {} }
class Custom implements Provider { public function useFileGateway($transport) { return $this; } }
function gatewayFactory() { return new Gateway; }
function run(Provider $provider, Custom $custom, Fake $fake, $unknown) {
    $provider->useFileGateway(gatewayFactory());
    $custom->useFileGateway(transport: new Gateway);
    $custom->useFileGateway(gateway: new Gateway);
    $fake->useFileGateway(new Gateway);
    $provider->useFileGateway($unknown);
    $provider->useFileGateway(new Fake);
    $provider->useFileGateway(...$unknown);
    $provider->useFileGateway(gateway: new Gateway, other: 'private-value');
}
SOURCE);
        $index = new CatalogIndex([...$facts, $this->package()]);
        $bindings = $this->edges($index, 'passes-ai-provider-gateway');
        $this->assertCount(2, $bindings);
        $this->assertCount(1, $this->edges($index, 'invokes-ai-provider-setter'));
        $this->assertCount(2, $this->edges($index, 'references-ai-provider-gateway'));
        $this->assertTrue($bindings[0]['metadata']['gateway_return_path_choice_required']);
        $this->assertNotEmpty($bindings[0]['metadata']['gateway_return_sources']);
        $this->assertTrue($bindings[1]['metadata']['source_method_body_controls_configuration']);
        $this->assertFalse($bindings[1]['metadata']['runtime_standard_provider_required']);
        $this->assertContains('package_ai_analysis', array_column($index->diagnostics, 'code'));
        $this->assertStringNotContainsString('private-value', json_encode($index, JSON_THROW_ON_ERROR));
        $this->assertSame([], $this->edges(new CatalogIndex($facts), 'passes-ai-provider-gateway'));
        $this->assertSame([], $this->edges(new CatalogIndex([...$facts, $this->package('0.12.0')]), 'passes-ai-provider-gateway'));
    }

    public function test_gateway_first_class_and_returned_closures_respect_source_visibility_and_return_provenance(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
function before() {}
function factory() { return fn () => before(); }
function stringFactory() { return 'private-callback'; }
function cycle() { return cycle(); }
class Service {
    public function before() {}
    private function secret() {}
    public static function after() {}
    public function factory() { return $this->secret(...); }
    public function internal(\Laravel\Ai\Contracts\Gateway\TextGateway $gateway) {
        $gateway->onToolInvocation($this->secret(...), self::after(...));
    }
}
function run(\Laravel\Ai\Contracts\Gateway\TextGateway $gateway, Service $service) {
    $gateway->onToolInvocation($service->before(...), Service::after(...));
    $callback = before(...);
    $gateway->onToolInvocation($callback, factory());
    $gateway->onToolInvocation($service->factory(), stringFactory());
    $gateway->onToolInvocation($service->secret(...), cycle());
    $gateway->onToolInvocation([$service, 'before'], 'private-callback');
}
SOURCE);
        $facts = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$facts, $this->package('0.8.1')]);
        $callbacks = $this->edges($index, 'ai-gateway-tool-callback');
        $this->assertCount(7, $callbacks);
        $this->assertCount(7, $this->edges($index, 'registers-ai-gateway-callback'));
        $returned = array_values(array_filter($callbacks, fn ($edge) => $edge['metadata']['callback_return_path_choice_required']));
        $this->assertCount(2, $returned);
        foreach ($returned as $edge) {
            $this->assertNotEmpty($edge['metadata']['callback_return_sources']);
            $this->assertSame('app/Ai.php', $edge['metadata']['callback_return_sources'][0]['path']);
        }
        $targets = array_map(fn ($edge) => $index->elements[$edge['to']]['name'], $callbacks);
        $this->assertContains('App\Service::secret', $targets);
        $this->assertContains('App\before', $targets);
        $this->assertContains('App\Service::before', $targets);
        $this->assertStringNotContainsString('private-callback', json_encode([$facts, $index], JSON_THROW_ON_ERROR));
        $this->assertContains('package_ai_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_gateway_stored_closures_follow_copies_and_value_captures_but_not_mutated_values(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
function mutate(&$value) {}
function run(\Laravel\Ai\Contracts\Gateway\TextGateway $gateway, $unknown) {
    $before = fn () => Service::before();
    $after = fn () => Service::after();
    $copy = $before;
    $gateway->onToolInvocation(invoked: $after, invoking: $copy);
    $capturedBefore = fn () => Service::before();
    $capturedAfter = fn () => Service::after();
    $closure = function () use ($gateway, $capturedBefore, $capturedAfter) {
        $gateway->onToolInvocation($capturedBefore, $capturedAfter);
    };
    $arrow = fn () => $gateway->onToolInvocation($capturedBefore, $capturedAfter);
    $changed = fn () => Service::before();
    $changed = $unknown;
    $gateway->onToolInvocation($changed, 'private-callback');
    $mutated = fn () => Service::before();
    mutate($mutated);
    $gateway->onToolInvocation($mutated, 'private-callback');
    $conditional = fn () => Service::before();
    if ($unknown) { $conditional = $unknown; }
    $gateway->onToolInvocation($conditional, 'private-callback');
    $referenced = fn () => Service::before();
    $byRef = function () use ($gateway, &$referenced) { $gateway->onToolInvocation($referenced, 'private-callback'); };
}
class Service { public static function before() {} public static function after() {} }
SOURCE);
        $facts = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$facts, $this->package('0.8.1')]);
        $callbacks = $this->edges($index, 'ai-gateway-tool-callback');
        $this->assertCount(6, $callbacks);
        $this->assertCount(6, $this->edges($index, 'registers-ai-gateway-callback'));
        foreach ($callbacks as $edge) {
            $this->assertSame('closure', $index->elements[$edge['to']]['kind']);
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
        $this->assertContains('package_ai_analysis', array_column($index->diagnostics, 'code'));
        $this->assertStringNotContainsString('private-callback', json_encode([$facts, $index], JSON_THROW_ON_ERROR));
    }

    public function test_legacy_gateway_callback_registration_is_distinct_from_later_tool_execution_and_custom_bodies(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Custom implements \Laravel\Ai\Contracts\Gateway\TextGateway {
    public function onToolInvocation($before, $after) { $before(); return $this; }
}
class Fake { public function onToolInvocation($before, $after) {} }
function run(\Laravel\Ai\Contracts\Gateway\TextGateway $gateway, Custom $custom, Fake $fake) {
    $gateway->onToolInvocation(invoked: fn () => Service::after(), invoking: fn () => Service::before());
    $custom->onToolInvocation(fn () => Service::before(), fn () => Service::after());
    $fake->onToolInvocation(fn () => Service::before(), fn () => Service::after());
    $gateway->onToolInvocation('private-callback-name', 'private-callback-name');
}
class Service { public static function before() {} public static function after() {} }
SOURCE);
        $facts = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        foreach (['0.8.0', '0.8.1'] as $version) {
            $index = new CatalogIndex([...$facts, $this->package($version)]);
            $registered = $this->edges($index, 'registers-ai-gateway-callback');
            $callbacks = $this->edges($index, 'ai-gateway-tool-callback');
            $passed = $this->edges($index, 'passes-ai-gateway-callback');
            $this->assertCount(2, $registered);
            $this->assertCount(2, $callbacks);
            $this->assertCount(2, $passed);
            $this->assertContains('registers-ai-gateway-callback', CatalogKinds::STRUCTURAL_RELATIONS);
            $this->assertContains('passes-ai-gateway-callback', CatalogKinds::STRUCTURAL_RELATIONS);
            foreach ($callbacks as $edge) {
                $this->assertFalse($edge['metadata']['execution_proven']);
                $this->assertTrue($edge['metadata']['runtime_tool_invocation_required']);
                $this->assertTrue($edge['metadata']['runtime_standard_gateway_required']);
                $this->assertTrue($edge['metadata']['registration_must_be_active_at_tool_start']);
                $this->assertSame($edge['metadata']['callback_phase'] === 'invoked', $edge['metadata']['tool_handler_returned_required']);
                $this->assertSame($edge['metadata']['callback_phase'] === 'invoked', $edge['metadata']['before_tool_callback_succeeded_required']);
                $this->assertSame('callback-registration-candidate', $index->elements[$edge['from']]['metadata']['operation_stage']);
            }
            foreach ($passed as $edge) {
                $this->assertTrue($edge['metadata']['source_method_body_controls_callback_execution']);
            }
            $this->assertContains('package_ai_analysis', array_column($index->diagnostics, 'code'));
            $this->assertStringNotContainsString('private-callback-name', json_encode([$facts, $index], JSON_THROW_ON_ERROR));
        }
        $modern = new CatalogIndex([...$facts, $this->package()]);
        $this->assertSame([], $this->edges($modern, 'registers-ai-gateway-callback'));
        $this->assertSame([], $this->edges($modern, 'ai-gateway-tool-callback'));
        $this->assertSame([], $this->edges($modern, 'passes-ai-gateway-callback'));
    }

    public function test_gateway_named_calls_use_current_source_parameter_names_including_trait_aliases_and_variadics(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
use Laravel\Ai\Contracts\Gateway\FileGateway;
class Renamed implements FileGateway { public function getFile($transport, $identifier) {} }
class Child extends Renamed {}
trait Implementation { public function read($transport, $identifier = 'default-secret') {} }
class Aliased implements FileGateway { use Implementation { read as getFile; } }
class Variadic implements FileGateway { public function getFile($transport, ...$options) {} }
function run(Child $renamed, Aliased $aliased, Variadic $variadic, $provider) {
    $renamed->getFile(transport: $provider, identifier: 'private-id');
    $renamed->getFile(provider: $provider, fileId: 'private-id');
    $renamed->getFile($provider, 'private-id');
    $renamed->getFile(transport: $provider);
    $aliased->getFile(transport: $provider);
    $aliased->getFile(transport: $provider, identifier: 'private-id');
    $aliased->getFile(provider: $provider, fileId: 'private-id');
    $variadic->getFile(transport: $provider, fileId: 'private-id', other: 'private-value');
    $variadic->getFile($provider, 'private-id', 'private-value');
    $variadic->getFile(other: 'private-value');
}
SOURCE);
        $facts = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$facts, $this->package()]);
        $edges = $this->edges($index, 'calls-ai-gateway');
        $this->assertCount(6, $edges);
        $this->assertCount(6, $this->edges($index, 'invokes-ai-gateway-method'));
        foreach ($edges as $edge) {
            $this->assertTrue($edge['metadata']['source_parameter_names_verified']);
            $this->assertTrue($edge['metadata']['runtime_argument_binding_required']);
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
        $serialized = json_encode([$facts, $index], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('default-secret', $serialized);
        $this->assertStringNotContainsString('private-value', $serialized);
        $this->assertContains('package_ai_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_gateway_composition_limit_reports_partial_results_across_files(): void
    {
        $facts = [];
        foreach ([2048, 2049] as $number => $count) {
            $source = 'namespace App; function run'.$number.'(\\Laravel\\Ai\\Contracts\\Gateway\\FileGateway $gateway) { '
                .str_repeat('$gateway->getFile(null, "private");', $count).' }';
            array_push($facts, ...$this->facts($source, 'app/Gateway'.$number.'.php'));
        }
        $index = new CatalogIndex([...$facts, $this->package()]);
        $this->assertCount(4096, $this->edges($index, 'calls-ai-gateway'));
        $this->assertContains('catalog_limit', array_column($index->diagnostics, 'code'));
    }

    public function test_gateway_contract_shadow_unknown_version_and_argument_budgets_are_explicit(): void
    {
        $shadow = $this->facts(<<<'SOURCE'
namespace Laravel\Ai\Contracts\Gateway { interface AudioGateway { public function generateAudio($provider, $model, $text, $voice); } }
namespace App { function run(\Laravel\Ai\Contracts\Gateway\Gateway $root, $provider) { $root->generateAudio($provider, 'private', 'private', 'private'); } }
SOURCE);
        $index = new CatalogIndex([...$shadow, $this->package()]);
        $this->assertSame([], $this->edges($index, 'calls-ai-gateway'));
        $this->assertContains('package_ai_analysis', array_column($index->diagnostics, 'code'));
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Custom implements \Laravel\Ai\Contracts\Gateway\FileGateway { public function getFile($provider, $fileId) {} }
function run(Custom $gateway, $provider) { $gateway->getFile($provider, 'private'); }
SOURCE);
        $unknown = new CatalogIndex([...$facts, $this->package('0.12.0')]);
        $this->assertSame([], $this->edges($unknown, 'calls-ai-gateway'));
        $this->assertNotContains('ai-gateway', $unknown->elements[$unknown->namedTypes('App\\Custom')[0]]['roles']);
        $this->assertContains('package_ai_analysis', array_column($unknown->diagnostics, 'code'));
        $oversized = 'namespace App; function run(\\Laravel\\Ai\\Contracts\\Gateway\\FileGateway $gateway) { $gateway->getFile('.implode(',', array_fill(0, 129, 'null')).'); }';
        $index = new CatalogIndex([...$this->facts($oversized), $this->package()]);
        $this->assertSame([], $this->edges($index, 'calls-ai-gateway'));
        $this->assertContains('catalog_limit', array_column($index->diagnostics, 'code'));
    }

    public function test_gateway_receiver_factory_cycle_does_not_hide_later_direct_contract_call(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Factory { public static function cycle() { return self::cycle(); } }
function run(\Laravel\Ai\Contracts\Gateway\FileGateway $gateway, $provider) {
    Factory::cycle()->getFile($provider, 'private');
    $gateway->getFile($provider, 'private');
}
SOURCE);
        $index = new CatalogIndex([...$facts, $this->package()]);
        $this->assertCount(1, $this->edges($index, 'calls-ai-gateway'));
        $this->assertContains('unresolved_dispatch', array_column($index->diagnostics, 'code'));
    }

    #[DataProvider('inspectedAiVersions')]
    public function test_gateway_contracts_follow_versioned_api_and_source_implementations_without_payloads(string $version, string $reference): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
use Laravel\Ai\Contracts\Gateway\FileGateway as Files;
class Custom implements Files { public function getFile($provider, $fileId) {} }
class Child extends Custom {}
class PrivateGateway implements Files { private function getFile($provider, $fileId) {} }
class StaticGateway implements Files { public static function getFile($provider, $fileId) {} }
class Fake { public function getFile($provider, $fileId) {} }
function run(Files $files, \Laravel\Ai\Contracts\Gateway\StoreGateway $stores,
    \Laravel\Ai\Contracts\Gateway\AudioGateway $audio, \Laravel\Ai\Contracts\Gateway\ImageGateway $image,
    \Laravel\Ai\Contracts\Gateway\EmbeddingGateway $embeddings, \Laravel\Ai\Contracts\Gateway\TranscriptionGateway $transcription,
    \Laravel\Ai\Contracts\Gateway\RerankingGateway $rerank, \Laravel\Ai\Contracts\Gateway\TextGateway $legacy,
    \Laravel\Ai\Contracts\Gateway\StepTextGateway $steps, \Laravel\Ai\Contracts\Gateway\Gateway $root,
    Child $custom, PrivateGateway $private, StaticGateway $static, Fake $fake, $provider, $file, $context, $method) {
    $files->getFile($provider, 'file-secret');
    $files->getFile(fileId: 'file-secret', provider: $provider);
    $files->putFile($provider, $file);
    $files->deleteFile($provider, 'file-secret');
    $stores->getStore($provider, 'store-secret');
    $stores->createStore($provider, 'store-secret');
    $stores->addFile($provider, 'store-secret', 'file-secret');
    $stores->removeFile($provider, 'store-secret', 'file-secret');
    $stores->deleteStore($provider, 'store-secret');
    $audio->generateAudio($provider, 'model-secret', 'text-secret', 'voice-secret');
    $image->generateImage($provider, 'model-secret', 'prompt-secret');
    $embeddings->generateEmbeddings($provider, 'model-secret', ['inputs-secret'], 100);
    $transcription->generateTranscription($provider, 'model-secret', $file);
    $rerank->rerank($provider, 'model-secret', ['documents-secret'], 'query-secret');
    $legacy->generateText($provider, 'model-secret', 'instructions-secret');
    $legacy->streamText('invocation-secret', $provider, 'model-secret', 'instructions-secret');
    $legacy->onToolInvocation(fn () => null, fn () => null);
    $steps->generateTextStep($provider, 'model-secret', 'instructions-secret', [], [], null, null, null, $context);
    $steps->generateStreamStep('invocation-secret', $provider, 'model-secret', 'instructions-secret', [], [], null, null, null, $context);
    $root->generateAudio($provider, 'model-secret', 'text-secret', 'voice-secret');
    $custom->getFile($provider, 'file-secret');
    $private->getFile($provider, 'file-secret');
    $static->getFile($provider, 'file-secret');
    $fake->getFile($provider, 'file-secret');
    $files->getFile($provider);
    $files->getFile(provider: $provider, unknown: 'file-secret');
    Files::getFile($provider, 'file-secret');
    $files->{$method}($provider, 'file-secret');
}
SOURCE);
        $facts = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$facts, $this->package($version, reference: $reference)]);
        $legacy = version_compare($version, '0.9.0.0', '<');
        $edges = $this->edges($index, 'calls-ai-gateway');
        $this->assertCount($legacy ? 19 : 18, $edges);
        $this->assertContains('ai-gateway', $index->elements[$index->namedTypes('App\\Child')[0]]['roles']);
        $handlers = $this->edges($index, 'invokes-ai-gateway-method');
        $this->assertCount(1, $handlers);
        $this->assertSame('App\\Custom::getFile', $index->elements[$handlers[0]['to']]['name']);
        foreach ($edges as $edge) {
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertFalse($edge['metadata']['provider_traffic_proven']);
        }
        $this->assertContains('package_ai_analysis', array_column($index->diagnostics, 'code'));
        $serialized = json_encode([$facts, $index], JSON_THROW_ON_ERROR);
        foreach (['file-secret', 'store-secret', 'model-secret', 'text-secret', 'voice-secret', 'prompt-secret', 'inputs-secret', 'documents-secret', 'query-secret', 'instructions-secret', 'invocation-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
        $absent = new CatalogIndex($facts);
        $this->assertSame([], $this->edges($absent, 'calls-ai-gateway'));
        $this->assertNotContains('ai-gateway', $absent->elements[$absent->namedTypes('App\\Child')[0]]['roles']);
    }

    public function test_returned_attachment_arrays_follow_source_factories_across_files_and_reject_decoys_cycles_and_private_calls(): void
    {
        $producer = $this->facts(<<<'SOURCE'
namespace App;
use Laravel\Ai\Files\Document as Doc;
class Factory {
    public static function files() { return [Doc::fromString('content-secret')]; }
    public static function wrapper() { return self::files(); }
    public function instance() { return self::wrapper(); }
    public static function mixed($condition) {
        if ($condition) { return [Doc::fromPath('path-secret')]; }
        return [\Laravel\Ai\Files\Image::fromUrl('url-secret')];
    }
    public static function cycle() { return self::cycle(); }
    private static function hidden() { return [Doc::fromPath('path-secret')]; }
    public function own(Agent $agent) { $agent->prompt('private', self::hidden()); }
}
function files() { return Factory::wrapper(); }
class Decoy { public static function files(): array {} }
SOURCE, 'app/Factory.php');
        $consumer = $this->facts(<<<'SOURCE'
namespace App;
class Agent implements \Laravel\Ai\Contracts\Agent { use \Laravel\Ai\Promptable; public function instructions(): string { return ''; } }
function run(Agent $agent, Factory $factory, $condition) {
    $agent->prompt('private', Factory::files());
    $agent->prompt('private', Factory::wrapper());
    $agent->stream('private', $factory->instance());
    $agent->broadcast('private', [], files());
    $returned = Factory::files();
    $agent->queue('private', $returned);
    $agent->prompt('private', Factory::mixed($condition));
    $agent->prompt('private', Factory::hidden());
    $agent->prompt('private', Decoy::files());
    $agent->prompt('private', Factory::cycle());
}
SOURCE, 'app/Consumer.php');
        $facts = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), [...$producer, ...$consumer]);
        $index = new CatalogIndex([...$facts, $this->package()]);
        $edges = $this->edges($index, 'uses-ai-attachment');
        $this->assertCount(8, $edges);
        foreach ($edges as $edge) {
            $this->assertSame('app/Factory.php', $edge['path']);
            $this->assertSame('app/Factory.php', $index->elements[$edge['to']]['path']);
            $this->assertTrue($edge['metadata']['attachment_return_path_choice_required']);
            $this->assertNotEmpty($edge['metadata']['attachment_return_sources']);
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
        $this->assertCount(4, $this->edges($index, 'prepares-ai-attachment'));
        $this->assertContains('catalog_limit', array_column($index->diagnostics, 'code'));
        $this->assertContains('package_ai_analysis', array_column($index->diagnostics, 'code'));
        $serialized = json_encode([$facts, $index], JSON_THROW_ON_ERROR);
        foreach (['content-secret', 'path-secret', 'url-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
    }

    public function test_reused_attachment_alias_has_one_creation_and_multiple_consumers(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Agent implements \Laravel\Ai\Contracts\Agent { use \Laravel\Ai\Promptable; public function instructions(): string { return ''; } }
function run(Agent $agent) {
    $files = [\Laravel\Ai\Files\Document::fromPath('private')];
    $copy = $files;
    $agent->prompt('private', $files);
    $agent->queue('private', $copy);
}
SOURCE);
        $index = new CatalogIndex([...$facts, $this->package()]);
        $uses = $this->edges($index, 'uses-ai-attachment');
        $this->assertCount(2, $uses);
        $this->assertSame($uses[0]['to'], $uses[1]['to']);
        $this->assertCount(1, $this->edges($index, 'prepares-ai-attachment'));
        $this->assertCount(1, $index->names[strtolower($index->elements[$uses[0]['to']]['name'])]);
    }

    public function test_stored_attachment_arrays_follow_assignment_alias_and_capture_but_drop_mutated_values(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
use Laravel\Ai\Files\Document as Doc;
class Agent implements \Laravel\Ai\Contracts\Agent { use \Laravel\Ai\Promptable; public function instructions(): string { return ''; } }
function simple(Agent $agent) {
    $files = ['key-secret' => Doc::fromPath('path-secret')];
    $agent->prompt('private', $files);
}
function alias(Agent $agent) {
    $files = [Doc::fromString('content-secret')];
    $copy = $files;
    $files = [];
    $agent->queue(attachments: $copy, prompt: 'private');
}
function capture(Agent $agent) {
    $files = [Doc::fromPath('path-secret')];
    $callback = function () use ($agent, $files) { $agent->stream('private', $files); };
}
function arrow(Agent $agent) {
    $files = [Doc::fromPath('path-secret')];
    $callback = fn () => $agent->broadcast('private', [], $files);
}
function replaced(Agent $agent, $unknown) {
    $files = [Doc::fromPath('path-secret')];
    $files = $unknown;
    $agent->prompt('private', $files);
}
function byReference(Agent $agent) {
    $files = [Doc::fromPath('path-secret')];
    $callback = function () use ($agent, &$files) { $agent->prompt('private', $files); };
}
function mutated(Agent $agent) {
    $files = [Doc::fromPath('path-secret')];
    unknownMutation($files);
    $agent->prompt('private', $files);
}
function dimension(Agent $agent, $unknown) {
    $files = [Doc::fromPath('path-secret')];
    $files[0] = $unknown;
    $agent->prompt('private', $files);
}
function branch(Agent $agent, $condition, $unknown) {
    $files = [Doc::fromPath('path-secret')];
    if ($condition) { $files = $unknown; }
    $agent->prompt('private', $files);
}
SOURCE);
        $facts = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$facts, $this->package()]);
        $edges = $this->edges($index, 'uses-ai-attachment');
        $this->assertCount(4, $edges);
        foreach ($edges as $edge) {
            $this->assertFalse($edge['metadata']['execution_proven']);
            $target = $index->elements[$edge['to']];
            $creator = $index->elements[$target['parent']];
            $this->assertSame('function', $creator['kind']);
            $this->assertContains($creator['name'], ['App\\simple', 'App\\alias', 'App\\capture', 'App\\arrow']);
        }
        $this->assertContains('package_ai_analysis', array_column($index->diagnostics, 'code'));
        $serialized = json_encode([$facts, $index], JSON_THROW_ON_ERROR);
        foreach (['key-secret', 'path-secret', 'content-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
    }

    public static function attachmentKeys(): array
    {
        return [
            'string duplicate' => ["['key-secret' => D, 'key-secret' => I]", ['Image']],
            'numeric string coercion' => ["[1 => D, '1' => I]", ['Image']],
            'leading zero distinct' => ["[1 => D, '01' => I]", ['Document', 'Image']],
            'bool float numeric collision' => ["[true => D, 1.8 => I, '1' => D]", ['Document']],
            'null empty string collision' => ["[null => D, '' => I]", ['Image']],
            'implicit numeric replacement' => ['[4 => D, I, 5 => D]', ['Document', 'Document']],
            'negative implicit replacement PHP83' => ['[-5 => D, D, -4 => I]', ['Document', 'Image']],
            'positive unary key' => ['[+2 => D, 2 => I]', ['Image']],
            'dynamic key could replace' => ['[0 => D, $'.'key => I]', []],
            'unpack could replace' => ["['key-secret' => D, ...$".'unknown]', []],
            'overwritten unknown removed' => ["['key-secret' => $"."unknown, 'key-secret' => I]", ['Image']],
            'known replaced with unknown' => ["['key-secret' => D, 'key-secret' => $".'unknown]', []],
        ];
    }

    #[DataProvider('attachmentKeys')]
    public function test_attachment_arrays_use_surviving_php_keys_without_exposing_them(string $array, array $expected): void
    {
        $array = str_replace(['D', 'I'], ['\\Laravel\\Ai\\Files\\Document::fromString("private-content")', '\\Laravel\\Ai\\Files\\Image::fromPath("private-path")'], $array);
        $source = 'namespace App; class Agent implements \\Laravel\\Ai\\Contracts\\Agent { use \\Laravel\\Ai\\Promptable; public function instructions(): string { return ""; } } function run(Agent $agent, $key, $unknown) { $agent->prompt("private", '.$array.'); }';
        $facts = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $this->facts($source));
        $index = new CatalogIndex([...$facts, $this->package()]);
        $types = array_map(fn ($edge) => substr($index->elements[$edge['to']]['metadata']['file_type'], strlen('Laravel\\Ai\\Files\\')), $this->edges($index, 'uses-ai-attachment'));
        $this->assertSame($expected, $types);
        $serialized = json_encode([$facts, $index], JSON_THROW_ON_ERROR);
        foreach (['key-secret', 'private-content', 'private-path'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
    }

    public function test_keyed_attachment_overflow_does_not_preserve_candidates_that_later_items_could_replace(): void
    {
        $items = array_fill(0, 128, '\\Laravel\\Ai\\Files\\Document::fromString("private")');
        $items[] = '0 => $unknown';
        $source = 'namespace App; class Agent implements \\Laravel\\Ai\\Contracts\\Agent { use \\Laravel\\Ai\\Promptable; public function instructions(): string { return ""; } } function run(Agent $agent, $unknown) { $agent->prompt("private", ['.implode(',', $items).']); }';
        $index = new CatalogIndex([...$this->facts($source), $this->package()]);
        $this->assertSame([], $this->edges($index, 'uses-ai-attachment'));
        $this->assertContains('catalog_limit', array_column($index->diagnostics, 'code'));
    }

    #[DataProvider('inspectedAiVersions')]
    public function test_attachment_factories_link_to_invocations_without_retaining_content_and_respect_video_version(string $version, string $reference): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
use Laravel\Ai\Files\Document as Doc;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\Audio;
use Laravel\Ai\Files\Video;
class Agent implements \Laravel\Ai\Contracts\Agent { use \Laravel\Ai\Promptable; public function instructions(): string { return ''; } }
class Decoy { public function prompt($prompt, $attachments) {} }
function run(Agent $agent, Decoy $decoy, $dynamic) {
    $agent->prompt('prompt-secret', [Doc::fromString('content-secret'), Image::fromUrl('https://example.test?token=url-secret'), Audio::fromStorage('path-secret', 'disk-secret')]);
    $agent->queue(prompt: 'prompt-secret', attachments: [Doc::fromPath(path: 'path-secret')]);
    $agent->stream('prompt-secret', [Video::fromUpload($dynamic)]);
    $agent->prompt('prompt-secret', [Doc::fromPath(mimeType: 'invalid')]);
    $agent->prompt('prompt-secret', [Audio::fromUpload($dynamic)]);
    $agent->prompt('prompt-secret', $dynamic);
    $agent->prompt('prompt-secret', ['same' => Doc::fromString('content-secret'), 'same' => $dynamic]);
    $decoy->prompt('prompt-secret', [Doc::fromString('content-secret')]);
}
throw new \RuntimeException('source-only-secret');
SOURCE);
        $facts = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$facts, $this->package($version, reference: $reference)]);
        $video = version_compare($version, '0.10.0.0', '>=');
        $this->assertCount($video ? 5 : 4, $this->edges($index, 'uses-ai-attachment'));
        $this->assertCount($video ? 5 : 4, $this->edges($index, 'prepares-ai-attachment'));
        foreach ($this->edges($index, 'uses-ai-attachment') as $edge) {
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertSame($index->elements[$edge['from']]['metadata']['queued'], $edge['metadata']['queue_delivery_required']);
            $this->assertSame($index->elements[$edge['from']]['metadata']['streamed'], $edge['metadata']['stream_consumption_required']);
        }
        $serialized = json_encode([$facts, $index], JSON_THROW_ON_ERROR);
        foreach (['prompt-secret', 'content-secret', 'url-secret', 'path-secret', 'disk-secret', 'source-only-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
        $this->assertContains('package_ai_analysis', array_column($index->diagnostics, 'code'));
        $absent = new CatalogIndex($facts);
        $this->assertSame([], $this->edges($absent, 'uses-ai-attachment'));
    }

    public function test_attachment_shadowing_and_list_budget_are_explicit(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace Laravel\Ai\Files { class Document { public static function fromString($content) {} } }
namespace App {
class Agent implements \Laravel\Ai\Contracts\Agent { use \Laravel\Ai\Promptable; public function instructions(): string { return ''; } }
function run(Agent $agent) { $agent->prompt('private', [\Laravel\Ai\Files\Document::fromString('private')]); }
}
SOURCE);
        $index = new CatalogIndex([...$facts, $this->package()]);
        $this->assertSame([], $this->edges($index, 'uses-ai-attachment'));
        $this->assertContains('package_ai_analysis', array_column($index->diagnostics, 'code'));
        foreach ([128, 129] as $count) {
            $source = 'namespace App; class Agent implements \\Laravel\\Ai\\Contracts\\Agent { use \\Laravel\\Ai\\Promptable; public function instructions(): string { return ""; } } function run(Agent $agent) { $agent->prompt("private", ['
                .implode(',', array_fill(0, $count, '\\Laravel\\Ai\\Files\\Document::fromString("private")')).']); }';
            $index = new CatalogIndex([...$this->facts($source), $this->package()]);
            $this->assertCount(128, $this->edges($index, 'uses-ai-attachment'));
            $this->assertSame($count === 129, in_array('catalog_limit', array_column($index->diagnostics, 'code'), true));
        }
    }

    public function test_direct_and_nested_response_factories_follow_method_and_function_returns_and_returned_fluent_callbacks(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Agent implements \Laravel\Ai\Contracts\Agent { use \Laravel\Ai\Promptable; public function instructions(): string { return ''; } }
class Factory {
    public static function response(Agent $agent) { return $agent->stream('private'); }
    public static function wrapper(Agent $agent) { return self::response($agent); }
    public function instance(Agent $agent) { return self::wrapper($agent); }
    public static function fluent(Agent $agent) { return $agent->stream('private')->each(fn ($event) => null); }
    public static function cycle(Agent $agent) { return self::cycle($agent); }
}
function response(Agent $agent) { return Factory::wrapper($agent); }
class Decoy { public static function response() { return new self; } public function then($callback) {} }
function consume(Agent $agent, Factory $factory) {
    Factory::response(new Agent)->then(fn ($value) => null);
    Factory::wrapper(new Agent)->then(fn ($value) => null);
    $factory->instance(new Agent)->then(fn ($value) => null);
    response(new Agent)->then(fn ($value) => null);
    Factory::fluent(new Agent)->then(fn ($value) => null);
    Factory::cycle(new Agent)->then(fn ($value) => null);
    Decoy::response()->then(fn ($value) => null);
}
function typedAfterCycle(\Laravel\Ai\Responses\StreamableAgentResponse $response) { $response->then(fn ($value) => null); }
SOURCE);
        $index = new CatalogIndex([...$facts, $this->package()]);
        $then = $this->edges($index, 'ai-response-then');
        $known = array_values(array_filter($then, fn ($edge) => ! ($edge['metadata']['agent_origin_unknown'] ?? false)));
        $typed = array_values(array_filter($then, fn ($edge) => $edge['metadata']['agent_origin_unknown'] ?? false));
        $this->assertCount(5, $known);
        $this->assertCount(1, $typed);
        foreach ($known as $edge) {
            $this->assertTrue($edge['metadata']['response_return_path_choice_required']);
            $this->assertFalse($edge['metadata']['agent_origin_unknown'] ?? false);
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
        $this->assertCount(1, $this->edges($index, 'ai-response-each'));
        $this->assertContains('catalog_limit', array_column($index->diagnostics, 'code'));
    }

    public function test_response_factory_candidate_budget_accepts_exact_boundary_and_reports_only_overflow(): void
    {
        foreach ([128, 129] as $count) {
            $returns = [];
            for ($position = 0; $position < $count; $position++) {
                $returns[] = 'if ($condition === '.$position.') { return $agent->stream("private"); }';
            }
            $source = 'namespace App; class Agent implements \\Laravel\\Ai\\Contracts\\Agent { use \\Laravel\\Ai\\Promptable; public function instructions(): string { return ""; } }'
                .' class Factory { public static function response(Agent $agent, $condition) { '.implode(' ', $returns).' } }'
                .' function consume() { Factory::response(new Agent, $condition)->then(fn ($response) => null); }';
            $index = new CatalogIndex([...$this->facts($source), $this->package()]);
            $this->assertCount(128, $this->edges($index, 'ai-response-then'));
            $messages = array_column($index->diagnostics, 'message');
            $limit = 'AI response factories exceed their source candidate budget.';
            if ($count === 128) {
                $this->assertNotContains($limit, $messages);
            } else {
                $this->assertContains($limit, $messages);
            }
        }
    }

    public function test_response_factories_connect_callbacks_to_cross_file_invocations_and_preserve_return_choices(): void
    {
        $factory = $this->facts(<<<'SOURCE'
namespace App;
class Agent implements \Laravel\Ai\Contracts\Agent { use \Laravel\Ai\Promptable; public function instructions(): string { return ''; } }
class Factory {
    public static function stream(Agent $agent) { return $agent->stream('private'); }
    public static function queued(Agent $agent) { $response = $agent->queue('private'); return $response; }
    public static function mixed(Agent $agent, $condition) {
        if ($condition) { return $agent->stream('private'); }
        return $agent->queue('private');
    }
    public static function broken(Agent $agent) { return $agent->queue('private')->then('invalid'); }
}
SOURCE, 'app/Factory.php');
        $consumer = $this->facts(<<<'SOURCE'
namespace App;
function consume(Agent $agent) {
    $stream = Factory::stream($agent);
    $stream->then(fn ($response) => null);
    $queued = Factory::queued(new Agent);
    $queued->catch(fn ($error) => null);
    $mixed = Factory::mixed(new Agent, $condition);
    $mixed->then(fn ($response) => null);
    $broken = Factory::broken(new Agent);
    $broken->then(fn ($response) => null);
}
SOURCE, 'app/Consumer.php');
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), [...$factory, ...$consumer]);
        $index = new CatalogIndex([...$cached, $this->package()]);
        $callbacks = [...$this->edges($index, 'ai-response-then'), ...$this->edges($index, 'ai-response-catch')];
        $this->assertCount(4, $callbacks, json_encode(array_map(fn ($edge) => [$index->elements[$index->elements[$edge['from']]['parent']]['name'], $edge['kind']], $callbacks), JSON_THROW_ON_ERROR));
        foreach ($callbacks as $edge) {
            $this->assertSame('app/Factory.php', $index->elements[$edge['from']]['path']);
            $this->assertSame('app/Consumer.php', $edge['path']);
            $this->assertTrue($edge['metadata']['response_return_path_choice_required']);
            $this->assertSame('app/Factory.php', $edge['metadata']['response_producer_sources'][0]['path']);
            $this->assertFalse($edge['metadata']['agent_origin_unknown'] ?? false);
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
        $then = $this->edges($index, 'ai-response-then');
        $this->assertCount(3, $then);
        $this->assertSame($then[1]['to'], $then[2]['to']);
        $this->assertNotSame($then[1]['from'], $then[2]['from']);
        $this->assertNotSame($then[1]['metadata']['queue_delivery_required'], $then[2]['metadata']['queue_delivery_required']);
    }

    #[DataProvider('inspectedAiVersions')]
    public function test_typed_responses_offer_conditional_callbacks_without_inventing_agent_origins(string $version, string $reference): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
use Laravel\Ai\Responses\StreamableAgentResponse as Stream;
use Laravel\Ai\Responses\QueuedAgentResponse as Queued;
class Service { public static function finish($response) {} }
class Decoy { public function then($callback) {} }
class Custom extends Stream { public function then($callback): self { return $this; } }
class Inherited extends Stream {}
function stream(Stream $response) {
    $response->each(fn ($event) => null)->then(Service::finish(...));
}
function queued(Queued $response) {
    $response->then(fn ($value) => null)->catch(fn ($error) => null);
}
function stored(Queued $response) {
    $next = $response->then(fn ($value) => null);
    $next->catch(fn ($error) => null);
}
function inherited(Inherited $response) { $response->then(Service::finish(...)); }
function invalid(Stream $stream, Queued $queued, Decoy $decoy, Custom $custom) {
    $stream->catch(fn ($error) => null);
    $queued->each(fn ($event) => null);
    $queued->then('App\\Service::finish');
    $decoy->then(Service::finish(...));
    $custom->then(Service::finish(...));
    $broken = $queued->then('App\\Service::finish');
    $broken->catch(fn ($error) => null);
}
SOURCE);
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$cached, $this->package($version, reference: $reference)]);
        $callbacks = [...$this->edges($index, 'ai-response-then'), ...$this->edges($index, 'ai-response-each'), ...$this->edges($index, 'ai-response-catch')];
        $this->assertCount(7, $callbacks);
        foreach ($callbacks as $edge) {
            $this->assertTrue($edge['metadata']['agent_origin_unknown']);
            $this->assertTrue($edge['metadata']['typed_response_candidate']);
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertContains($index->elements[$edge['from']]['name'], ['App\\stream', 'App\\queued', 'App\\stored', 'App\\inherited']);
        }
        $this->assertSame([], $this->edges($index, 'invokes-ai-agent'));
        $absent = new CatalogIndex($cached);
        $this->assertSame([], $this->edges($absent, 'ai-response-then'));
    }

    public function test_named_callback_factories_select_source_functions_and_methods_and_reject_queue_strings_and_cycles(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Agent implements \Laravel\Ai\Contracts\Agent { use \Laravel\Ai\Promptable; public function instructions(): string { return ''; } }
function finish($response) {}
class Service { public static function finish($response) {} }
class Factory {
    public static function name() { return 'App\\finish'; }
    public static function method() { return 'App\\Service::finish'; }
    public static function keyed() { return [1 => 'finish', 0 => 'App\\Service']; }
    public static function invalid() { return 'private-sentinel-not-a-callable'; }
    public static function recursive() { return self::recursive(); }
}
function run(Agent $agent) {
    $agent->stream('private-prompt')->then(Factory::name());
    $stored = Factory::method();
    $agent->stream('private-prompt')->then($stored);
    $agent->stream('private-prompt')->then(Factory::keyed());
    $queued = $agent->queue('private-prompt')->then(Factory::name());
    $queued->catch(fn ($error) => null);
    $agent->stream('private-prompt')->then(Factory::invalid());
    $agent->stream('private-prompt')->then(Factory::recursive());
}
SOURCE);
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$cached, $this->package()]);
        $then = $this->edges($index, 'ai-response-then');
        $names = array_map(fn ($edge) => $index->elements[$edge['to']]['name'], $then);
        sort($names);
        $this->assertSame(['App\\Service::finish', 'App\\Service::finish', 'App\\finish'], $names);
        foreach ($then as $edge) {
            $this->assertTrue($edge['metadata']['callback_return_path_choice_required']);
            $this->assertFalse($edge['metadata']['queue_delivery_required']);
        }
        $this->assertSame([], $this->edges($index, 'ai-response-catch'));
        $this->assertContains('catalog_limit', array_column($index->diagnostics, 'code'));
        $this->assertStringNotContainsString('private-sentinel-not-a-callable', json_encode(array_map(fn ($fact) => $fact->toArray(), $facts), JSON_THROW_ON_ERROR));
    }

    public function test_source_factories_return_callbacks_with_return_evidence_and_without_type_only_inference(): void
    {
        $factories = $this->facts(<<<'SOURCE'
namespace App;
class Handler { public function __invoke($response) {} }
class Factory {
    private static function finish($response) {}
    public static function callback() { return self::finish(...); }
    public static function inline() { return fn ($response) => null; }
    public static function object() { return new Handler; }
    public static function mixed($condition) { if ($condition) { return self::finish(...); } return new Handler; }
    public static function unknown(): \Closure { return dynamicFactory(); }
    private static function inaccessible() { return fn ($response) => null; }
}
SOURCE, 'app/Factory.php');
        $consumer = $this->facts(<<<'SOURCE'
namespace App;
class Agent implements \Laravel\Ai\Contracts\Agent { use \Laravel\Ai\Promptable; public function instructions(): string { return ''; } }
function run(Agent $agent) {
    $callback = Factory::callback();
    $agent->queue('private')->then($callback);
    $agent->stream('private')->then(Factory::inline());
    $agent->stream('private')->then(Factory::object());
    $agent->queue('private')->then(Factory::mixed($condition));
    $invalid = $agent->queue('private')->then(Factory::object());
    $invalid->catch(fn ($error) => null);
    $agent->stream('private')->then(Factory::unknown());
    $agent->stream('private')->then(Factory::inaccessible());
}
SOURCE, 'app/Consumer.php');
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), [...$factories, ...$consumer]);
        $index = new CatalogIndex([...$cached, $this->package()]);
        $then = $this->edges($index, 'ai-response-then');
        $this->assertCount(4, $then);
        foreach ($then as $edge) {
            $this->assertTrue($edge['metadata']['callback_return_path_choice_required']);
            $this->assertSame('app/Factory.php', $edge['metadata']['callback_return_sources'][0]['path']);
            $this->assertSame('app/Consumer.php', $edge['path']);
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
        $queued = array_values(array_filter($then, fn ($edge) => $edge['metadata']['queue_delivery_required']));
        $this->assertCount(2, $queued);
        foreach ($queued as $edge) {
            $this->assertSame('App\\Factory::finish', $index->elements[$edge['to']]['name']);
        }
        $this->assertSame([], $this->edges($index, 'ai-response-catch'));
        $this->assertContains('package_ai_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_stored_named_callbacks_and_keyed_arrays_keep_selectors_without_payloads(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace { function finish($response) {} }
namespace App {
class Agent implements \Laravel\Ai\Contracts\Agent { use \Laravel\Ai\Promptable; public function instructions(): string { return ''; } }
class Service { public static function finish($response) {} public function delta($response) {} }
function finish($response) {}
function run(Agent $agent, Service $service) {
    $name = 'finish';
    $agent->stream('private-payload-sentinel')->then($name);
    $qualified = '\\App\\finish';
    $agent->stream('private-payload-sentinel')->then($qualified);
    $method = 'App\\Service::finish';
    $agent->stream('private-payload-sentinel')->then($method);
    $array = [1 => 'finish', 0 => Service::class];
    $agent->stream('private-payload-sentinel')->then($array);
    $stringArray = [1 => 'finish', 0 => 'App\\Service'];
    $agent->stream('private-payload-sentinel')->then($stringArray);
    $agent->stream('private-payload-sentinel')->each([1 => 'delta', 0 => $service]);
    $agent->stream('private-payload-sentinel')->then([0 => Service::class, 0 => 'finish']);
    $agent->stream('private-payload-sentinel')->then([1 => Service::class, 'finish']);
    $queued = 'App\\Service::finish';
    $invalid = $agent->queue('private-payload-sentinel')->then($queued);
    $invalid->catch(fn ($error) => null);
    $changed = 'App\\Service::finish';
    unknownMutator($changed);
    $agent->stream('private-payload-sentinel')->then($changed);
}
}
SOURCE);
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$cached, $this->package()]);
        $names = array_map(fn ($edge) => $index->elements[$edge['to']]['name'], $this->edges($index, 'ai-response-then'));
        sort($names);
        $this->assertSame(['App\\Service::finish', 'App\\Service::finish', 'App\\Service::finish', 'App\\finish', 'finish'], $names);
        $this->assertCount(1, $this->edges($index, 'ai-response-each'));
        $this->assertSame([], $this->edges($index, 'ai-response-catch'));
        $serialized = json_encode(array_map(fn ($fact) => $fact->toArray(), $facts), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('private-payload-sentinel', $serialized);
        $this->assertContains('package_ai_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_stored_callbacks_follow_source_values_and_reject_mutation_and_queue_objects(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Agent implements \Laravel\Ai\Contracts\Agent { use \Laravel\Ai\Promptable; public function instructions(): string { return ''; } }
class Service {
    public static function finish($response) {}
    public function delta($response) {}
    private static function hidden($response) {}
    public function run(Agent $agent) { $callback = self::hidden(...); $agent->queue('private')->then($callback); }
}
class Handler { public function __invoke($response) {} }
class Decoy { public function finish($response) {} }
function finish($response) {}
function run(Agent $agent, Service $service) {
    $callback = Service::finish(...);
    $alias = $callback;
    $callback = new Decoy;
    $agent->stream('private')->then($alias);
    $agent->stream('private')->then($callback);
    $method = $service->delta(...);
    $agent->stream('private')->each($method);
    $function = finish(...);
    $agent->queue('private')->then($function);
    $closure = fn ($response) => null;
    $agent->queue('private')->then($closure);
    $array = [Service::class, 'finish'];
    $agent->stream('private')->then($array);
    $object = new Handler;
    $agent->stream('private')->then($object);
    $invalid = new Handler;
    $broken = $agent->queue('private')->then($invalid);
    $broken->catch(fn ($error) => null);
    $agent->queue('private')->then(new Handler)->catch(fn ($error) => null);
    $changed = Service::finish(...);
    unknownMutator($changed);
    $agent->stream('private')->then($changed);
}
SOURCE);
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$cached, $this->package()]);
        $then = $this->edges($index, 'ai-response-then');
        $this->assertCount(6, $then);
        $names = array_map(fn ($edge) => $index->elements[$edge['to']]['name'], $then);
        $this->assertContains('App\\Service::hidden', $names);
        $this->assertContains('App\\Handler::__invoke', $names);
        $this->assertContains('App\\finish', $names);
        $this->assertSame(2, count(array_filter($names, fn ($name) => $name === 'App\\Service::finish')));
        $each = $this->edges($index, 'ai-response-each');
        $this->assertCount(1, $each);
        $this->assertSame('App\\Service::delta', $index->elements[$each[0]['to']]['name']);
        $this->assertSame([], $this->edges($index, 'ai-response-catch'));
        $this->assertContains('package_ai_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_stream_array_and_named_method_callbacks_respect_visibility_static_form_and_instance_context(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Agent implements \Laravel\Ai\Contracts\Agent { use \Laravel\Ai\Promptable; public function instructions(): string { return ''; } }
class ParentService { public static function inherited($response) {} }
class Service extends ParentService {
    public static function finish($response) {}
    public function delta($response) {}
    private static function hidden($response) {}
    public function run(Agent $agent) {
        $agent->stream('private')->then(self::delta(...));
        $agent->stream('private')->then('App\\Service::hidden');
    }
    public static function wrong(Agent $agent) { $agent->stream('private')->then(self::delta(...)); }
}
function run(Agent $agent, Service $service) {
    $agent->stream('private')->then([Service::class, 'finish']);
    $agent->stream('private')->then([$service, 'delta']);
    $agent->stream('private')->then('App\\Service::finish');
    $agent->stream('private')->then(['App\\Service', 'finish']);
    $agent->stream('private')->then([1 => 'App\\Service', 0 => 'finish']);
    $agent->stream('private')->then('\\APP\\SERVICE::inherited');
    $agent->stream('private')->then([Service::class, 'delta']);
    $agent->stream('private')->then([Service::class, 'hidden']);
    $agent->stream('private')->then('App\\Service::hidden');
    $agent->stream('private')->then('App\\Service::delta');
    $agent->queue('private')->then([Service::class, 'finish']);
}
SOURCE);
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$cached, $this->package()]);
        $edges = $this->edges($index, 'ai-response-then');
        $names = array_map(fn ($edge) => $index->elements[$edge['to']]['name'], $edges);
        sort($names);
        $this->assertSame(['App\\ParentService::inherited', 'App\\Service::delta', 'App\\Service::delta', 'App\\Service::finish', 'App\\Service::finish', 'App\\Service::finish'], $names);
        foreach ($edges as $edge) {
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertFalse($edge['metadata']['queue_delivery_required']);
        }
        $this->assertContains('package_ai_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_reused_callback_facts_follow_current_cross_file_visibility_and_deletion(): void
    {
        $caller = $this->facts(<<<'SOURCE'
namespace App;
class Agent implements \Laravel\Ai\Contracts\Agent { use \Laravel\Ai\Promptable; public function instructions(): string { return ''; } }
function run(Agent $agent) { $agent->stream('private')->then(Service::finish(...)); }
SOURCE, 'app/Caller.php');
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $caller);
        foreach (['public', 'private'] as $visibility) {
            $service = $this->facts('namespace App; class Service { '.$visibility.' static function finish($response) {} }', 'app/Service.php');
            $index = new CatalogIndex([...$cached, ...$service, $this->package()]);
            $edges = $this->edges($index, 'ai-response-then');
            $this->assertCount($visibility === 'public' ? 1 : 0, $edges);
            if ($edges !== []) {
                $this->assertSame('App\\Service::finish', $index->elements[$edges[0]['to']]['name']);
                $this->assertSame('app/Caller.php', $edges[0]['path']);
                $this->assertSame('app/Service.php', $index->elements[$edges[0]['to']]['path']);
            }
        }
        $index = new CatalogIndex([...$cached, $this->package()]);
        $this->assertSame([], $this->edges($index, 'ai-response-then'));
        $this->assertContains('package_ai_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_named_class_first_class_callbacks_bind_only_compatible_creation_instances(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Agent implements \Laravel\Ai\Contracts\Agent { use \Laravel\Ai\Promptable; public function instructions(): string { return ''; } }
class Base { protected function complete($response) {} private function hidden($response) {} }
class Other { public function complete($response) {} public static function finish($response) {} }
class Service extends Base {
    private function save($response) {}
    public function callback() { return Service::save(...); }
    public function run(Agent $agent) {
        $agent->stream('private')->then(Service::save(...));
        $agent->queue('private')->then(Base::complete(...));
        $callback = Service::save(...);
        $agent->queue('private')->then($callback);
        $returned = $this->callback();
        $agent->stream('private')->then($returned);
        $agent->stream('private')->then(Other::complete(...));
        $wrong = Other::complete(...);
        $agent->stream('private')->then($wrong);
        $agent->stream('private')->then(Base::hidden(...));
        $agent->stream('private')->then(Other::finish(...));
        $static = static fn () => Service::save(...);
        $agent->stream('private')->then($static());
    }
    public static function unbound(Agent $agent) {
        $agent->stream('private')->then(Service::save(...));
        $callback = Service::save(...);
        $agent->stream('private')->then($callback);
    }
}
function unbound(Agent $agent) {
    $agent->stream('private')->then(Service::save(...));
    $callback = Service::save(...);
    $agent->stream('private')->then($callback);
}
SOURCE);
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$cached, $this->package()]);
        $edges = $this->edges($index, 'ai-response-then');
        $names = array_map(fn ($edge) => $index->elements[$edge['to']]['name'], $edges);
        sort($names);
        $this->assertSame(['App\\Base::complete', 'App\\Other::finish', 'App\\Service::save', 'App\\Service::save', 'App\\Service::save'], $names);
        foreach ($edges as $edge) {
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
        $this->assertContains('package_ai_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_response_first_class_callables_select_source_methods_and_functions_without_claiming_execution(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Agent implements \Laravel\Ai\Contracts\Agent { use \Laravel\Ai\Promptable; public function instructions(): string { return ''; } }
class Service {
    public static function complete($response) {}
    public function delta($event) {}
    private static function hidden($response) {}
    public function run(Agent $agent) { $agent->queue('private')->then(self::hidden(...)); }
}
function finish($response) {}
function run(Agent $agent, Service $service) {
    $agent->stream('private')->then(Service::complete(...));
    $agent->stream('private')->each($service->delta(...));
    $agent->queue('private')->then(finish(...));
    $agent->stream('private')->then(Service::hidden(...));
    $agent->stream('private')->then(Service::delta(...));
}
SOURCE);
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$cached, $this->package()]);
        $then = $this->edges($index, 'ai-response-then');
        $names = array_map(fn ($edge) => $index->elements[$edge['to']]['name'], $then);
        sort($names);
        $this->assertSame(['App\\Service::complete', 'App\\Service::hidden', 'App\\finish'], $names);
        $each = $this->edges($index, 'ai-response-each');
        $this->assertCount(1, $each);
        $this->assertSame('App\\Service::delta', $index->elements[$each[0]['to']]['name']);
        foreach ([...$then, ...$each] as $edge) {
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
        $this->assertContains('package_ai_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_named_functions_use_global_names_and_queued_callbacks_require_closures_even_in_producer_chains(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace { function finish($response) {} }
namespace App {
class Agent implements \Laravel\Ai\Contracts\Agent { use \Laravel\Ai\Promptable; public function instructions(): string { return ''; } }
function finish($response) {}
function run(Agent $agent) {
    $agent->stream('private')->then('finish');
    $agent->stream('private')->then('\\App\\finish');
    $agent->stream('private')->then('APP\\FINISH');
    $agent->queue('private')->then('finish');
    $agent->queue('private')->then('finish')->catch(fn ($error) => null);
    $invalid = $agent->queue('private')->then(['App\\Service', 'finish']);
    $invalid->catch(fn ($error) => null);
    $agent->stream('private')->then('missing');
}
}
SOURCE);
        $index = new CatalogIndex([...$facts, $this->package()]);
        $then = $this->edges($index, 'ai-response-then');
        $names = array_map(fn ($edge) => $index->elements[$edge['to']]['name'], $then);
        sort($names);
        $this->assertSame(['App\\finish', 'App\\finish', 'finish'], $names);
        $this->assertSame([], $this->edges($index, 'ai-response-catch'));
        foreach ($then as $edge) {
            $this->assertFalse($edge['metadata']['queue_delivery_required']);
        }
        $this->assertContains('package_ai_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_stored_fluent_response_origins_follow_valid_producers_and_reject_invalid_intermediate_calls(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Agent implements \Laravel\Ai\Contracts\Agent { use \Laravel\Ai\Promptable; public function instructions(): string { return ''; } }
function run(Agent $agent) {
    $response = $agent->stream('private-prompt');
    $next = $response->then(fn ($value) => null);
    $last = $next->each(fn ($event) => null);
    $last->then(fn ($value) => null);
    $queued = $agent->queue('private-prompt')->then(fn ($value) => null);
    $queued->catch(fn ($error) => null);
    $broken = $response->then();
    $broken->then(fn ($value) => null);
    $wrong = $agent->queue('private-prompt')->each(fn ($event) => null);
    $wrong->then(fn ($value) => null);
}
SOURCE);
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$cached, $this->package()]);
        $then = $this->edges($index, 'ai-response-then');
        $this->assertCount(3, $then);
        $this->assertCount(1, $this->edges($index, 'ai-response-each'));
        $this->assertCount(1, $this->edges($index, 'ai-response-catch'));
        $this->assertSame($then[0]['from'], $then[1]['from']);
        $this->assertNotSame($then[0]['from'], $then[2]['from']);
        $this->assertTrue($this->edges($index, 'ai-response-catch')[0]['metadata']['job_failure_required']);
        $this->assertContains('package_ai_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_stored_response_aliases_retain_exact_invocation_origin_and_mutation_discards_stale_origin(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Agent implements \Laravel\Ai\Contracts\Agent { use \Laravel\Ai\Promptable; public function instructions(): string { return ''; } }
class Decoy { public function stream($prompt) {} public function each($callback) {} }
function run(Agent $agent, Decoy $decoy) {
    $stream = $agent->stream('stream-private');
    $queued = $agent->queue('queue-private');
    $alias = $stream;
    $stream->then(fn ($response) => null);
    $queued->then(fn ($response) => null);
    $alias->each(fn ($event) => null);
    $stream = $decoy->stream('decoy-private');
    $stream->then(fn ($response) => null);
    $alias->then(fn ($response) => null);
    unknownMutator($queued);
    $queued->catch(fn ($error) => null);
    if ($condition) { $alias = $decoy->stream('other'); }
    $alias->then(fn ($response) => null);
    $decoy->each(fn ($value) => null);
}
SOURCE);
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$cached, $this->package()]);
        $then = $this->edges($index, 'ai-response-then');
        $this->assertCount(3, $then);
        $this->assertCount(1, $this->edges($index, 'ai-response-each'));
        $this->assertSame([], $this->edges($index, 'ai-response-catch'));
        $streamCallbacks = array_values(array_filter($then, fn ($edge) => ! $edge['metadata']['queue_delivery_required']));
        $queueCallbacks = array_values(array_filter($then, fn ($edge) => $edge['metadata']['queue_delivery_required']));
        $this->assertCount(2, $streamCallbacks);
        $this->assertCount(1, $queueCallbacks);
        $this->assertSame($streamCallbacks[0]['from'], $streamCallbacks[1]['from']);
        $this->assertNotSame($streamCallbacks[0]['from'], $queueCallbacks[0]['from']);
        $this->assertFalse($then[2]['metadata']['execution_proven']);
        $serialized = json_encode(array_map(fn ($fact) => $fact->toArray(), $facts), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('stream-private', $serialized);
        $this->assertStringNotContainsString('queue-private', $serialized);
        $index = new CatalogIndex($cached);
        $this->assertSame([], $this->edges($index, 'ai-response-then'));
    }

    public function test_repeated_callbacks_keep_distinct_bodies_and_expose_chain_budget(): void
    {
        foreach ([16, 17] as $count) {
            $source = 'namespace App; class Agent implements \\Laravel\\Ai\\Contracts\\Agent { use \\Laravel\\Ai\\Promptable; public function instructions(): string { return ""; } } function run(Agent $agent) { $agent->stream("secret")'
                .str_repeat('->then(fn ($response) => null)', $count).'; }';
            $facts = $this->facts($source);
            $index = new CatalogIndex([...$facts, $this->package()]);
            $edges = $this->edges($index, 'ai-response-then');
            $this->assertCount(16, $edges);
            $this->assertCount(16, array_unique(array_column($edges, 'to')));
            $codes = array_column($index->diagnostics, 'code');
            if ($count === 17) {
                $this->assertContains('catalog_limit', $codes);
            } else {
                $this->assertNotContains('catalog_limit', $codes);
            }
        }
    }

    #[DataProvider('inspectedAiVersions')]
    public function test_stream_and_queue_callbacks_keep_consumption_success_and_failure_distinct(string $version, string $reference): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Agent implements \Laravel\Ai\Contracts\Agent {
    use \Laravel\Ai\Promptable;
    public function instructions(): string { return ''; }
}
class Decoy { public function stream($prompt) {} }
function run(Agent $agent, Decoy $decoy) {
    $agent->stream('callback-secret')->each(fn ($event) => Service::delta())->then(callback: fn ($response) => Service::complete());
    $agent->queue('queue-secret')->then(fn ($response) => Service::success())->catch(fn ($error) => Service::failure());
    $agent->queue('invalid')->each(fn ($event) => Service::invalid())->then(fn ($response) => Service::invalid());
    $agent->stream('invalid')->catch(fn ($error) => Service::invalid())->then(fn ($response) => Service::invalid());
    $decoy->stream('decoy')->then(fn ($response) => Service::invalid());
    $agent->stream('dynamic')->then($callback);
    $agent->stream('invalid-arity')->then()->then(fn ($response) => Service::invalid());
    $agent->queue('invalid-name')->then(wrong: fn ($response) => Service::invalid())->catch(fn ($error) => Service::invalid());
    $agent->queue('invalid-type')->then(42)->then(fn ($response) => Service::invalid());
}
class Service { public static function delta() {} public static function complete() {} public static function success() {} public static function failure() {} public static function invalid() {} }
SOURCE);
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$cached, $this->package($version, reference: $reference)]);
        $each = $this->edges($index, 'ai-response-each');
        $then = $this->edges($index, 'ai-response-then');
        $catch = $this->edges($index, 'ai-response-catch');
        $this->assertCount(1, $each);
        $this->assertCount(2, $then);
        $this->assertCount(1, $catch);
        $this->assertTrue($each[0]['metadata']['stream_consumption_required']);
        $this->assertFalse($each[0]['metadata']['stream_completion_required']);
        $this->assertTrue($catch[0]['metadata']['queue_delivery_required']);
        $this->assertTrue($catch[0]['metadata']['job_failure_required']);
        $this->assertSame([false, true], array_column(array_column($then, 'metadata'), 'agent_success_required'));
        $this->assertSame([true, false], array_column(array_column($then, 'metadata'), 'stream_completion_required'));
        foreach ([...$each, ...$then, ...$catch] as $edge) {
            $this->assertSame('ai-invocation', $index->elements[$edge['from']]['kind']);
            $this->assertSame('closure', $index->elements[$edge['to']]['kind']);
            $this->assertSame('conditional', $edge['resolution']);
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
        $this->assertContains('package_ai_analysis', array_column($index->diagnostics, 'code'));
        $this->assertStringNotContainsString('callback-secret', json_encode($index->relations, JSON_THROW_ON_ERROR));
        $shadow = $this->facts('namespace Laravel\\Ai\\Responses; class StreamableAgentResponse {}', 'app/Shadow.php');
        $index = new CatalogIndex([...$cached, ...$shadow, $this->package($version, reference: $reference)]);
        $this->assertSame([], $this->edges($index, 'ai-response-each'));
        $this->assertCount(1, $this->edges($index, 'ai-response-then'));
    }

    /** @return list<CatalogFacts> */
    private function facts(string $source, string $path = 'app/Ai.php'): array
    {
        return (new ProjectGraphBuilder(catalog: true))->build([new FileContext($path, '<?php '.$source)])->catalogFacts;
    }

    private function package(string $version = '0.11.2', string $path = 'composer.lock', ?string $reference = null): CatalogFacts
    {
        $package = ['name' => 'laravel/ai', 'version' => $version];
        if ($reference !== null) {
            $package['source'] = ['reference' => $reference];
        }

        return (new ComposerCatalogExtractor)->extract(new FileContext($path, json_encode(['packages' => [$package]], JSON_THROW_ON_ERROR)));
    }

    /** @return list<array<string, mixed>> */
    private function edges(CatalogIndex $index, string $kind): array
    {
        return array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === $kind));
    }

    public static function inspectedAiVersions(): array
    {
        return array_map(fn ($version, $reference) => [$version, $reference], array_keys(CatalogPackageVersions::AI_REFERENCES), array_values(CatalogPackageVersions::AI_REFERENCES));
    }

    #[DataProvider('inspectedAiVersions')]
    public function test_agents_tools_and_six_entry_forms_separate_queue_delivery_and_tool_selection(string $version, string $reference): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
use Laravel\Ai\Contracts\Agent as AgentContract;
use Laravel\Ai\Promptable as Prompt;
class Lookup implements \Laravel\Ai\Contracts\Tool {
    public function description(): string { return 'description-secret-sentinel'; }
    public function schema($schema): array { return []; }
    public function handle($request): string { return Service::read(); }
}
class Base implements AgentContract, \Laravel\Ai\Contracts\HasTools {
    use Prompt;
    public function instructions(): string { return 'instructions-secret-sentinel'; }
    public function tools(): iterable { return [new Lookup()]; }
}
class Agent extends Base {}
class Service { public static function read(): string { return ''; } }
class Consumer {
    public function direct(Agent $agent) {
        $agent->prompt(prompt: 'prompt-secret-sentinel', attachments: ['attachment-secret-sentinel']);
        $agent->stream('secret-stream');
        $agent->broadcast('secret-broadcast', ['private-channel']);
        $agent->broadcastNow('secret-now', ['private-channel']);
        Agent::make()->queue('secret-queued');
        Agent::make()->broadcastOnQueue('secret-queued-broadcast', ['private-channel']);
    }
}
throw new \RuntimeException('source-only-sentinel');
SOURCE);
        $serialized = json_encode(array_map(fn ($fact) => $fact->toArray(), $facts), JSON_THROW_ON_ERROR);
        foreach (['description-secret-sentinel', 'instructions-secret-sentinel', 'prompt-secret-sentinel', 'attachment-secret-sentinel', 'source-only-sentinel', 'secret-queued'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
        $facts = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$facts, $this->package($version, reference: $reference)]);
        $this->assertContains('ai-agent', $index->elements[$index->namedTypes('App\\Agent')[0]]['roles']);
        $this->assertContains('ai-tool', $index->elements[$index->namedTypes('App\\Lookup')[0]]['roles']);
        $this->assertCount(4, $this->edges($index, 'starts-ai-invocation'));
        $queued = $this->edges($index, 'queues-ai-invocation');
        $this->assertCount(2, $queued);
        $this->assertCount(6, $this->edges($index, 'invokes-ai-agent'));
        $this->assertCount(6, $this->edges($index, 'ai-source-instructions'));
        $this->assertCount(6, $this->edges($index, 'ai-source-tools'));
        $tools = $this->edges($index, 'ai-selected-tool-handler');
        $this->assertCount(2, $tools);
        foreach ($tools as $edge) {
            $this->assertTrue($edge['metadata']['model_tool_selection_required']);
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertSame('App\\Lookup::handle', $index->elements[$edge['to']]['name']);
        }
        foreach ($queued as $edge) {
            $this->assertTrue($edge['metadata']['queue_delivery_required']);
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertNotEmpty($edge['metadata']['package_sources']);
            $this->assertSame($version, $edge['metadata']['version']);
            $this->assertSame($reference, $edge['metadata']['package_sources'][0]['source_reference']);
        }
        foreach ([[], [$this->package('99.0.0')], [$this->package(), $this->package('0.8.0', 'vendor/composer/installed.json')]] as $packages) {
            $index = new CatalogIndex([...$facts, ...$packages]);
            $this->assertSame([], $this->edges($index, 'invokes-ai-agent'));
            $this->assertSame([], $this->edges($index, 'ai-selected-tool-handler'));
            $this->assertContains('package_ai_analysis', array_column($index->diagnostics, 'code'));
        }
    }

    public function test_source_overrides_lookalikes_invalid_arguments_and_dynamic_tools_do_not_fake_sdk_invocation(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class Decoy { public function prompt($prompt) {} public static function make() { return new static(); } }
class Agent implements \Laravel\Ai\Contracts\Agent, \Laravel\Ai\Contracts\HasTools {
    use \Laravel\Ai\Promptable;
    public function instructions(): string { return ''; }
    public function tools(): iterable { return $dynamic; }
}
class Override extends Agent { public function prompt($prompt) { return null; } }
function consumer(Agent $agent, Override $override, Decoy $decoy) {
    $decoy->prompt('fake');
    $override->prompt('fake');
    $agent->prompt();
    $agent->broadcast('missing-channel');
    Agent::prompt('static-invalid');
    $agent->stream('valid');
}
SOURCE);
        $index = new CatalogIndex([...$facts, $this->package()]);
        $this->assertCount(1, $this->edges($index, 'invokes-ai-agent'));
        $this->assertSame([], $this->edges($index, 'ai-selected-tool-handler'));
        $this->assertContains('package_ai_analysis', array_column($index->diagnostics, 'code'));
        $shadow = $this->facts('namespace Laravel\\Ai; trait Promptable {}', 'app/Shadow.php');
        $index = new CatalogIndex([...$facts, ...$shadow, $this->package()]);
        $this->assertSame([], $this->edges($index, 'invokes-ai-agent'));
    }

    #[DataProvider('inspectedAiVersions')]
    public function test_nested_agents_use_prompt_hooks_conditionally_and_source_overrides_suppress_delivery(string $version, string $reference): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
use Laravel\Ai\Contracts\Agent as AgentContract;
use Laravel\Ai\Promptable as Prompt;
class Child implements AgentContract, \Laravel\Ai\Contracts\HasTools {
    use Prompt;
    public function instructions(): string { return 'nested-secret-sentinel'; }
    public function tools(): iterable { return []; }
}
class Overridden extends Child { public function prompt($prompt) {} }
class Lookalike { public function prompt($prompt) {} }
class ParentAgent implements AgentContract, \Laravel\Ai\Contracts\HasTools {
    use Prompt;
    public function instructions(): string { return ''; }
    public function tools(): iterable { return [new Child(), new Overridden(), new Lookalike()]; }
}
SOURCE);
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$cached, $this->package($version, reference: $reference)]);
        $selected = $this->edges($index, 'ai-selected-agent');
        $this->assertCount(1, $selected);
        $this->assertSame('App\\Child', $index->elements[$selected[0]['to']]['name']);
        $this->assertTrue($selected[0]['metadata']['model_tool_selection_required']);
        $this->assertTrue($selected[0]['metadata']['runtime_agent_invocation_required']);
        $this->assertFalse($selected[0]['metadata']['execution_proven']);
        $this->assertSame('conditional', $selected[0]['resolution']);
        foreach (['instructions', 'tools'] as $hook) {
            $edges = $this->edges($index, 'ai-selected-agent-'.$hook);
            $this->assertCount(1, $edges);
            $this->assertSame('App\\Child::'.$hook, $index->elements[$edges[0]['to']]['name']);
        }
        $this->assertSame([], $this->edges($index, 'ai-selected-tool-handler'));
        $this->assertStringNotContainsString('nested-secret-sentinel', json_encode($index->relations, JSON_THROW_ON_ERROR));
        $shadow = $this->facts('namespace Laravel\\Ai\\Tools; class AgentTool {}', 'app/Shadow.php');
        $index = new CatalogIndex([...$cached, ...$shadow, $this->package($version, reference: $reference)]);
        $this->assertSame([], $this->edges($index, 'ai-selected-agent'));
        $this->assertSame([], $this->edges($index, 'ai-selected-agent-instructions'));
        $this->assertContains('package_ai_analysis', array_column($index->diagnostics, 'code'));
    }

    public function test_cached_consumers_and_tool_lists_recompose_after_cross_file_contract_and_handler_changes(): void
    {
        $agent = $this->facts(<<<'SOURCE'
namespace App;
class Agent implements \Laravel\Ai\Contracts\Agent, \Laravel\Ai\Contracts\HasTools {
    use \Laravel\Ai\Promptable;
    public function instructions(): string { return ''; }
    public function tools(): iterable { return [new Tool()]; }
}
function consumer(Agent $agent) { return $agent->prompt('secret'); }
SOURCE, 'app/Agent.php');
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $agent);
        $tool = $this->facts('namespace App; class Tool implements \\Laravel\\Ai\\Contracts\\Tool { public function handle($request) {} }', 'app/Tool.php');
        $index = new CatalogIndex([...$cached, ...$tool, $this->package()]);
        $this->assertCount(1, $this->edges($index, 'ai-selected-tool-handler'));
        $decoy = $this->facts('namespace App; class Tool { public function handle($request) {} }', 'app/Tool.php');
        $index = new CatalogIndex([...$cached, ...$decoy, $this->package()]);
        $this->assertSame([], $this->edges($index, 'ai-selected-tool-handler'));
        $private = $this->facts('namespace App; class Tool implements \\Laravel\\Ai\\Contracts\\Tool { private function handle($request) {} }', 'app/Tool.php');
        $index = new CatalogIndex([...$cached, ...$private, $this->package()]);
        $this->assertSame([], $this->edges($index, 'ai-selected-tool-handler'));
        $this->assertCount(1, $this->edges($index, 'invokes-ai-agent'));
    }

    public function test_alternative_and_partial_tool_returns_keep_known_candidates_with_source_conditions(): void
    {
        $facts = $this->facts(<<<'SOURCE'
namespace App;
class First implements \Laravel\Ai\Contracts\Tool { public function handle($request) {} }
class Second implements \Laravel\Ai\Contracts\Tool { public function handle($request) {} }
class Agent implements \Laravel\Ai\Contracts\Agent, \Laravel\Ai\Contracts\HasTools {
    use \Laravel\Ai\Promptable;
    public function instructions(): string { return ''; }
    public function tools(): iterable {
        if ($secretRuntimeCondition) { return [new First(), ...$dynamicTools]; }
        return [new Second()];
    }
}
class Conditional extends Agent {
    public function tools(): iterable { if ($condition) { return [new First()]; } }
}
SOURCE);
        $stored = array_map(fn ($fact) => $fact->toArray(), $facts);
        $serialized = json_encode($stored, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('secretRuntimeCondition', $serialized);
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
        $index = new CatalogIndex([...$cached, $this->package()]);
        $handlers = $this->edges($index, 'ai-selected-tool-handler');
        $this->assertCount(3, $handlers);
        $partial = 0;
        foreach ($handlers as $edge) {
            $this->assertTrue($edge['metadata']['tool_return_path_choice_required']);
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertSame('conditional', $edge['resolution']);
            $this->assertSame('app/Ai.php', $edge['path']);
            $partial += (int) $edge['metadata']['tool_list_partial'];
        }
        $this->assertSame(1, $partial);
        $this->assertContains('package_ai_analysis', array_column($index->diagnostics, 'code'));
        foreach ($facts as $fact) {
            $corrupt = $fact->toArray();
            foreach ($corrupt['elements'] as &$element) {
                if ($element['kind'] === 'ai-tools') {
                    $element['metadata']['conditional'] = 'unknown';
                    unset($element);
                    $this->expectException(\InvalidArgumentException::class);
                    CatalogFacts::fromArray($fact->path, $corrupt);

                    return;
                }
            }
            unset($element);
        }
        $this->fail('Expected a tool list fact to validate.');
    }

    public function test_tools_member_budget_reports_partial_analysis_without_claiming_the_oversized_list(): void
    {
        $source = <<<'SOURCE'
namespace App;
class Tool implements \Laravel\Ai\Contracts\Tool { public function handle($request) {} }
class Agent implements \Laravel\Ai\Contracts\Agent, \Laravel\Ai\Contracts\HasTools {
    use \Laravel\Ai\Promptable;
    public function instructions(): string { return ''; }
    public function tools(): iterable { return [%s]; }
}
SOURCE;
        foreach ([128, 129] as $count) {
            $facts = $this->facts(sprintf($source, implode(',', array_fill(0, $count, 'new Tool()'))));
            $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $facts);
            $index = new CatalogIndex([...$cached, $this->package()]);
            $this->assertCount($count === 128 ? 1 : 0, $this->edges($index, 'ai-selected-tool-handler'));
            if ($count === 129) {
                $this->assertContains('catalog_limit', array_column($index->diagnostics, 'code'));
                $this->assertContains('package_ai_analysis', array_column($index->diagnostics, 'code'));
                $this->assertContains('ai-agent', $index->elements[$index->namedTypes('App\\Agent')[0]]['roles']);
            }
        }
    }

    #[DataProvider('inspectedAiVersions')]
    public function test_cross_file_trait_alias_uses_original_return_body_and_respects_alias_visibility(string $version, string $reference): void
    {
        $trait = $this->facts(<<<'SOURCE'
namespace App;
trait Supplies {
    public function available(): iterable { return [new Tool()]; }
    public function unrelated(): array { return [new Decoy()]; }
}
class Tool implements \Laravel\Ai\Contracts\Tool { public function handle($request) {} }
class Decoy { public function handle($request) {} }
SOURCE, 'app/Supplies.php');
        $cached = array_map(fn ($fact) => CatalogFacts::fromArray($fact->path, $fact->toArray()), $trait);
        $source = <<<'SOURCE'
namespace App;
class Agent implements \Laravel\Ai\Contracts\Agent, \Laravel\Ai\Contracts\HasTools {
    use \Laravel\Ai\Promptable;
    use Supplies { Supplies::available as %s tools; }
    public function instructions(): string { return ''; }
}
SOURCE;
        $index = new CatalogIndex([...$cached, ...$this->facts(sprintf($source, 'public'), 'app/Agent.php'), $this->package($version, reference: $reference)]);
        $handlers = $this->edges($index, 'ai-selected-tool-handler');
        $this->assertCount(1, $handlers);
        $this->assertSame('App\\Tool::handle', $index->elements[$handlers[0]['to']]['name']);
        $this->assertSame('app/Supplies.php', $handlers[0]['path']);
        $this->assertFalse($handlers[0]['metadata']['tool_return_path_choice_required']);
        foreach (['private', 'protected'] as $visibility) {
            $index = new CatalogIndex([...$cached, ...$this->facts(sprintf($source, $visibility), 'app/Agent.php'), $this->package($version, reference: $reference)]);
            $this->assertSame([], $this->edges($index, 'ai-selected-tool-handler'));
            $this->assertContains('package_ai_analysis', array_column($index->diagnostics, 'code'));
        }
        $index = new CatalogIndex([...$cached, ...$this->facts(sprintf($source, 'public'), 'app/Agent.php')]);
        $this->assertSame([], $this->edges($index, 'ai-selected-tool-handler'));
        $unqualified = str_replace('Supplies::available', 'available', sprintf($source, 'public'));
        $index = new CatalogIndex([...$cached, ...$this->facts($unqualified, 'app/Agent.php'), $this->package($version, reference: $reference)]);
        $this->assertCount(1, $this->edges($index, 'ai-selected-tool-handler'));
        $conflicting = str_replace('available', 'prompt', $unqualified);
        $conflictingTrait = $this->facts('namespace App; trait Supplies { public function prompt($prompt): iterable { return [new Tool()]; } } class Tool implements \\Laravel\\Ai\\Contracts\\Tool { public function handle($request) {} }', 'app/Supplies.php');
        $index = new CatalogIndex([...$conflictingTrait, ...$this->facts($conflicting, 'app/Agent.php'), $this->package($version, reference: $reference)]);
        $this->assertSame([], $this->edges($index, 'ai-selected-tool-handler'));
        $this->assertContains('package_ai_analysis', array_column($index->diagnostics, 'code'));
    }
}
