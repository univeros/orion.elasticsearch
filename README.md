# orion-search/elasticsearch

Orion's engine adapter for Elasticsearch 8.19 and 9.x, on the official `elasticsearch/elasticsearch` client
(install the client major that matches your server: client 9 cannot talk to an 8.x cluster).

```php
use Elastic\Elasticsearch\ClientBuilder;
use Orion\Elasticsearch\ElasticsearchEngine;
use Orion\Registry\EngineRegistry;

$engines = new EngineRegistry(elasticsearch: new ElasticsearchEngine(ClientBuilder::create()->setHosts([$url])->build()));
```

- Mappings are `dynamic: strict`: a document carrying an undeclared field fails alone instead of growing the mapping.
- Writes and deletes are externally versioned with the outbox offset; a version conflict is a stale write, never a
  failure, so replayed and live writes converge.
- `integer` maps to `long`, `float` to `double`; any other native type inspects as unknown and fails a plan closed.
- `IndexSchema::native('elasticsearch', ['settings' => [...], 'mappings' => [...]])` merges over the generated body.
- `raw()` takes `callable(): array` returning a search body; scope filters are ANDed into it.
- Native hybrid fusion (the RRF retriever) needs a paid license, so `HybridFusion` is reported false and core fuses.

Proven by `Orion\Testing\EngineConformanceTestCase` against Elasticsearch 9.5 and 8.19.
