<?php

declare(strict_types=1);

namespace Bberkaysari\LaravelTestGenerator\Scanner\Scanners;

use Bberkaysari\LaravelTestGenerator\Scanner\Contracts\ScannerInterface;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * EventScanner - Detects Laravel Events, Listeners, and Jobs
 * 
 * Capabilities:
 * - Identifies Event classes
 * - Detects Event Listeners
 * - Finds Job classes (sync and queued)
 * - Maps Event-Listener relationships
 * - Detects event() and dispatch() calls
 * - Identifies queue configuration
 */
class EventScanner implements ScannerInterface
{
    private array $events = [];
    private array $listeners = [];
    private array $jobs = [];
    private string $projectPath;

    public function __construct(string $projectPath)
    {
        $this->projectPath = rtrim($projectPath, '/');
    }

    public function scan(): array
    {
        $this->events = [];
        $this->listeners = [];
        $this->jobs = [];

        // Scan Events
        $this->scanDirectory('/app/Events', 'event');
        $this->scanDirectory('/src/Events', 'event');
        $this->scanDirectory('/app/Domain/*/Events', 'event');

        // Scan Listeners
        $this->scanDirectory('/app/Listeners', 'listener');
        $this->scanDirectory('/src/Listeners', 'listener');

        // Scan Jobs
        $this->scanDirectory('/app/Jobs', 'job');
        $this->scanDirectory('/src/Jobs', 'job');
        $this->scanDirectory('/app/Domain/*/Jobs', 'job');

        return [
            'events' => $this->events,
            'listeners' => $this->listeners,
            'jobs' => $this->jobs,
            'statistics' => [
                'total_events' => count($this->events),
                'total_listeners' => count($this->listeners),
                'total_jobs' => count($this->jobs),
                'queued_jobs' => count(array_filter($this->jobs, fn($j) => $j['is_queued'])),
            ],
        ];
    }

    private function scanDirectory(string $relativePath, string $type): void
    {
        $path = $this->projectPath . $relativePath;

        // Handle wildcard paths
        if (str_contains($relativePath, '*')) {
            $pattern = str_replace('*', '**', $relativePath);
            $this->scanGlob($pattern, $type);
            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $finder = new Finder();
        $finder->files()->in($path)->name('*.php');

        foreach ($finder as $file) {
            $this->scanFile($file, $type);
        }
    }

    private function scanGlob(string $pattern, string $type): void
    {
        $basePath = $this->projectPath . '/' . explode('*', $pattern)[0];
        
        if (!is_dir($basePath)) {
            return;
        }

        $finder = new Finder();
        $finder->files()->in($basePath)->name('*.php');

        foreach ($finder as $file) {
            if (fnmatch('*Events*', $file->getPathname()) && $type === 'event') {
                $this->scanFile($file, $type);
            }
            if (fnmatch('*Jobs*', $file->getPathname()) && $type === 'job') {
                $this->scanFile($file, $type);
            }
        }
    }

    private function scanFile(SplFileInfo $file, string $type): void
    {
        $content = $file->getContents();
        $parser = (new ParserFactory())->createForHostVersion();

        try {
            $ast = $parser->parse($content);
            if (!$ast) {
                return;
            }

            $traverser = new NodeTraverser();
            $visitor = new EventVisitor($file->getRelativePathname(), $type);
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            // Store results based on type
            foreach ($visitor->classes as $classData) {
                switch ($type) {
                    case 'event':
                        $this->events[] = $classData;
                        break;
                    case 'listener':
                        $this->listeners[] = $classData;
                        break;
                    case 'job':
                        $this->jobs[] = $classData;
                        break;
                }
            }

        } catch (\Exception $e) {
            // Skip files with parse errors
        }
    }

    public function getEvents(): array
    {
        return $this->events;
    }

    public function getListeners(): array
    {
        return $this->listeners;
    }

    public function getJobs(): array
    {
        return $this->jobs;
    }
}

/**
 * AST Visitor for Event/Listener/Job detection
 */
class EventVisitor extends NodeVisitorAbstract
{
    public array $classes = [];
    private string $currentNamespace = '';
    private string $filename;
    private string $type;

    public function __construct(string $filename, string $type)
    {
        $this->filename = $filename;
        $this->type = $type;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = $node->name ? $node->name->toString() : '';
        }

        if ($node instanceof Class_) {
            $className = $node->name ? $node->name->toString() : 'Anonymous';
            $fqn = $this->currentNamespace ? $this->currentNamespace . '\\' . $className : $className;

            $classData = [
                'name' => $className,
                'fqn' => $fqn,
                'namespace' => $this->currentNamespace,
                'filename' => $this->filename,
                'extends' => $node->extends ? $node->extends->toString() : null,
                'implements' => array_map(
                    fn($interface) => $interface->toString(),
                    $node->implements
                ),
                'constructor' => $this->extractConstructor($node),
                'methods' => $this->extractMethods($node),
                'properties' => $this->extractProperties($node),
            ];

            // Add type-specific data
            if ($this->type === 'job') {
                $classData['is_queued'] = $this->isQueuedJob($node);
                $classData['queue_name'] = $this->getQueueName($node);
                $classData['delay'] = $this->getDelay($node);
            }

            if ($this->type === 'listener') {
                $classData['handles_events'] = $this->getHandledEvents($node);
                $classData['is_queued'] = $this->implementsShouldQueue($node);
            }

            $this->classes[] = $classData;
        }

        return null;
    }

    private function extractConstructor(Class_ $class): ?array
    {
        $constructor = $class->getMethod('__construct');
        if (!$constructor) {
            return null;
        }

        return [
            'parameters' => $this->extractParameters($constructor),
        ];
    }

    private function extractMethods(Class_ $class): array
    {
        $methods = [];

        foreach ($class->getMethods() as $method) {
            $methods[] = [
                'name' => $method->name->toString(),
                'visibility' => $this->getVisibility($method),
                'is_static' => $method->isStatic(),
                'parameters' => $this->extractParameters($method),
                'return_type' => $method->getReturnType() ? $this->getTypeString($method->getReturnType()) : null,
            ];
        }

        return $methods;
    }

    private function extractParameters($method): array
    {
        $params = [];

        foreach ($method->getParams() as $param) {
            $params[] = [
                'name' => $param->var instanceof Node\Expr\Variable ? $param->var->name : 'unknown',
                'type' => $param->type ? $this->getTypeString($param->type) : null,
            ];
        }

        return $params;
    }

    private function extractProperties(Class_ $class): array
    {
        $properties = [];

        foreach ($class->getProperties() as $property) {
            foreach ($property->props as $prop) {
                $properties[] = [
                    'name' => $prop->name->toString(),
                    'visibility' => $this->getVisibility($property),
                    'type' => $property->type ? $this->getTypeString($property->type) : null,
                ];
            }
        }

        return $properties;
    }

    private function isQueuedJob(Class_ $class): bool
    {
        foreach ($class->implements as $interface) {
            if (str_contains($interface->toString(), 'ShouldQueue')) {
                return true;
            }
        }
        return false;
    }

    private function implementsShouldQueue(Class_ $class): bool
    {
        return $this->isQueuedJob($class);
    }

    private function getQueueName(Class_ $class): ?string
    {
        foreach ($class->getProperties() as $property) {
            foreach ($property->props as $prop) {
                if ($prop->name->toString() === 'queue' && $prop->default) {
                    if ($prop->default instanceof Node\Scalar\String_) {
                        return $prop->default->value;
                    }
                }
            }
        }
        return null;
    }

    private function getDelay(Class_ $class): ?int
    {
        foreach ($class->getProperties() as $property) {
            foreach ($property->props as $prop) {
                if ($prop->name->toString() === 'delay' && $prop->default) {
                    if ($prop->default instanceof Node\Scalar\LNumber) {
                        return $prop->default->value;
                    }
                }
            }
        }
        return null;
    }

    private function getHandledEvents(Class_ $class): array
    {
        $handleMethod = $class->getMethod('handle');
        if (!$handleMethod) {
            return [];
        }

        $events = [];
        foreach ($handleMethod->getParams() as $param) {
            if ($param->type) {
                $events[] = $this->getTypeString($param->type);
            }
        }

        return $events;
    }

    private function getVisibility($node): string
    {
        if ($node->isPublic()) return 'public';
        if ($node->isProtected()) return 'protected';
        if ($node->isPrivate()) return 'private';
        return 'public';
    }

    private function getTypeString($type): string
    {
        if ($type instanceof Node\Identifier) {
            return $type->toString();
        }
        if ($type instanceof Node\Name) {
            return $type->toString();
        }
        if ($type instanceof Node\NullableType) {
            return '?' . $this->getTypeString($type->type);
        }
        if ($type instanceof Node\UnionType) {
            return implode('|', array_map(fn($t) => $this->getTypeString($t), $type->types));
        }
        return 'mixed';
    }
}
