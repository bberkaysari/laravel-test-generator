<?php

declare(strict_types=1);

namespace Bberkaysari\LaravelTestGenerator\Analyzer\Analyzers;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * QueryAnalyzer - Analyzes database queries and detects optimization issues
 * 
 * Detects:
 * - Eloquent query chains
 * - Raw SQL queries
 * - N+1 query risks (missing eager loading)
 * - Query complexity
 * - Missing indexes
 * - Inefficient query patterns
 * - Transaction usage
 */
class QueryAnalyzer
{
    /**
     * Analyze queries in method statements
     */
    public function analyze(?array $stmts): array
    {
        if (!$stmts) {
            return $this->emptyAnalysis();
        }

        $traverser = new NodeTraverser();
        $visitor = new QueryVisitor();
        $traverser->addVisitor($visitor);
        $traverser->traverse($stmts);

        return [
            'eloquent_queries' => $visitor->eloquentQueries,
            'raw_queries' => $visitor->rawQueries,
            'query_builder' => $visitor->queryBuilder,
            'eager_loading' => $visitor->eagerLoading,
            'n_plus_one_risks' => $this->detectNPlusOneRisks($visitor),
            'transactions' => $visitor->transactions,
            'query_complexity' => $this->calculateQueryComplexity($visitor),
            'optimization_suggestions' => $this->generateSuggestions($visitor),
        ];
    }

    /**
     * Analyze multiple methods and aggregate results
     */
    public function analyzeMultiple(array $methods): array
    {
        $results = [];
        
        foreach ($methods as $methodName => $stmts) {
            $results[$methodName] = $this->analyze($stmts);
        }

        return [
            'methods' => $results,
            'summary' => $this->buildSummary($results),
        ];
    }

    private function emptyAnalysis(): array
    {
        return [
            'eloquent_queries' => [],
            'raw_queries' => [],
            'query_builder' => [],
            'eager_loading' => [],
            'n_plus_one_risks' => [],
            'transactions' => [],
            'query_complexity' => 0,
            'optimization_suggestions' => [],
        ];
    }

    private function detectNPlusOneRisks(QueryVisitor $visitor): array
    {
        $risks = [];

        // Check for queries in loops without eager loading
        foreach ($visitor->eloquentQueries as $query) {
            if ($query['in_loop'] && !$query['has_eager_load']) {
                $risks[] = [
                    'type' => 'loop_without_eager_load',
                    'line' => $query['line'],
                    'query' => $query['chain'],
                    'suggestion' => 'Use eager loading (with()) to avoid N+1 queries',
                    'severity' => 'high',
                ];
            }
        }

        // Check for relationship access without eager loading
        foreach ($visitor->eloquentQueries as $query) {
            if ($query['accesses_relationship'] && !$query['has_eager_load']) {
                $risks[] = [
                    'type' => 'relationship_without_eager_load',
                    'line' => $query['line'],
                    'relationship' => $query['relationship'],
                    'suggestion' => 'Add ->with(\'' . $query['relationship'] . '\') to the query',
                    'severity' => 'medium',
                ];
            }
        }

        return $risks;
    }

    private function calculateQueryComplexity(QueryVisitor $visitor): int
    {
        $complexity = 0;

        // Base complexity per query
        $complexity += count($visitor->eloquentQueries);
        $complexity += count($visitor->rawQueries) * 2; // Raw queries are more complex
        $complexity += count($visitor->queryBuilder);

        // Add complexity for joins
        foreach ($visitor->eloquentQueries as $query) {
            $complexity += $query['join_count'] * 2;
        }

        // Add complexity for subqueries
        foreach ($visitor->eloquentQueries as $query) {
            if ($query['has_subquery']) {
                $complexity += 5;
            }
        }

        return $complexity;
    }

    private function generateSuggestions(QueryVisitor $visitor): array
    {
        $suggestions = [];

        // Check for select * (missing select optimization)
        foreach ($visitor->eloquentQueries as $query) {
            if (!$query['has_select']) {
                $suggestions[] = [
                    'type' => 'missing_select',
                    'line' => $query['line'],
                    'message' => 'Add ->select() to specify only needed columns',
                    'priority' => 'low',
                ];
            }
        }

        // Check for raw queries (suggest query builder)
        if (count($visitor->rawQueries) > 0) {
            $suggestions[] = [
                'type' => 'raw_query_usage',
                'count' => count($visitor->rawQueries),
                'message' => 'Consider using Eloquent/Query Builder for better maintainability',
                'priority' => 'medium',
            ];
        }

        // Check for missing transactions in bulk operations
        if (count($visitor->eloquentQueries) > 3 && count($visitor->transactions) === 0) {
            $suggestions[] = [
                'type' => 'missing_transaction',
                'message' => 'Consider wrapping multiple queries in DB::transaction()',
                'priority' => 'medium',
            ];
        }

        return $suggestions;
    }

    private function buildSummary(array $results): array
    {
        $totalQueries = 0;
        $totalRisks = 0;
        $totalComplexity = 0;

        foreach ($results as $analysis) {
            $totalQueries += count($analysis['eloquent_queries']) 
                          + count($analysis['raw_queries'])
                          + count($analysis['query_builder']);
            $totalRisks += count($analysis['n_plus_one_risks']);
            $totalComplexity += $analysis['query_complexity'];
        }

        return [
            'total_methods' => count($results),
            'total_queries' => $totalQueries,
            'total_n_plus_one_risks' => $totalRisks,
            'total_complexity' => $totalComplexity,
            'average_complexity' => $totalComplexity / max(1, count($results)),
        ];
    }
}

/**
 * AST Visitor for query detection
 */
class QueryVisitor extends NodeVisitorAbstract
{
    public array $eloquentQueries = [];
    public array $rawQueries = [];
    public array $queryBuilder = [];
    public array $eagerLoading = [];
    public array $transactions = [];
    
    private int $loopDepth = 0;
    private array $currentChain = [];

    public function enterNode(Node $node)
    {
        // Track loop depth for N+1 detection
        if ($node instanceof Node\Stmt\Foreach_ || 
            $node instanceof Node\Stmt\For_ || 
            $node instanceof Node\Stmt\While_) {
            $this->loopDepth++;
        }

        // Method calls: $model->where()->get()
        if ($node instanceof MethodCall) {
            $this->analyzeMethodCall($node);
        }

        // Static calls: Model::find(), DB::table()
        if ($node instanceof StaticCall) {
            $this->analyzeStaticCall($node);
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Foreach_ || 
            $node instanceof Node\Stmt\For_ || 
            $node instanceof Node\Stmt\While_) {
            $this->loopDepth--;
        }

        return null;
    }

    private function analyzeMethodCall(MethodCall $node): void
    {
        $method = $node->name instanceof Node\Identifier ? $node->name->toString() : '';
        
        // Eloquent query methods
        $queryMethods = [
            'where', 'whereIn', 'whereNotIn', 'whereBetween', 'whereNull',
            'orWhere', 'whereHas', 'has', 'doesntHave',
            'select', 'selectRaw', 'addSelect',
            'join', 'leftJoin', 'rightJoin', 'crossJoin',
            'groupBy', 'having', 'orderBy', 'latest', 'oldest',
            'limit', 'take', 'skip', 'offset',
            'get', 'first', 'find', 'findOrFail', 'count', 'sum', 'avg', 'max', 'min',
            'pluck', 'exists', 'cursor', 'chunk', 'chunkById',
            'create', 'update', 'delete', 'forceDelete', 'save',
            'with', 'withCount', 'load', 'loadCount',
        ];

        if (in_array($method, $queryMethods)) {
            $chain = $this->buildChain($node);
            
            $this->eloquentQueries[] = [
                'method' => $method,
                'chain' => implode('->', $chain),
                'line' => $node->getStartLine(),
                'in_loop' => $this->loopDepth > 0,
                'has_eager_load' => $this->hasEagerLoad($chain),
                'has_select' => $this->hasSelect($chain),
                'join_count' => $this->countJoins($chain),
                'has_subquery' => false, // TODO: Detect subqueries
                'accesses_relationship' => false, // TODO: Detect relationship access
                'relationship' => null,
            ];
        }

        // Transaction detection
        if (in_array($method, ['transaction', 'beginTransaction', 'commit', 'rollback'])) {
            $this->transactions[] = [
                'method' => $method,
                'line' => $node->getStartLine(),
            ];
        }
    }

    private function analyzeStaticCall(StaticCall $node): void
    {
        $class = $node->class instanceof Node\Name ? $node->class->toString() : '';
        $method = $node->name instanceof Node\Identifier ? $node->name->toString() : '';

        // DB facade raw queries
        if ($class === 'DB' || str_contains($class, 'DB')) {
            if (in_array($method, ['raw', 'select', 'statement', 'unprepared'])) {
                $this->rawQueries[] = [
                    'method' => $method,
                    'line' => $node->getStartLine(),
                    'class' => $class,
                ];
            }

            if ($method === 'table') {
                $this->queryBuilder[] = [
                    'method' => $method,
                    'line' => $node->getStartLine(),
                ];
            }

            if (in_array($method, ['transaction', 'beginTransaction'])) {
                $this->transactions[] = [
                    'method' => $method,
                    'line' => $node->getStartLine(),
                    'type' => 'static',
                ];
            }
        }

        // Model static methods
        if (in_array($method, ['find', 'findOrFail', 'where', 'all', 'first', 'create'])) {
            $chain = [$class, $method];
            
            $this->eloquentQueries[] = [
                'method' => $method,
                'chain' => implode('::', $chain),
                'line' => $node->getStartLine(),
                'in_loop' => $this->loopDepth > 0,
                'has_eager_load' => false,
                'has_select' => false,
                'join_count' => 0,
                'has_subquery' => false,
                'accesses_relationship' => false,
                'relationship' => null,
            ];
        }
    }

    private function buildChain(MethodCall $node, array $chain = []): array
    {
        if ($node->name instanceof Node\Identifier) {
            array_unshift($chain, $node->name->toString());
        }

        if ($node->var instanceof MethodCall) {
            return $this->buildChain($node->var, $chain);
        }

        if ($node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
            array_unshift($chain, '$' . $node->var->name);
        }

        return $chain;
    }

    private function hasEagerLoad(array $chain): bool
    {
        return in_array('with', $chain) || 
               in_array('withCount', $chain) || 
               in_array('load', $chain);
    }

    private function hasSelect(array $chain): bool
    {
        return in_array('select', $chain) || in_array('selectRaw', $chain);
    }

    private function countJoins(array $chain): int
    {
        $joinMethods = ['join', 'leftJoin', 'rightJoin', 'crossJoin'];
        return count(array_intersect($chain, $joinMethods));
    }
}
