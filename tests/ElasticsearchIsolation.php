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

use function base64_encode;
use function getenv;
use function json_decode;

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
            'knn list filtered to the foreign tenant' => static fn(): array => ['knn' => [['field' => 'embedding', 'query_vector' => [0.0, 1.0, 0.0], 'k' => 10, 'num_candidates' => 100, 'filter' => ['term' => ['org_id' => 'globex']]]], 'size' => 100],
            'post filter' => static fn(): array => ['query' => ['match_all' => new stdClass()], 'post_filter' => ['term' => ['org_id' => 'globex']], 'size' => 100],
            'collapse' => static fn(): array => ['query' => ['match_all' => new stdClass()], 'collapse' => ['field' => 'brand'], 'size' => 100],
        ];
    }

    /**
     * k-NN aimed at the foreign tenant's nearest vector, one per placement: the top-level entry and, since 8.12, the
     * `knn` query clause wherever a query may sit.
     */
    protected function nativeProbes(): array
    {
        $knn = ['knn' => ['field' => 'embedding', 'query_vector' => [0.0, 1.0, 0.0], 'k' => 1, 'num_candidates' => 10]];

        return [
            'knn at the foreign tenant' => static fn(): array => [...$knn, 'size' => 100],
            'knn query at the foreign tenant' => static fn(): array => ['query' => $knn, 'size' => 100],
            'knn query at the foreign tenant in a bool' => static fn(): array => ['query' => ['bool' => ['must' => [$knn]]], 'size' => 100],
            'knn query at the foreign tenant in a post filter' => static fn(): array => ['query' => ['match_all' => new stdClass()], 'post_filter' => $knn, 'size' => 100],
            'knn query at the foreign tenant in a rescore' => static fn(): array => ['query' => ['match_all' => new stdClass()], 'rescore' => ['window_size' => 10, 'query' => ['rescore_query' => $knn, 'query_weight' => 0]], 'size' => 100],
            'knn query at the foreign tenant inside a knn filter' => static fn(): array => ['knn' => ['field' => 'embedding', 'query_vector' => [0.0, 1.0, 0.0], 'k' => 10, 'num_candidates' => 100, 'filter' => $knn], 'size' => 100],
            'knn query at the foreign tenant as decoded JSON' => static fn(): array => ['query' => json_decode('{"knn":{"field":"embedding","query_vector":[0.0,1.0,0.0],"k":1,"num_candidates":10}}'), 'size' => 100],
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
            'terms lookup of a foreign document' => fn(): array => ['query' => ['terms' => ['brand' => ['index' => $this->indexName(), 'id' => 'globex:p1', 'path' => 'brand']]]],
            'more like a foreign document' => fn(): array => ['query' => ['more_like_this' => ['fields' => ['title'], 'like' => [['_index' => $this->indexName(), '_id' => 'globex:p1']], 'min_term_freq' => 1, 'min_doc_freq' => 1]]],
            'a wrapped query' => static fn(): array => ['query' => ['wrapper' => ['query' => base64_encode('{"match_all":{}}')]]],
        ];
    }
}
