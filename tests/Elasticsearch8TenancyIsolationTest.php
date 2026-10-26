<?php

declare(strict_types=1);

namespace Orion\Elasticsearch\Tests;

use PHPUnit\Framework\Attributes\Group;

/**
 * Elasticsearch 8.19 with client 8.19 (client 9 cannot talk to an 8.x server); set ORION_ELASTICSEARCH8_URL. CI runs
 * this group in its own job, with the client pinned to ^8.19.
 */
#[Group('elasticsearch8')]
final class Elasticsearch8TenancyIsolationTest extends ElasticsearchIsolation
{
    protected static function urlVariable(): string
    {
        return 'ORION_ELASTICSEARCH8_URL';
    }
}
