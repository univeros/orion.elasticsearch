<?php

declare(strict_types=1);

namespace Orion\Elasticsearch\Tests;

use Elastic\Elasticsearch\ClientBuilder;
use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\WriteAdapter;
use Orion\Elasticsearch\ElasticsearchEngine;
use Orion\Testing\Equivalence\QueryEquivalenceTestCase;

use function getenv;

/**
 * Runs the query equivalence case against the server at the URL in {@see urlVariable()}; skipped when it is unset.
 */
abstract class ElasticsearchEquivalence extends QueryEquivalenceTestCase
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
}
