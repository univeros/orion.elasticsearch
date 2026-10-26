<?php

declare(strict_types=1);

namespace Orion\Elasticsearch;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Elastic\Elasticsearch\Response\Elasticsearch;
use InvalidArgumentException;
use LogicException;
use Orion\Adapter\Capability;
use Orion\Adapter\PendingTask;
use Orion\Adapter\RequiresReindex;
use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\WriteAdapter;
use Orion\IndexName;
use Orion\Query\Filter;
use Orion\Query\Hit;
use Orion\Query\Query;
use Orion\Query\ResultSet;
use Orion\Schema\ChangeKind;
use Orion\Schema\Field;
use Orion\Schema\IndexSchema;
use Orion\Schema\LiveSchema;
use Orion\Schema\MigrationPlan;
use Orion\Write\BulkResult;
use Orion\Write\Document;
use Orion\Write\Tombstone;
use Override;
use stdClass;

use function array_diff;
use function array_fill;
use function array_key_first;
use function array_keys;
use function array_map;
use function array_replace_recursive;
use function array_values;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_scalar;
use function sprintf;

/**
 * The Elasticsearch adapter (8.19 and 9.x) on the official client. Mappings are strict, so a document with an
 * undeclared field fails alone; writes and deletes use external versioning with the outbox offset, so a version
 * conflict is a stale write, never a failure. `raw()` takes `callable(): array` returning a search body.
 * Native hybrid fusion (the RRF retriever) needs a paid license, so it is reported false and core fuses instead.
 */
final class ElasticsearchEngine implements SearchAdapter, SchemaAdapter, WriteAdapter
{
    public const string NAME = 'elasticsearch';
    private const array CAPABILITIES = [
        Capability::Vectors, Capability::NestedDocuments, Capability::Facets, Capability::Highlighting,
        Capability::NativeAliases, Capability::CursorPagination, Capability::FunctionScore, Capability::OptimisticConcurrency,
    ];

    private readonly QueryCompiler $compiler;

    public function __construct(private readonly Client $client)
    {
        $this->compiler = new QueryCompiler(self::NAME);
    }

    /**
     * An engine on the official client for these hosts (`http://host:9200`), for hosts that configure Orion from the
     * environment rather than building the client themselves.
     */
    public static function connect(string ...$hosts): self
    {
        if ($hosts === []) {
            throw new InvalidArgumentException('Elasticsearch needs at least one host URL.');
        }

        return new self(ClientBuilder::create()->setHosts(array_values($hosts))->build());
    }

    #[Override]
    public function supports(Capability $capability): bool
    {
        return in_array($capability, self::CAPABILITIES, true);
    }

    #[Override]
    public function search(IndexName $index, Query $query): ResultSet
    {
        return $this->results($this->call($this->client->search(['index' => $index->value, 'body' => $this->compiler->compile($query)])));
    }

    #[Override]
    public function raw(IndexName $index, callable $native, Filter ...$scope): ResultSet
    {
        $body = $native();
        if (!is_array($body)) {
            throw new LogicException('An Elasticsearch native query is a callable returning a search body array.');
        }

        return $this->results($this->call($this->client->search(['index' => $index->value, 'body' => $this->compiler->scope($body, $scope)])));
    }

    #[Override]
    public function ensure(IndexSchema $schema): void
    {
        if ($this->exists($schema->index)) {
            return;
        }
        $this->call($this->client->indices()->create(['index' => $schema->index->value, 'body' => Mapping::create($schema, self::NAME)]));
    }

    #[Override]
    public function inspect(IndexName $index): ?LiveSchema
    {
        $mapping = $this->mapping($index);

        return $mapping === null ? null : new LiveSchema($index, Mapping::fields($mapping));
    }

    #[Override]
    public function plan(IndexSchema $desired): MigrationPlan
    {
        return MigrationPlan::between($desired, $this->inspect($desired->index));
    }

    /**
     * Adds the plan's new fields with one mapping update. Analysis cannot be added to a live index, so a new field
     * needing an analyzer the index lacks is refused like a breaking change: it needs a reindex.
     */
    #[Override]
    public function apply(MigrationPlan $plan): PendingTask
    {
        $mapping = $this->mapping($plan->index) ?? throw new LogicException(sprintf('The index %s does not exist; ensure it first.', $plan->index->value));
        $fields = $plan->applyTo(Mapping::fields($mapping));
        $added = [];
        $addedFields = [];
        foreach ($plan->additive() as $change) {
            if ($change->kind === ChangeKind::Added && $change->after instanceof Field) {
                $added = array_replace_recursive($added, self::nest(explode('.', $change->path), $change->after));
                $addedFields[] = $change->after;
            }
        }
        if ($added === []) {
            return PendingTask::completed();
        }
        $missing = array_diff(array_keys(Mapping::analysis($addedFields)[0]), $this->analyzers($plan->index));
        if ($missing !== []) {
            throw RequiresReindex::for($plan->index, sprintf('it adds fields needing analysis the live index lacks (%s).', implode(', ', $missing)));
        }
        // Only Orion's key of _meta is rewritten; anything else a host keeps there survives.
        $meta = is_array($mapping['_meta'] ?? null) ? $mapping['_meta'] : [];
        $meta['orion'] = Mapping::mappings($fields)['_meta']['orion'];
        $this->call($this->client->indices()->putMapping(['index' => $plan->index->value, 'body' => ['_meta' => $meta, 'properties' => $added]]));

        return PendingTask::completed();
    }

    #[Override]
    public function drop(IndexName $index): void
    {
        if ($this->exists($index)) {
            $this->call($this->client->indices()->delete(['index' => $index->value]));
        }
    }

    #[Override]
    public function alias(IndexName $alias, IndexName $physical): void
    {
        // One request, applied atomically: off whatever it pointed at (nothing, the first time), onto the new index.
        $this->call($this->client->indices()->updateAliases(['body' => ['actions' => [
            ['remove' => ['index' => '*', 'alias' => $alias->value, 'must_exist' => false]],
            ['add' => ['index' => $physical->value, 'alias' => $alias->value]],
        ]]]));
    }

    #[Override]
    public function resolve(IndexName $alias): ?IndexName
    {
        $exists = $this->client->indices()->existsAlias(['name' => $alias->value]);
        if (!$exists instanceof Elasticsearch) {
            throw new LogicException('The Elasticsearch client must run synchronously.');
        }
        if (!$exists->asBool()) {
            return null;
        }
        $indices = array_keys($this->call($this->client->indices()->getAlias(['name' => $alias->value])));
        if (count($indices) !== 1) {
            throw new LogicException(sprintf('The alias %s points at %d indices; Orion keeps exactly one behind an alias.', $alias->value, count($indices)));
        }

        return IndexName::of((string) $indices[0]);
    }

    #[Override]
    public function index(IndexName $index, Document ...$docs): BulkResult
    {
        $body = [];
        foreach ($docs as $doc) {
            $body[] = ['index' => ['_index' => $index->value, '_id' => $doc->id, 'version' => $doc->version, 'version_type' => 'external']];
            $body[] = $doc->fields === [] ? new stdClass() : $doc->fields;
        }

        return $this->bulk($index, $body, array_values(array_map(static fn(Document $doc): string => $doc->id, $docs)), 'index');
    }

    #[Override]
    public function delete(IndexName $index, Tombstone ...$tombstones): BulkResult
    {
        $body = [];
        foreach ($tombstones as $tombstone) {
            $body[] = ['delete' => ['_index' => $index->value, '_id' => $tombstone->id, 'version' => $tombstone->version, 'version_type' => 'external']];
        }

        return $this->bulk($index, $body, array_values(array_map(static fn(Tombstone $tombstone): string => $tombstone->id, $tombstones)), 'delete');
    }

    #[Override]
    public function flush(IndexName $index, bool $await = false): void
    {
        $this->call($this->client->indices()->refresh(['index' => $index->value]));
    }

    /**
     * Bulk writes would auto-create a missing index with a dynamic mapping, so the index must exist first.
     *
     * @param list<array<string, mixed>|stdClass> $body
     * @param list<string> $ids
     * @param 'index'|'delete' $operation
     */
    private function bulk(IndexName $index, array $body, array $ids, string $operation): BulkResult
    {
        if (!$this->exists($index)) {
            throw new InvalidArgumentException(sprintf('The index %s does not exist; ensure it first.', $index->value));
        }
        if ($ids === []) {
            return BulkResult::completed();
        }
        $response = $this->call($this->client->bulk(['body' => $body]));

        return BulkResult::completed(...BulkResponse::outcomes($response, $ids, array_fill(0, count($ids), $operation)));
    }

    /**
     * @param array<string, mixed> $response
     */
    private function results(array $response): ResultSet
    {
        $hits = [];
        foreach ((array) ($response['hits']['hits'] ?? []) as $hit) {
            if (!is_array($hit)) {
                continue;
            }
            $highlights = [];
            foreach ((array) ($hit['highlight'] ?? []) as $field => $fragments) {
                $highlights[(string) $field] = array_values(array_map(static fn($fragment): string => is_scalar($fragment) ? (string) $fragment : '', (array) $fragments));
            }
            $hits[] = new Hit((string) ($hit['_id'] ?? ''), (float) ($hit['_score'] ?? 0.0), (array) ($hit['_source'] ?? []), $highlights);
        }
        $total = (array) ($response['hits']['total'] ?? []);
        $facets = [];
        foreach ((array) ($response['aggregations'] ?? []) as $field => $aggregation) {
            $buckets = [];
            $aggregation = (array) $aggregation;
            foreach ((array) ($aggregation['buckets'] ?? []) as $bucket) {
                $bucket = (array) $bucket;
                $buckets[(string) ($bucket['key_as_string'] ?? $bucket['key'] ?? '')] = (int) ($bucket['doc_count'] ?? 0);
            }
            $facets[(string) $field] = $buckets;
        }

        return new ResultSet($hits, (int) ($total['value'] ?? count($hits)), ($total['relation'] ?? 'eq') !== 'eq', $facets, (float) ($response['took'] ?? 0));
    }

    /**
     * @return array<string, mixed>|null the index's `mappings`, or null when it does not exist
     */
    private function mapping(IndexName $index): ?array
    {
        if (!$this->exists($index)) {
            return null;
        }
        $response = $this->call($this->client->indices()->getMapping(['index' => $index->value]));
        $concrete = $response[array_key_first($response)] ?? [];

        /** @var array<string, mixed> */
        return is_array($concrete) && is_array($concrete['mappings'] ?? null) ? $concrete['mappings'] : [];
    }

    /**
     * @return list<string> the custom analyzers defined on the index
     */
    private function analyzers(IndexName $index): array
    {
        $response = $this->call($this->client->indices()->getSettings(['index' => $index->value]));
        $concrete = (array) ($response[array_key_first($response)] ?? []);
        $settings = (array) ($concrete['settings'] ?? []);
        $index = (array) ($settings['index'] ?? []);
        $analysis = (array) ($index['analysis'] ?? []);

        return array_map(static fn(int|string $name): string => (string) $name, array_keys((array) ($analysis['analyzer'] ?? [])));
    }

    private function exists(IndexName $index): bool
    {
        $response = $this->client->indices()->exists(['index' => $index->value]);
        if (!$response instanceof Elasticsearch) {
            throw new LogicException('The Elasticsearch client must run synchronously.');
        }

        return $response->asBool();
    }

    /**
     * @return array<string, mixed>
     */
    private function call(mixed $response): array
    {
        if (!$response instanceof Elasticsearch) {
            throw new LogicException('The Elasticsearch client must run synchronously.');
        }
        /** @var array<string, mixed> */
        return $response->asArray();
    }

    /**
     * @param list<string> $path
     * @return array<string, mixed> the property, nested under its parents' `properties`
     */
    private static function nest(array $path, Field $field): array
    {
        $property = Mapping::properties([$field]);
        for ($i = count($path) - 2; $i >= 0; --$i) {
            $property = [$path[$i] => ['properties' => $property]];
        }

        return $property;
    }
}
