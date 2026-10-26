<?php

declare(strict_types=1);

namespace Orion\Elasticsearch\Tests;

use PHPUnit\Framework\Attributes\Group;

/**
 * Elasticsearch 8.19 with client 8.19; set ORION_ELASTICSEARCH8_URL. CI runs this group in its own job.
 */
#[Group('elasticsearch8')]
final class Elasticsearch8FaultInjectionTest extends ElasticsearchFaults
{
    protected static function urlVariable(): string
    {
        return 'ORION_ELASTICSEARCH8_URL';
    }
}
