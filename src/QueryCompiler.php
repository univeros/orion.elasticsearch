<?php

declare(strict_types=1);

namespace Orion\Elasticsearch;

use InvalidArgumentException;
use LogicException;
use Orion\Adapter\Capability;
use Orion\Adapter\UnsupportedClause;
use Orion\Query\BoolFilter;
use Orion\Query\Clause;
use Orion\Query\ClauseKind;
use Orion\Query\ExistsFilter;
use Orion\Query\Facet;
use Orion\Query\Filter;
use Orion\Query\Query;
use Orion\Query\RangeFilter;
use Orion\Query\Sort;
use Orion\Query\TermFilter;
use Orion\Query\TermsFilter;
use Orion\Support\Assert;
use stdClass;

use function array_filter;
use function array_is_list;
use function array_map;
use function array_values;
use function implode;
use function in_array;
use function is_array;
use function max;
use function min;
use function sprintf;

/**
 * The neutral query AST compiled to an Elasticsearch search body: a pure function, so the same query always
 * compiles to the same DSL (and `compile_query` can show it without executing). Values are placed in structured
 * queries only, never in a query string.
 */
final class QueryCompiler
{
    /** Top-level search keys that only narrow, page, sort or present the scoped documents. */
    private const array SCOPED_KEYS = [
        'query', 'knn', 'size', 'from', 'sort', '_source', 'highlight', 'post_filter', 'track_total_hits',
        'min_score', 'search_after', 'rescore', 'collapse', 'timeout',
    ];

    /** Candidates per k-NN shard: ten times k, within Elasticsearch's bounds. */
    private const int CANDIDATE_FACTOR = 10;
    private const int MIN_CANDIDATES = 100;
    private const int MAX_CANDIDATES = 10000;

    public function __construct(private readonly string $engine = ElasticsearchEngine::NAME)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function compile(Query $query): array
    {
        if ($query->vector !== null && $query->clauses !== []) {
            throw UnsupportedClause::missing(Capability::HybridFusion, 'hybrid retrieval', $this->engine);
        }
        $filters = array_map($this->filter(...), $query->filters);
        $body = [
            'from' => ($query->page->page - 1) * $query->page->size,
            'size' => $query->page->size,
        ];
        if ($query->vector !== null) {
            $k = $query->vector->k;
            $body['knn'] = [
                'field' => $query->vector->field,
                'query_vector' => $query->vector->vector,
                'k' => $k,
                'num_candidates' => min(self::MAX_CANDIDATES, max(self::MIN_CANDIDATES, $k * self::CANDIDATE_FACTOR)),
                ...($filters === [] ? [] : ['filter' => $filters]),
            ];
        } else {
            $body['query'] = ['bool' => array_filter([
                'must' => array_map($this->clause(...), $query->clauses),
                'filter' => $filters,
            ], static fn(array $part): bool => $part !== []) ?: ['must' => [['match_all' => new stdClass()]]]];
        }
        if ($query->sorts !== []) {
            $body['sort'] = array_map(static fn(Sort $sort): array => [$sort->field => ['order' => $sort->direction->value]], $query->sorts);
        }
        if ($query->facets !== []) {
            $aggregations = [];
            foreach ($query->facets as $facet) {
                $aggregations[$facet->field] = self::facet($facet);
            }
            $body['aggs'] = $aggregations;
        }
        if ($query->highlights !== []) {
            $fields = [];
            foreach ($query->highlights as $field) {
                $fields[$field] = new stdClass();
            }
            // The html encoder escapes the stored text, so a fragment's only markup is the <em> around matches.
            $body['highlight'] = ['encoder' => 'html', 'pre_tags' => ['<em>'], 'post_tags' => ['</em>'], 'fields' => $fields];
        }

        return $body;
    }

    /**
     * A native body restricted by neutral filters, for the scoped escape hatch. Under a scope only keys that act
     * within the matched documents are accepted; anything that reads index-wide (aggregations, suggesters,
     * retrievers, runtime fields, scripts) is refused, because the scope is what keeps one tenant from another.
     * The filters are ANDed with the query (when there is one) and with every k-NN entry.
     *
     * @param array<array-key, mixed> $body
     * @param array<Filter> $scope
     * @return array<array-key, mixed>
     */
    public function scope(array $body, array $scope): array
    {
        if ($scope === []) {
            return $body;
        }
        foreach ($body as $key => $_) {
            if (!in_array($key, self::SCOPED_KEYS, true)) {
                throw new InvalidArgumentException(sprintf('A scoped native query cannot use %s: it reads outside the scope. Allowed: %s.', Assert::show((string) $key), implode(', ', self::SCOPED_KEYS)));
            }
        }
        $filters = array_map($this->filter(...), array_values($scope));
        if (isset($body['query']) || !isset($body['knn'])) {
            $body['query'] = ['bool' => ['must' => [$body['query'] ?? ['match_all' => new stdClass()]], 'filter' => $filters]];
        }
        if (isset($body['knn'])) {
            $entries = is_array($body['knn']) && array_is_list($body['knn']) ? $body['knn'] : [$body['knn']];
            $body['knn'] = array_map(static function (mixed $entry) use ($filters): array {
                $entry = (array) $entry;
                $existing = $entry['filter'] ?? [];
                $existing = is_array($existing) && array_is_list($existing) ? $existing : [$existing];
                $entry['filter'] = [...$existing, ...$filters];

                return $entry;
            }, $entries);
        }

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    public function filter(Filter $filter): array
    {
        return match (true) {
            $filter instanceof TermFilter => ['term' => [$filter->field => $filter->value]],
            $filter instanceof TermsFilter => ['terms' => [$filter->field => $filter->values]],
            $filter instanceof RangeFilter => ['range' => [$filter->field => array_filter(
                ['gt' => $filter->gt, 'gte' => $filter->gte, 'lt' => $filter->lt, 'lte' => $filter->lte],
                static fn($bound): bool => $bound !== null,
            )]],
            $filter instanceof ExistsFilter => ['exists' => ['field' => $filter->field]],
            $filter instanceof BoolFilter => ['bool' => [
                'must' => array_map($this->filter(...), $filter->must),
                'should' => array_map($this->filter(...), $filter->should),
                'must_not' => array_map($this->filter(...), $filter->mustNot),
                // In filter context Elasticsearch ignores `should` beside `must` unless told otherwise.
                ...($filter->should === [] ? [] : ['minimum_should_match' => 1]),
            ]],
            default => throw new LogicException('Unknown filter ' . $filter::class),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function clause(Clause $clause): array
    {
        return match ($clause->kind) {
            ClauseKind::Match => ['match' => [$clause->field => ['query' => $clause->value]]],
            ClauseKind::Term => ['term' => [$clause->field => ['value' => $clause->value]]],
            ClauseKind::Prefix => ['prefix' => [$clause->field => ['value' => $clause->value]]],
            ClauseKind::Phrase => ['match_phrase' => [$clause->field => ['query' => $clause->value]]],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function facet(Facet $facet): array
    {
        return ['terms' => ['field' => $facet->field, 'size' => $facet->size]];
    }
}
