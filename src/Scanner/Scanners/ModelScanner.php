<?php

declare(strict_types=1);

namespace Bberkaysari\LaravelTestGenerator\Scanner\Scanners;

use Bberkaysari\LaravelTestGenerator\Scanner\Contracts\ScannerInterface;
use Bberkaysari\LaravelTestGenerator\Scanner\Parser\PhpParser;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use Symfony\Component\Finder\Finder;

class ModelScanner implements ScannerInterface
{
    private string $projectPath;
    private PhpParser $parser;
    private array $models = [];

    public function __construct(string $projectPath)
    {
        $this->projectPath = $projectPath;
        $this->parser = new PhpParser();
    }

    public function scan(): array
    {
        $modelPath = $this->findModelPath();

        if (!is_dir($modelPath)) {
            return [];
        }

        $finder = new Finder();
        $finder->files()->in($modelPath)->name('*.php');

        foreach ($finder as $file) {
            $content = $file->getContents();

            if ($this->isModel($content)) {
                $this->models[] = $this->parseModel($file->getRealPath(), $content);
            }
        }

        return $this->models;
    }

    private function findModelPath(): string
    {
        // Laravel 8+
        $modernPath = $this->projectPath . '/app/Models';
        if (is_dir($modernPath)) {
            return $modernPath;
        }

        // Laravel < 8
        return $this->projectPath . '/app';
    }

    private function isModel(string $content): bool
    {
        return str_contains($content, 'extends Model')
            || str_contains($content, 'extends Authenticatable');
    }

    private function parseModel(string $path, string $content): array
    {
        $ast = $this->parser->parse($content);

        $visitor = new class extends NodeVisitorAbstract {
            public string $name = '';
            public string $namespace = '';
            public string $extends = '';
            public array $properties = [];
            public array $methods = [];
            public array $fillable = [];
            public array $guarded = [];
            public array $casts = [];
            public array $hidden = [];
            public array $relations = [];

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Node\Stmt\Namespace_) {
                    $this->namespace = $node->name?->toString() ?? '';
                }

                if ($node instanceof Node\Stmt\Class_) {
                    $this->name = $node->name?->toString() ?? '';

                    if ($node->extends) {
                        $this->extends = $node->extends->getLast();
                    }

                    foreach ($node->stmts as $stmt) {
                        if ($stmt instanceof Node\Stmt\Property) {
                            $this->extractProperty($stmt);
                        }

                        if ($stmt instanceof Node\Stmt\ClassMethod) {
                            $methodName = $stmt->name->toString();
                            $this->methods[] = $methodName;

                            // Check for relations
                            $this->extractRelation($stmt);
                        }
                    }
                }

                return null;
            }

            private function extractProperty(Node\Stmt\Property $property): void
            {
                foreach ($property->props as $prop) {
                    $propertyName = $prop->name->toString();
                    $this->properties[] = $propertyName;

                    // Extract array values from properties
                    if ($prop->default instanceof Node\Expr\Array_) {
                        $values = $this->extractArrayValues($prop->default);

                        match ($propertyName) {
                            'fillable' => $this->fillable = $values,
                            'guarded' => $this->guarded = $values,
                            'hidden' => $this->hidden = $values,
                            'casts' => $this->casts = $this->extractAssociativeArray($prop->default),
                            default => null,
                        };
                    }
                }
            }

            private function extractArrayValues(Node\Expr\Array_ $array): array
            {
                $values = [];
                foreach ($array->items as $item) {
                    if ($item && $item->value instanceof Node\Scalar\String_) {
                        $values[] = $item->value->value;
                    }
                }
                return $values;
            }

            private function extractAssociativeArray(Node\Expr\Array_ $array): array
            {
                $values = [];
                foreach ($array->items as $item) {
                    if ($item && $item->key instanceof Node\Scalar\String_ && $item->value instanceof Node\Scalar\String_) {
                        $values[$item->key->value] = $item->value->value;
                    }
                }
                return $values;
            }

            private function extractRelation(Node\Stmt\ClassMethod $method): void
            {
                $methodName = $method->name->toString();

                // Skip magic methods
                if (str_starts_with($methodName, '__')) {
                    return;
                }

                // Get the method body as string for analysis
                $stmts = $method->getStmts() ?? [];

                foreach ($stmts as $stmt) {
                    if ($stmt instanceof Node\Stmt\Return_ && $stmt->expr instanceof Node\Expr\MethodCall) {
                        $relationType = $this->detectRelationType($stmt->expr);
                        if ($relationType) {
                            $this->relations[$methodName] = [
                                'type' => $relationType,
                                'model' => $this->extractRelatedModel($stmt->expr),
                            ];
                        }
                    }
                }
            }

            private function detectRelationType(Node\Expr\MethodCall $call): ?string
            {
                $relationMethods = [
                    'hasOne', 'hasMany', 'belongsTo', 'belongsToMany',
                    'morphOne', 'morphMany', 'morphTo', 'morphToMany',
                    'hasManyThrough', 'hasOneThrough',
                ];

                if ($call->name instanceof Node\Identifier) {
                    $methodName = $call->name->toString();
                    if (in_array($methodName, $relationMethods)) {
                        return $methodName;
                    }
                }

                return null;
            }

            private function extractRelatedModel(Node\Expr\MethodCall $call): ?string
            {
                if (!empty($call->args) && $call->args[0]->value instanceof Node\Expr\ClassConstFetch) {
                    $classConst = $call->args[0]->value;
                    if ($classConst->class instanceof Node\Name) {
                        return $classConst->class->getLast();
                    }
                }
                return null;
            }
        };

        $this->parser->traverse($ast, $visitor);

        return [
            'path' => $path,
            'name' => $visitor->name,
            'namespace' => $visitor->namespace,
            'extends' => $visitor->extends,
            'properties' => $visitor->properties,
            'methods' => $visitor->methods,
            'fillable' => $visitor->fillable,
            'guarded' => $visitor->guarded,
            'casts' => $visitor->casts,
            'hidden' => $visitor->hidden,
            'relations' => $visitor->relations,
        ];
    }
}
