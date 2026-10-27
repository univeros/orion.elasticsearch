<?php

declare(strict_types=1);

namespace Orion\Elasticsearch\Tests;

use Elastic\Elasticsearch\ClientBuilder;
use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\WriteAdapter;
use Orion\Elasticsearch\ElasticsearchEngine;
use Orion\Testing\EngineConformanceTestCase;
use stdClass;

use function getenv;

/**
 * Runs the engine conformance case against the server at the URL in {@see urlVariable()}; skipped when it is unset.
 */
abstract class ElasticsearchConformance extends EngineConformanceTestCase
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

    protected function compiledAsNative(array $compiled): ?callable
    {
        return static fn(): mixed => $compiled['body'] ?? null;
    }

    protected function nativeShapes(): array
    {
        return [
            'bool with should' => static fn(): array => ['query' => ['bool' => ['should' => [['match' => ['title' => 'running']], ['match_all' => new stdClass()]]]], 'size' => 100],
            'function score' => static fn(): array => ['query' => ['function_score' => ['query' => ['match_all' => new stdClass()], 'random_score' => new stdClass()]], 'size' => 100],
            'knn only' => static fn(): array => ['knn' => ['field' => 'embedding', 'query_vector' => [1.0, 0.0, 0.0], 'k' => 5, 'num_candidates' => 10], 'size' => 100],
            'knn list' => static fn(): array => ['knn' => [['field' => 'embedding', 'query_vector' => [0.0, 1.0, 0.0], 'k' => 5, 'num_candidates' => 10]], 'size' => 100],
            'post filter' => static fn(): array => ['query' => ['match_all' => new stdClass()], 'post_filter' => ['exists' => ['field' => 'sku']], 'size' => 100],
        ];
    }

    protected function nativeScopeEscapes(): array
    {
        return [
            'global aggregation' => static fn(): array => ['query' => ['match_all' => new stdClass()], 'size' => 0, 'aggs' => ['g' => ['global' => new stdClass(), 'aggs' => ['b' => ['terms' => ['field' => 'brand']]]]]],
            'suggester' => static fn(): array => ['suggest' => ['s' => ['text' => 'runing', 'term' => ['field' => 'title']]]],
        ];
    }
}
