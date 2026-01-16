<?php

declare(strict_types=1);

namespace Bberkaysari\LaravelTestGenerator\Scanner\Scanners;

use Bberkaysari\LaravelTestGenerator\Scanner\Contracts\ScannerInterface;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Param;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * ServiceScanner - Detects and analyzes Service and Repository classes
 * 
 * Capabilities:
 * - Identifies service and repository patterns
 * - Extracts method signatures and dependencies
 * - Detects constructor injection
 * - Maps return types and type hints
 * - Tracks business logic patterns
 */
class ServiceScanner implements ScannerInterface
{
    private array $services = [];
    private array $repositories = [];
    private string $projectPath;

    public function __construct(string $projectPath)
    {
        $this->projectPath = rtrim($projectPath, '/');
    }

    public function scan(): array
    {
        $this->services = [];
        $this->repositories = [];

        $paths = [
            $this->projectPath . '/app/Services',
            $this->projectPath . '/app/Repositories',
            $this->projectPath . '/app/Domain',
            $this->projectPath . '/src/Services',
            $this->projectPath . '/src/Repositories',
        ];

        foreach ($paths as $path) {
            if (!is_dir($path)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($path)->name('*.php');

            foreach ($finder as $file) {
                $this->scanFile($file);
            }
        }

        return [
            'services' => $this->services,
            'repositories' => $this->repositories,
            'statistics' => [
                'total_services' => count($this->services),
                'total_repositories' => count($this->repositories),
                'total_methods' => $this->countMethods(),
            ],
        ];
    }

    private function scanFile(SplFileInfo $file): void
    {
        $content = $file->getContents();
        $parser = (new ParserFactory())->createForHostVersion();

        try {
            $ast = $parser->parse($content);
            if (!$ast) {
                return;
            }

            $traverser = new NodeTraverser();
            $visitor = new class($file->getRelativePathname()) extends NodeVisitorAbstract {
                public array $classes = [];
                private string $currentNamespace = '';
                private string $filename;

                public function __construct(string $filename)
                {
                    $this->filename = $filename;
                }

                public function enterNode(Node $node)
                {
                    if ($node instanceof Node\Stmt\Namespace_) {
                        $this->currentNamespace = $node->name ? $node->name->toString() : '';
                    }

                    if ($node instanceof Class_) {
                        $className = $node->name ? $node->name->toString() : 'Anonymous';
                        $fqn = $this->currentNamespace ? $this->currentNamespace . '\\' . $className : $className;

                        $this->classes[] = [
                            'name' => $className,
                            'fqn' => $fqn,
                            'namespace' => $this->currentNamespace,
                            'filename' => $this->filename,
                            'is_abstract' => $node->isAbstract(),
                            'is_final' => $node->isFinal(),
                            'extends' => $node->extends ? $node->extends->toString() : null,
                            'implements' => array_map(
                                fn($interface) => $interface->toString(),
                                $node->implements
                            ),
                            'constructor' => $this->extractConstructor($node),
                            'methods' => $this->extractMethods($node),
                            'properties' => $this->extractProperties($node),
                        ];
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
                        'is_public' => $constructor->isPublic(),
                    ];
                }

                private function extractMethods(Class_ $class): array
                {
                    $methods = [];

                    foreach ($class->getMethods() as $method) {
                        if ($method->name->toString() === '__construct') {
                            continue;
                        }

                        $methods[] = [
                            'name' => $method->name->toString(),
                            'visibility' => $this->getVisibility($method),
                            'is_static' => $method->isStatic(),
                            'is_abstract' => $method->isAbstract(),
                            'parameters' => $this->extractParameters($method),
                            'return_type' => $method->getReturnType() ? $this->getTypeString($method->getReturnType()) : null,
                            'has_body' => $method->stmts !== null,
                            'doc_comment' => $method->getDocComment() ? $method->getDocComment()->getText() : null,
                        ];
                    }

                    return $methods;
                }

                private function extractParameters(ClassMethod $method): array
                {
                    $params = [];

                    foreach ($method->getParams() as $param) {
                        $params[] = [
                            'name' => $param->var instanceof Node\Expr\Variable ? $param->var->name : 'unknown',
                            'type' => $param->type ? $this->getTypeString($param->type) : null,
                            'has_default' => $param->default !== null,
                            'is_variadic' => $param->variadic,
                            'is_passed_by_ref' => $param->byRef,
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
                                'is_static' => $property->isStatic(),
                                'type' => $property->type ? $this->getTypeString($property->type) : null,
                                'has_default' => $prop->default !== null,
                            ];
                        }
                    }

                    return $properties;
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
            };

            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            foreach ($visitor->classes as $classData) {
                if ($this->isRepository($classData)) {
                    $this->repositories[] = $classData;
                } elseif ($this->isService($classData)) {
                    $this->services[] = $classData;
                }
            }

        } catch (\Exception $e) {
            // Skip files with parse errors
        }
    }

    private function isRepository(array $classData): bool
    {
        // Check if it's in a Repositories directory
        if (str_contains($classData['filename'], 'Repositories')) {
            return true;
        }

        // Check if it implements Repository interface
        foreach ($classData['implements'] as $interface) {
            if (str_contains($interface, 'Repository')) {
                return true;
            }
        }

        // Check if class name ends with Repository
        if (str_ends_with($classData['name'], 'Repository')) {
            return true;
        }

        return false;
    }

    private function isService(array $classData): bool
    {
        // Check if it's in a Services directory
        if (str_contains($classData['filename'], 'Services')) {
            return true;
        }

        // Check if class name ends with Service
        if (str_ends_with($classData['name'], 'Service')) {
            return true;
        }

        // Check if it has typical service patterns (multiple dependencies, business logic)
        if ($classData['constructor'] && count($classData['constructor']['parameters']) >= 2) {
            return true;
        }

        return false;
    }

    private function countMethods(): int
    {
        $total = 0;
        foreach ($this->services as $service) {
            $total += count($service['methods']);
        }
        foreach ($this->repositories as $repo) {
            $total += count($repo['methods']);
        }
        return $total;
    }

    public function getServices(): array
    {
        return $this->services;
    }

    public function getRepositories(): array
    {
        return $this->repositories;
    }
}
