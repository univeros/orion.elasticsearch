<?php

declare(strict_types=1);

namespace Orion\Elasticsearch\Tests;

/**
 * Elasticsearch 9; set ORION_ELASTICSEARCH_URL.
 */
final class ElasticsearchQueryEquivalenceTest extends ElasticsearchEquivalence
{
    protected static function urlVariable(): string
    {
        return 'ORION_ELASTICSEARCH_URL';
    }
}
