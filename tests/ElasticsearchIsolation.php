<?php

declare(strict_types=1);

namespace Orion\Elasticsearch\Tests;

use Elastic\Elasticsearch\ClientBuilder;
use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\WriteAdapter;
use Orion\Elasticsearch\ElasticsearchEngine;
use Orion\Testing\TenancyIsolationTestCase;
use stdClass;

use function getenv;

/**
 * Runs the tenancy isolation case against the server at the URL in {@see urlVariable()}; skipped when it is unset.
 */
abstract class ElasticsearchIsolation extends TenancyIsolationTestCase
{
    abstract protected static function urlVariable(): string;

    protected function setUp(): void
    {
        if (getenv(static::urlVariable()) === false) {
            self::markTestSkipped(static::urlVariable() . ' is not set.');
        }
        parent::setUp();
    }

    protected function createEngine(): SearchAdapter&SchemaAdapter&WriteAdapter
    {
        return new ElasticsearchEngine(ClientBuilder::create()->setHosts([(string) getenv(static::urlVariable())])->build());
    }

    protected function nativeMatchAll(): callable
    {
        return static fn(): array => ['query' => ['match_all' => new stdClass()], 'size' => 100];
    }

    protected function nativeShapes(): array
    {
        return [
            'foreign ids' => static fn(): array => ['query' => ['ids' => ['values' => ['globex:p1', 'globex:p4']]], 'size' => 100],
            'the foreign tenant by name' => static fn(): array => ['query' => ['term' => ['org_id' => 'globex']], 'size' => 100],
            'should with match all' => static fn(): array => ['query' => ['bool' => ['should' => [['term' => ['org_id' => 'globex']], ['match_all' => new stdClass()]]]], 'size' => 100],
            'knn at the foreign tenant' => static fn(): array => ['knn' => ['field' => 'embedding', 'query_vector' => [0.0, 1.0, 0.0], 'k' => 10, 'num_candidates' => 100], 'size' => 100],
            'knn list filtered to the foreign tenant' => static fn(): array => ['knn' => [['field' => 'embedding', 'query_vector' => [0.0, 1.0, 0.0], 'k' => 10, 'num_candidates' => 100, 'filter' => ['term' => ['org_id' => 'globex']]]], 'size' => 100],
            'post filter' => static fn(): array => ['query' => ['match_all' => new stdClass()], 'post_filter' => ['term' => ['org_id' => 'globex']], 'size' => 100],
            'collapse' => static fn(): array => ['query' => ['match_all' => new stdClass()], 'collapse' => ['field' => 'brand'], 'size' => 100],
        ];
    }

    protected function nativeScopeEscapes(): array
    {
        return [
            'aggregation' => static fn(): array => ['size' => 0, 'aggs' => ['tenants' => ['terms' => ['field' => 'org_id']]]],
            'global aggregation' => static fn(): array => ['query' => ['match_all' => new stdClass()], 'size' => 0, 'aggs' => ['g' => ['global' => new stdClass(), 'aggs' => ['b' => ['terms' => ['field' => 'brand']]]]]],
            'suggester' => static fn(): array => ['suggest' => ['s' => ['text' => 'globx', 'term' => ['field' => 'title']]]],
            'retriever' => static fn(): array => ['retriever' => ['standard' => ['query' => ['match_all' => new stdClass()]]]],
            'runtime field' => static fn(): array => ['runtime_mappings' => ['x' => ['type' => 'keyword']], 'query' => ['match_all' => new stdClass()]],
        ];
    }
}
