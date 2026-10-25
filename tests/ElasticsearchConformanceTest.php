<?php

declare(strict_types=1);

namespace Orion\Elasticsearch\Tests;

/**
 * Elasticsearch 9; set ORION_ELASTICSEARCH_URL, e.g. `http://127.0.0.1:9200`.
 */
final class ElasticsearchConformanceTest extends ElasticsearchConformance
{
    protected static function urlVariable(): string
    {
        return 'ORION_ELASTICSEARCH_URL';
    }
}
