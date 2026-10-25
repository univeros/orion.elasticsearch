<?php

declare(strict_types=1);

namespace Orion\Elasticsearch\Tests;

use Orion\Adapter\UnsupportedClause;
use Orion\Elasticsearch\QueryCompiler;
use Orion\Query\Direction;
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
            . '"highlight":{"pre_tags":["<em>"],"post_tags":["<\/em>"],"fields":{"title":{}}}}',
            json_encode((new QueryCompiler())->compile($query)),
        );
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

    public function testTheEscapeHatchIsAndedWithTheScope(): void
    {
        $scoped = (new QueryCompiler())->scope(['query' => ['function_score' => ['query' => ['match' => ['title' => 'x']]]]], [Filter::term('org_id', 'org_1')]);

        self::assertSame(
            ['bool' => ['must' => [['function_score' => ['query' => ['match' => ['title' => 'x']]]]], 'filter' => [['term' => ['org_id' => 'org_1']]]]],
            $scoped['query'],
        );
    }
}
