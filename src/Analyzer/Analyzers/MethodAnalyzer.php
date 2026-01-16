<?php

declare(strict_types=1);

namespace Bberkaysari\LaravelTestGenerator\Analyzer\Analyzers;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\While_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * MethodAnalyzer - Deep analysis of method bodies
 * 
 * Analyzes:
 * - Method calls (chained and nested)
 * - Static calls
 * - Function calls
 * - Control flow (if/else, loops, switch)
 * - Database queries (DB::, Model::, QueryBuilder)
 * - Event dispatches (event(), Event::dispatch)
 * - Job dispatches (dispatch(), Job::dispatch)
 * - Exception handling (try/catch)
 * - Cyclomatic complexity
 */
class MethodAnalyzer
{
    /**
     * Analyze a method's AST statements
     */
    public function analyze(?array $stmts): array
    {
        if (!$stmts) {
            return $this->emptyAnalysis();
        }

        $traverser = new NodeTraverser();
        $visitor = new MethodVisitor();
        $traverser->addVisitor($visitor);
        $traverser->traverse($stmts);

        return [
            'method_calls' => $visitor->methodCalls,
            'static_calls' => $visitor->staticCalls,
            'function_calls' => $visitor->functionCalls,
            'control_flow' => $visitor->controlFlow,
            'database_operations' => $visitor->databaseOps,
            'event_dispatches' => $visitor->events,
            'job_dispatches' => $visitor->jobs,
            'exception_handling' => $visitor->exceptions,
            'complexity' => $visitor->complexity,
            'call_graph' => $this->buildCallGraph($visitor),
        ];
    }

    /**
     * Analyze multiple methods and build cross-references
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
            'method_calls' => [],
            'static_calls' => [],
            'function_calls' => [],
            'control_flow' => [],
            'database_operations' => [],
            'event_dispatches' => [],
            'job_dispatches' => [],
            'exception_handling' => [],
            'complexity' => 1,
            'call_graph' => [],
        ];
    }

    private function buildCallGraph(MethodVisitor $visitor): array
    {
        $graph = [];

        // Build method call chains
        foreach ($visitor->methodCalls as $call) {
            $graph[] = [
                'type' => 'method',
                'target' => $call['method'],
                'object' => $call['object'],
                'line' => $call['line'] ?? null,
            ];
        }

        // Add static calls
        foreach ($visitor->staticCalls as $call) {
            $graph[] = [
                'type' => 'static',
                'class' => $call['class'],
                'method' => $call['method'],
                'line' => $call['line'] ?? null,
            ];
        }

        // Add function calls
        foreach ($visitor->functionCalls as $call) {
            $graph[] = [
                'type' => 'function',
                'name' => $call['name'],
                'line' => $call['line'] ?? null,
            ];
        }

        return $graph;
    }

    private function buildSummary(array $results): array
    {
        $totalCalls = 0;
        $totalComplexity = 0;
        $totalDbOps = 0;
        $totalEvents = 0;
        $totalJobs = 0;

        foreach ($results as $analysis) {
            $totalCalls += count($analysis['method_calls']) 
                         + count($analysis['static_calls']) 
                         + count($analysis['function_calls']);
            $totalComplexity += $analysis['complexity'];
            $totalDbOps += count($analysis['database_operations']);
            $totalEvents += count($analysis['event_dispatches']);
            $totalJobs += count($analysis['job_dispatches']);
        }

        return [
            'total_methods' => count($results),
            'total_calls' => $totalCalls,
            'average_complexity' => $totalComplexity / max(1, count($results)),
            'max_complexity' => max(array_column($results, 'complexity') ?: [0]),
            'total_database_operations' => $totalDbOps,
            'total_events' => $totalEvents,
            'total_jobs' => $totalJobs,
        ];
    }
}

/**
 * AST Visitor for method body analysis
 */
class MethodVisitor extends NodeVisitorAbstract
{
    public array $methodCalls = [];
    public array $staticCalls = [];
    public array $functionCalls = [];
    public array $controlFlow = [];
    public array $databaseOps = [];
    public array $events = [];
    public array $jobs = [];
    public array $exceptions = [];
    public int $complexity = 1;

    public function enterNode(Node $node)
    {
        // Method calls: $obj->method()
        if ($node instanceof MethodCall) {
            $this->methodCalls[] = [
                'object' => $this->getNodeName($node->var),
                'method' => $node->name instanceof Node\Identifier ? $node->name->toString() : 'dynamic',
                'line' => $node->getStartLine(),
            ];

            // Check for database operations
            $this->checkDatabaseOperation($node);
        }

        // Static calls: Class::method()
        if ($node instanceof StaticCall) {
            $class = $node->class instanceof Node\Name ? $node->class->toString() : 'dynamic';
            $method = $node->name instanceof Node\Identifier ? $node->name->toString() : 'dynamic';

            $this->staticCalls[] = [
                'class' => $class,
                'method' => $method,
                'line' => $node->getStartLine(),
            ];

            // Check for events, jobs, database
            $this->checkEvent($class, $method, $node);
            $this->checkJob($class, $method, $node);
            $this->checkDatabaseStatic($class, $method, $node);
        }

        // Function calls: function()
        if ($node instanceof FuncCall) {
            $name = $node->name instanceof Node\Name ? $node->name->toString() : 'dynamic';
            
            $this->functionCalls[] = [
                'name' => $name,
                'line' => $node->getStartLine(),
            ];

            // Check for event/dispatch functions
            $this->checkEventFunction($name, $node);
            $this->checkJobFunction($name, $node);
        }

        // Control flow - increases complexity
        if ($node instanceof If_) {
            $this->complexity++;
            $this->controlFlow[] = [
                'type' => 'if',
                'line' => $node->getStartLine(),
                'has_else' => $node->else !== null,
                'elseif_count' => count($node->elseifs),
            ];
        }

        if ($node instanceof ElseIf_) {
            $this->complexity++;
        }

        if ($node instanceof Foreach_ || $node instanceof For_ || $node instanceof While_) {
            $this->complexity++;
            $this->controlFlow[] = [
                'type' => $node instanceof Foreach_ ? 'foreach' : ($node instanceof For_ ? 'for' : 'while'),
                'line' => $node->getStartLine(),
            ];
        }

        if ($node instanceof Switch_) {
            $this->complexity += count($node->cases);
            $this->controlFlow[] = [
                'type' => 'switch',
                'line' => $node->getStartLine(),
                'case_count' => count($node->cases),
            ];
        }

        if ($node instanceof TryCatch) {
            $this->exceptions[] = [
                'line' => $node->getStartLine(),
                'catch_count' => count($node->catches),
                'has_finally' => $node->finally !== null,
                'caught_types' => array_map(
                    fn($catch) => array_map(fn($type) => $type->toString(), $catch->types),
                    $node->catches
                ),
            ];
        }

        return null;
    }

    private function getNodeName($node): string
    {
        if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
            return '$' . $node->name;
        }
        if ($node instanceof Node\Expr\PropertyFetch) {
            return $this->getNodeName($node->var) . '->' . ($node->name instanceof Node\Identifier ? $node->name->toString() : '?');
        }
        if ($node instanceof MethodCall) {
            return $this->getNodeName($node->var) . '->' . ($node->name instanceof Node\Identifier ? $node->name->toString() : '?') . '()';
        }
        return 'unknown';
    }

    private function checkDatabaseOperation(MethodCall $node): void
    {
        $method = $node->name instanceof Node\Identifier ? $node->name->toString() : '';
        
        $dbMethods = ['where', 'select', 'join', 'orderBy', 'groupBy', 'having', 
                      'first', 'get', 'find', 'create', 'update', 'delete', 'save',
                      'with', 'whereHas', 'has', 'pluck', 'count', 'exists'];

        if (in_array($method, $dbMethods)) {
            $this->databaseOps[] = [
                'type' => 'eloquent',
                'method' => $method,
                'object' => $this->getNodeName($node->var),
                'line' => $node->getStartLine(),
            ];
        }
    }

    private function checkDatabaseStatic(string $class, string $method, StaticCall $node): void
    {
        // DB facade
        if (str_contains($class, 'DB') || $class === 'DB') {
            $this->databaseOps[] = [
                'type' => 'db_facade',
                'method' => $method,
                'line' => $node->getStartLine(),
            ];
        }

        // Model static methods
        if (in_array($method, ['find', 'where', 'create', 'all', 'first', 'findOrFail'])) {
            $this->databaseOps[] = [
                'type' => 'model_static',
                'class' => $class,
                'method' => $method,
                'line' => $node->getStartLine(),
            ];
        }
    }

    private function checkEvent(string $class, string $method, StaticCall $node): void
    {
        if (str_contains($class, 'Event') && $method === 'dispatch') {
            $this->events[] = [
                'type' => 'static_dispatch',
                'class' => $class,
                'line' => $node->getStartLine(),
            ];
        }
    }

    private function checkEventFunction(string $name, FuncCall $node): void
    {
        if ($name === 'event') {
            $this->events[] = [
                'type' => 'function',
                'line' => $node->getStartLine(),
            ];
        }
    }

    private function checkJob(string $class, string $method, StaticCall $node): void
    {
        if ($method === 'dispatch' && !str_contains($class, 'Event')) {
            $this->jobs[] = [
                'type' => 'static_dispatch',
                'class' => $class,
                'line' => $node->getStartLine(),
            ];
        }
    }

    private function checkJobFunction(string $name, FuncCall $node): void
    {
        if ($name === 'dispatch') {
            $this->jobs[] = [
                'type' => 'function',
                'line' => $node->getStartLine(),
            ];
        }
    }
}
