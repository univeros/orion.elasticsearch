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
}
