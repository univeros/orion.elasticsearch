<?php

declare(strict_types=1);

namespace Orion\Elasticsearch\Tests;

use PHPUnit\Framework\Attributes\Group;

/**
 * Elasticsearch 8.19 with client 8.19; set ORION_ELASTICSEARCH8_URL.
 */
#[Group('elasticsearch8')]
final class Elasticsearch8QueryEquivalenceTest extends ElasticsearchEquivalence
{
    protected static function urlVariable(): string
    {
        return 'ORION_ELASTICSEARCH8_URL';
    }
}
