<?php

declare(strict_types=1);

namespace Orion\Elasticsearch\Tests;

use Orion\Adapter\UnsupportedClause;
use Orion\Elasticsearch\QueryCompiler;
use Orion\Query\Direction;
use Orion\Query\Clause;
use Orion\Query\Filter;
use Orion\Query\Fusion;
use Orion\Query\Query;
use Orion\Query\Sort;
use PHPUnit\Framework\TestCase;
use stdClass;

use function json_encode;

final class QueryCompilerTest extends TestCase
{
    public function testALexicalQueryCompilesToStructuredDsl(): void
    {
        $query = Query::on('products')
            ->match('title', 'running shoe')
            ->filter(Filter::range('price', lte: 100))
            ->filter(Filter::bool(must: [Filter::term('in_stock', true)], should: [Filter::term('brand', 'acme')]))
            ->facet('brand', size: 5)
            ->sort(Sort::SCORE, Direction::Desc)
            ->highlight('title')
            ->paginate(page: 2, size: 20);

        self::assertSame(
            '{"from":20,"size":20,"query":{"bool":{"must":[{"match":{"title":{"query":"running shoe"}}}],"filter":[{"range":{"price":{"lte":100}}},'
            . '{"bool":{"must":[{"term":{"in_stock":true}}],"should":[{"term":{"brand":"acme"}}],"must_not":[],"minimum_should_match":1}}]}},'
            . '"sort":[{"_score":{"order":"desc"}}],"aggs":{"brand":{"terms":{"field":"brand","size":5}}},'
            . '"highlight":{"encoder":"html","pre_tags":["<em>"],"post_tags":["<\/em>"],"fields":{"title":{}}}}',
            json_encode((new QueryCompiler())->compile($query)),
        );
    }

    public function testAMatchOverSeveralWeightedFieldsIsAMultiMatch(): void
    {
        $body = (new QueryCompiler())->compile(Query::on('products')->match(['title' => 3.0, 'description' => 1.0], 'shoe'));

        self::assertSame(['multi_match' => ['query' => 'shoe', 'fields' => ['title^3', 'description'], 'type' => 'best_fields']], $body['query']['bool']['must'][0]);
    }

    public function testAWeightedClauseCarriesItsBoost(): void
    {
        $body = (new QueryCompiler())->compile(Query::on('products')->withClauses([Clause::match('title', 'shoe')->weighted(['title' => 0.25]), Clause::prefix('sku', 'A')->weighted(['sku' => 2.0])]));

        self::assertSame([['match' => ['title' => ['query' => 'shoe', 'boost' => 0.25]]], ['prefix' => ['sku' => ['value' => 'A', 'boost' => 2.0]]]], $body['query']['bool']['must']);
    }

    public function testAVectorQueryPreFiltersTheNeighbours(): void
    {
        $body = (new QueryCompiler())->compile(Query::on('products')->vector('embedding', [0.1, 0.2], k: 5)->filter(Filter::term('brand', 'acme')));

        self::assertSame(['field' => 'embedding', 'query_vector' => [0.1, 0.2], 'k' => 5, 'num_candidates' => 100, 'filter' => [['term' => ['brand' => 'acme']]]], $body['knn']);
        self::assertArrayNotHasKey('query', $body);
    }

    public function testAnEmptyQueryMatchesEverything(): void
    {
        self::assertEquals(['bool' => ['must' => [['match_all' => new stdClass()]]]], (new QueryCompiler())->compile(Query::on('products'))['query']);
    }

    public function testCompilingIsPure(): void
    {
        $query = Query::on('products')->match('title', 'shoe')->filter(Filter::terms('brand', ['a', 'b']));

        self::assertSame(json_encode((new QueryCompiler())->compile($query)), json_encode((new QueryCompiler())->compile($query)));
    }

    public function testHybridRetrievalIsLeftToCore(): void
    {
        $this->expectException(UnsupportedClause::class);

        (new QueryCompiler())->compile(Query::on('products')->match('title', 'x')->vector('embedding', [0.1], k: 2)->fuse(Fusion::reciprocalRank()));
    }

    public function testScopeFiltersEveryKnnEntryAndAddsNoMatchAll(): void
    {
        $scoped = (new QueryCompiler())->scope([
            'knn' => [
                ['field' => 'a', 'query_vector' => [1.0], 'k' => 2, 'num_candidates' => 10, 'filter' => ['term' => ['x' => 1]]],
                ['field' => 'b', 'query_vector' => [1.0], 'k' => 2, 'num_candidates' => 10],
            ],
        ], [Filter::term('org_id', 'org_1')]);

        self::assertArrayNotHasKey('query', $scoped);
        self::assertSame([['term' => ['x' => 1]], ['term' => ['org_id' => 'org_1']]], $scoped['knn'][0]['filter']);
        self::assertSame([['term' => ['org_id' => 'org_1']]], $scoped['knn'][1]['filter']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function escapes(): iterable
    {
        yield 'global aggregation' => [['query' => ['match_all' => new stdClass()], 'aggs' => ['g' => ['global' => new stdClass()]]]];
        yield 'any aggregation' => [['aggs' => ['b' => ['terms' => ['field' => 'brand']]]]];
        yield 'suggester' => [['suggest' => ['s' => ['text' => 'x', 'term' => ['field' => 'title']]]]];
        yield 'retriever' => [['retriever' => ['standard' => ['query' => ['match_all' => new stdClass()]]]]];
        yield 'runtime mappings' => [['runtime_mappings' => ['x' => ['type' => 'keyword']]]];
        yield 'script fields' => [['script_fields' => ['x' => ['script' => 'doc']]]];
        yield 'terms lookup of a foreign document' => [['query' => ['terms' => ['brand' => ['index' => 'products', 'id' => 'globex:p1', 'path' => 'brand']]]]];
        yield 'terms lookup nested in bool' => [['query' => ['bool' => ['filter' => [['terms' => ['brand' => ['index' => 'products', 'id' => 'globex:p1', 'path' => 'brand']]]]]]]];
        yield 'more like a foreign document' => [['query' => ['more_like_this' => ['fields' => ['title'], 'like' => [['_index' => 'products', '_id' => 'globex:p1']]]]]];
        yield 'percolate a foreign document' => [['query' => ['percolate' => ['field' => 'q', 'index' => 'products', 'id' => 'globex:p1']]]];
        yield 'an indexed shape' => [['query' => ['geo_shape' => ['area' => ['indexed_shape' => ['index' => 'shapes', 'id' => 'globex:s1', 'path' => 'area']]]]]];
        yield 'a wrapped query, uninspectable' => [['query' => ['wrapper' => ['query' => 'eyJtYXRjaF9hbGwiOnt9fQ==']]]];
        yield 'a lookup in the k-NN filter' => [['knn' => ['field' => 'v', 'query_vector' => [1.0], 'k' => 1, 'num_candidates' => 1, 'filter' => ['terms' => ['brand' => ['index' => 'products', 'id' => 'globex:p1', 'path' => 'brand']]]]]];
        yield 'a lookup in the rescore query' => [['query' => ['match_all' => new stdClass()], 'rescore' => ['query' => ['rescore_query' => ['more_like_this' => ['like' => [['_id' => 'globex:p1']]]]]]]];
    }

    public function testAScopedBodyMayStillUseOrdinaryTermsAndQueries(): void
    {
        $body = ['query' => ['bool' => ['must' => [['terms' => ['brand' => ['a', 'b']]], ['match' => ['title' => 'index']]]]]];

        $scoped = (new QueryCompiler())->scope($body, [Filter::term('org_id', 'org_1')]);

        self::assertSame($body['query'], $scoped['query']['bool']['must'][0]);
    }

    /**
     * @param array<string, mixed> $body
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('escapes')]
    public function testAScopedNativeBodyMayNotReadOutsideTheScope(array $body): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new QueryCompiler())->scope($body, [Filter::term('org_id', 'org_1')]);
    }

    public function testAnUnscopedNativeBodyIsLeftAlone(): void
    {
        $body = ['suggest' => ['s' => ['text' => 'x', 'term' => ['field' => 'title']]]];

        self::assertSame($body, (new QueryCompiler())->scope($body, []));
    }

    public function testHighlightsAreHtmlEncodedByTheEngine(): void
    {
        self::assertSame('html', (new QueryCompiler())->compile(Query::on('products')->match('title', 'x')->highlight('title'))['highlight']['encoder']);
    }

    public function testTheEscapeHatchIsAndedWithTheScope(): void
    {
        $scoped = (new QueryCompiler())->scope(['query' => ['function_score' => ['query' => ['match' => ['title' => 'x']]]]], [Filter::term('org_id', 'org_1')]);

        self::assertSame(
            ['bool' => ['must' => [['function_score' => ['query' => ['match' => ['title' => 'x']]]]], 'filter' => [['term' => ['org_id' => 'org_1']]]]],
            $scoped['query'],
        );
    }
}
