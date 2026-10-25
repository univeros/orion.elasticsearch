<?php

declare(strict_types=1);

namespace Orion\Elasticsearch;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Response\Elasticsearch;
use LogicException;
use Orion\Adapter\Capability;
use Orion\Adapter\PendingTask;
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
use Orion\Write\WriteOutcome;
use Override;
use stdClass;

use function array_key_first;
use function array_map;
use function array_replace_recursive;
use function array_values;
use function count;
use function explode;
use function in_array;
use function is_array;
use function is_string;
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
    private const int CONFLICT = 409;
    private const int NOT_FOUND = 404;

    private readonly QueryCompiler $compiler;

    public function __construct(private readonly Client $client)
    {
        $this->compiler = new QueryCompiler(self::NAME);
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
        if (!$this->exists($index)) {
            return null;
        }
        $response = $this->call($this->client->indices()->getMapping(['index' => $index->value]));
        $concrete = $response[array_key_first($response)] ?? [];
        $mapping = is_array($concrete) && is_array($concrete['mappings'] ?? null) ? $concrete['mappings'] : [];

        return new LiveSchema($index, Mapping::fields($mapping));
    }

    #[Override]
    public function plan(IndexSchema $desired): MigrationPlan
    {
        return MigrationPlan::between($desired, $this->inspect($desired->index));
    }

    #[Override]
    public function apply(MigrationPlan $plan): PendingTask
    {
        if ($plan->requiresReindex()) {
            throw new LogicException(sprintf('The plan for %s has breaking changes; they need a reindex, never an in-place apply.', $plan->index->value));
        }
        $live = $this->inspect($plan->index) ?? throw new LogicException(sprintf('The index %s does not exist; ensure it first.', $plan->index->value));
        $fields = $live->fields;
        $added = [];
        foreach ($plan->additive() as $change) {
            if ($change->kind === ChangeKind::Added && $change->after !== null) {
                $fields = Field::addAt($fields, $change->path, $change->after);
                $added = array_replace_recursive($added, self::nest(explode('.', $change->path), $change->after));
            }
        }
        if ($added !== []) {
            $mappings = Mapping::mappings($fields);
            $this->call($this->client->indices()->putMapping(['index' => $plan->index->value, 'body' => ['_meta' => $mappings['_meta'], 'properties' => $added]]));
        }

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
    public function index(IndexName $index, Document ...$docs): BulkResult
    {
        $body = [];
        foreach ($docs as $doc) {
            $body[] = ['index' => ['_index' => $index->value, '_id' => $doc->id, 'version' => $doc->version, 'version_type' => 'external']];
            $body[] = $doc->fields === [] ? new stdClass() : $doc->fields;
        }

        return $this->bulk($body, count($docs));
    }

    #[Override]
    public function delete(IndexName $index, Tombstone ...$tombstones): BulkResult
    {
        $body = [];
        foreach ($tombstones as $tombstone) {
            $body[] = ['delete' => ['_index' => $index->value, '_id' => $tombstone->id, 'version' => $tombstone->version, 'version_type' => 'external']];
        }

        return $this->bulk($body, count($tombstones));
    }

    #[Override]
    public function flush(IndexName $index, bool $await = false): void
    {
        $this->call($this->client->indices()->refresh(['index' => $index->value]));
    }

    /**
     * @param list<array<string, mixed>|stdClass> $body
     */
    private function bulk(array $body, int $count): BulkResult
    {
        if ($count === 0) {
            return BulkResult::completed();
        }
        $response = $this->call($this->client->bulk(['body' => $body]));
        $outcomes = [];
        foreach (is_array($response['items'] ?? null) ? $response['items'] : [] as $item) {
            $result = is_array($item) ? (array) ($item['index'] ?? $item['delete'] ?? []) : [];
            $outcomes[] = self::outcome($result);
        }

        return BulkResult::completed(...$outcomes);
    }

    /**
     * @param array<array-key, mixed> $result one bulk item
     */
    private static function outcome(array $result): WriteOutcome
    {
        $id = (string) ($result['_id'] ?? '');
        $status = (int) ($result['status'] ?? 0);
        if ($status === self::CONFLICT) {
            return WriteOutcome::stale($id);
        }
        if (isset($result['error']) && $status !== self::NOT_FOUND) {
            $error = is_array($result['error']) ? (string) ($result['error']['type'] ?? '') . ': ' . (string) ($result['error']['reason'] ?? '') : (string) $result['error'];

            return WriteOutcome::failed($id, $error);
        }

        return WriteOutcome::applied($id);
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
                $highlights[(string) $field] = array_values(array_map(static fn($fragment): string => (string) $fragment, (array) $fragments));
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
