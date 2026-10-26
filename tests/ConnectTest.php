<?php

declare(strict_types=1);

namespace Orion\Elasticsearch\Tests;

use InvalidArgumentException;
use Orion\Adapter\Capability;
use Orion\Elasticsearch\ElasticsearchEngine;
use PHPUnit\Framework\TestCase;

final class ConnectTest extends TestCase
{
    public function testAnEngineConnectsToTheHostsItIsGiven(): void
    {
        $engine = ElasticsearchEngine::connect('http://127.0.0.1:59200');

        self::assertTrue($engine->supports(Capability::NativeAliases));
    }

    public function testConnectingNeedsAHost(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ElasticsearchEngine::connect();
    }
}
