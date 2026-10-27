<?php

declare(strict_types=1);

namespace Orion\Elasticsearch\Tests;

use Orion\Elasticsearch\Mapping;
use Orion\Schema\Field;
use Orion\Schema\FieldType;
use Orion\Schema\IndexSchema;
use Orion\Schema\LiveSchema;
use Orion\Schema\MigrationPlan;
use Orion\Schema\VectorMetric;
use Orion\IndexName;
use PHPUnit\Framework\TestCase;

final class MappingTest extends TestCase
{
    public function testEveryNeutralTypeRoundTripsWithoutDrift(): void
    {
        $schema = IndexSchema::for('products')
            ->text('title', language: 'en', boost: 3.0)
            ->text('description', folding: true)
            ->text('body', language: 'de', folding: true)
            ->keyword('sku', filterable: true, sortable: true)
            ->keyword('brand', facetable: true)
            ->keyword('note')
            ->integer('stock', filterable: true)
            ->float('price', filterable: true, sortable: true, facetable: true)
            ->boolean('in_stock', filterable: true, facetable: true)
            ->date('released', sortable: true)
            ->geo('at', filterable: true, sortable: true)
            ->vector('embedding', dims: 3, metric: VectorMetric::DotProduct)
            ->object('address', Field::keyword('city', filterable: true))
            ->nested('variants', Field::keyword('size', filterable: true), Field::integer('qty', sortable: true));

        $live = new LiveSchema(IndexName::of('products'), Mapping::fields(Mapping::create($schema, 'elasticsearch')['mappings']));

        self::assertTrue(MigrationPlan::between($schema, $live)->isEmpty());
    }

    public function testMappingsAreStrict(): void
    {
        self::assertSame('strict', Mapping::create(IndexSchema::for('products')->keyword('sku'), 'elasticsearch')['mappings']['dynamic']);
    }

    public function testNativeSettingsMergeOverTheGeneratedBody(): void
    {
        $body = Mapping::create(IndexSchema::for('products')->keyword('sku')->native('elasticsearch', ['settings' => ['number_of_replicas' => 0]]), 'elasticsearch');

        self::assertSame(0, $body['settings']['number_of_replicas']);
    }

    public function testNativeSettingsCannotLoosenTheMappingOrForgeItsMeta(): void
    {
        $body = Mapping::create(IndexSchema::for('products')->keyword('sku')->native('elasticsearch', ['mappings' => [
            'dynamic' => true,
            '_meta' => ['orion' => ['fields' => ['forged' => ['type' => 'keyword']]]],
            'properties' => ['sku' => ['type' => 'keyword', 'ignore_above' => 64]],
        ]]), 'elasticsearch');

        self::assertSame('strict', $body['mappings']['dynamic']);
        self::assertSame(['sku' => ['type' => 'keyword']], $body['mappings']['_meta']['orion']['fields']);
        self::assertSame(64, $body['mappings']['properties']['sku']['ignore_above']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function orgKeyOverrides(): iterable
    {
        yield 'retyped as text' => [['properties' => ['org_id' => ['type' => 'text']]]];
        yield 'normalized' => [['properties' => ['org_id' => ['type' => 'keyword', 'normalizer' => 'lowercase']]]];
        yield 'shadowed by a runtime field' => [['runtime' => ['org_id' => ['type' => 'keyword', 'script' => "emit('acme')"]]]];
        yield 'copied into from another field' => [['properties' => ['title' => ['type' => 'text', 'copy_to' => 'org_id']]]];
        yield 'copied into from a nested field' => [['properties' => ['meta' => ['properties' => ['owner' => ['type' => 'keyword', 'copy_to' => ['x', 'org_id']]]]]]];
    }

    /**
     * @param array<string, mixed> $mappings
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('orgKeyOverrides')]
    public function testNativeMappingsCannotTouchTheOrgKey(array $mappings): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('org_id');

        Mapping::create(IndexSchema::for('products')->text('title')->orgKey('org_id')->native('elasticsearch', ['mappings' => $mappings]), 'elasticsearch');
    }

    public function testNativeMappingsMayStillNameTheOrgKeyElsewhere(): void
    {
        $body = Mapping::create(IndexSchema::for('products')->text('title')->orgKey('org_id')->native('elasticsearch', ['mappings' => ['_source' => ['excludes' => ['org_id']]]]), 'elasticsearch');

        self::assertSame(['org_id'], $body['mappings']['_source']['excludes']);
    }

    public function testNativeSettingsAreSettingsAndMappingsOnly(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Mapping::create(IndexSchema::for('products')->keyword('sku')->native('elasticsearch', ['aliases' => ['all' => new \stdClass()]]), 'elasticsearch');
    }

    public function testNativeTypesWithoutANeutralEquivalentAreUnknown(): void
    {
        $fields = Mapping::fields(['properties' => [
            'ratio' => ['type' => 'float'],
            'title' => ['type' => 'text', 'analyzer' => 'my_custom'],
            'tags' => ['type' => 'percolator'],
        ]]);

        self::assertSame([FieldType::Unknown, FieldType::Unknown, FieldType::Unknown], [$fields[0]->type, $fields[1]->type, $fields[2]->type]);
    }

    public function testWithoutMetaDocValuesMeanSortableAndFacetable(): void
    {
        $field = Mapping::fields(['properties' => ['sku' => ['type' => 'keyword']]])[0];

        self::assertTrue($field->filterable);
        self::assertTrue($field->sortable);
        self::assertTrue($field->facetable);
    }

    public function testVectorFieldsAreLeftOutOfTheSourceOnEveryVersion(): void
    {
        $schema = IndexSchema::for('products')->text('title')->vector('embedding', 3)->with(Field::object('variants', Field::vector('vec', 2)));

        self::assertSame(['embedding', 'variants.vec'], Mapping::create($schema, 'elasticsearch')['mappings']['_source']['excludes']);
        self::assertArrayNotHasKey('_source', Mapping::create(IndexSchema::for('products')->text('title'), 'elasticsearch')['mappings']);
    }
}
