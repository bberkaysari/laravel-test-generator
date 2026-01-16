<?php

declare(strict_types=1);

namespace Bberkaysari\LaravelTestGenerator\Scanner\Scanners;

use Bberkaysari\LaravelTestGenerator\Scanner\Contracts\ScannerInterface;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * FormRequestScanner - Detects and analyzes Laravel Form Request classes
 * 
 * Capabilities:
 * - Identifies FormRequest classes
 * - Extracts validation rules
 * - Detects authorize() logic
 * - Maps custom validation messages
 * - Identifies validation attributes
 * - Detects prepareForValidation() and withValidator()
 */
class FormRequestScanner implements ScannerInterface
{
    private array $formRequests = [];
    private string $projectPath;

    public function __construct(string $projectPath)
    {
        $this->projectPath = rtrim($projectPath, '/');
    }

    public function scan(): array
    {
        $this->formRequests = [];

        $paths = [
            $this->projectPath . '/app/Http/Requests',
            $this->projectPath . '/src/Http/Requests',
            $this->projectPath . '/app/Requests',
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
            'form_requests' => $this->formRequests,
            'statistics' => [
                'total_requests' => count($this->formRequests),
                'with_authorization' => count(array_filter($this->formRequests, fn($r) => $r['has_authorize'])),
                'with_custom_messages' => count(array_filter($this->formRequests, fn($r) => $r['has_messages'])),
                'total_rules' => array_sum(array_column($this->formRequests, 'rule_count')),
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
            $visitor = new FormRequestVisitor($file->getRelativePathname());
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            foreach ($visitor->formRequests as $request) {
                $this->formRequests[] = $request;
            }

        } catch (\Exception $e) {
            // Skip files with parse errors
        }
    }

    public function getFormRequests(): array
    {
        return $this->formRequests;
    }

    /**
     * Get FormRequest by name
     */
    public function getFormRequestByName(string $name): ?array
    {
        foreach ($this->formRequests as $request) {
            if ($request['name'] === $name || $request['fqn'] === $name) {
                return $request;
            }
        }
        return null;
    }
}

/**
 * AST Visitor for FormRequest detection
 */
class FormRequestVisitor extends NodeVisitorAbstract
{
    public array $formRequests = [];
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
            // Check if extends FormRequest
            if (!$node->extends || !str_contains($node->extends->toString(), 'FormRequest')) {
                return null;
            }

            $className = $node->name ? $node->name->toString() : 'Anonymous';
            $fqn = $this->currentNamespace ? $this->currentNamespace . '\\' . $className : $className;

            $rulesMethod = $node->getMethod('rules');
            $authorizeMethod = $node->getMethod('authorize');
            $messagesMethod = $node->getMethod('messages');
            $attributesMethod = $node->getMethod('attributes');

            $rules = $rulesMethod ? $this->extractRules($rulesMethod) : [];

            $this->formRequests[] = [
                'name' => $className,
                'fqn' => $fqn,
                'namespace' => $this->currentNamespace,
                'filename' => $this->filename,
                'rules' => $rules,
                'rule_count' => count($rules),
                'has_authorize' => $authorizeMethod !== null,
                'authorize_returns_true' => $this->authorizesAll($authorizeMethod),
                'has_messages' => $messagesMethod !== null,
                'custom_messages' => $messagesMethod ? $this->extractMessages($messagesMethod) : [],
                'has_attributes' => $attributesMethod !== null,
                'custom_attributes' => $attributesMethod ? $this->extractAttributes($attributesMethod) : [],
                'methods' => $this->extractMethods($node),
            ];
        }

        return null;
    }

    private function extractRules(ClassMethod $method): array
    {
        $rules = [];

        // Find return statement
        $returnNode = $this->findReturnStatement($method);
        if (!$returnNode || !$returnNode->expr) {
            return $rules;
        }

        // Parse array
        if ($returnNode->expr instanceof Array_) {
            $rules = $this->parseRulesArray($returnNode->expr);
        }

        return $rules;
    }

    private function findReturnStatement(ClassMethod $method): ?Return_
    {
        $returnStmt = null;

        $traverser = new NodeTraverser();
        $visitor = new class extends NodeVisitorAbstract {
            public ?Return_ $returnNode = null;

            public function enterNode(Node $node) {
                if ($node instanceof Return_ && $this->returnNode === null) {
                    $this->returnNode = $node;
                }
                return null;
            }
        };

        $traverser->addVisitor($visitor);
        $traverser->traverse($method->stmts ?? []);

        return $visitor->returnNode;
    }

    private function parseRulesArray(Array_ $array): array
    {
        $rules = [];

        foreach ($array->items as $item) {
            if (!$item instanceof ArrayItem) {
                continue;
            }

            $field = null;
            if ($item->key instanceof String_) {
                $field = $item->key->value;
            }

            $ruleValue = $this->extractRuleValue($item->value);

            if ($field && $ruleValue) {
                $rules[$field] = [
                    'field' => $field,
                    'rules' => $ruleValue,
                    'parsed_rules' => $this->parseRuleString($ruleValue),
                ];
            }
        }

        return $rules;
    }

    private function extractRuleValue($node): ?string
    {
        // String: 'required|email'
        if ($node instanceof String_) {
            return $node->value;
        }

        // Array: ['required', 'email']
        if ($node instanceof Array_) {
            $rules = [];
            foreach ($node->items as $item) {
                if ($item instanceof ArrayItem && $item->value instanceof String_) {
                    $rules[] = $item->value->value;
                }
            }
            return implode('|', $rules);
        }

        return null;
    }

    private function parseRuleString(string $rules): array
    {
        $parsed = [];
        $ruleList = explode('|', $rules);

        foreach ($ruleList as $rule) {
            // Handle rules with parameters: min:3, max:255
            if (str_contains($rule, ':')) {
                [$ruleName, $params] = explode(':', $rule, 2);
                $parsed[] = [
                    'rule' => $ruleName,
                    'parameters' => explode(',', $params),
                ];
            } else {
                $parsed[] = [
                    'rule' => $rule,
                    'parameters' => [],
                ];
            }
        }

        return $parsed;
    }

    private function authorizesAll(?ClassMethod $method): bool
    {
        if (!$method) {
            return false;
        }

        $returnNode = $this->findReturnStatement($method);
        if (!$returnNode || !$returnNode->expr) {
            return false;
        }

        // Check if returns true
        if ($returnNode->expr instanceof Node\Expr\ConstFetch) {
            $name = $returnNode->expr->name->toString();
            return strtolower($name) === 'true';
        }

        return false;
    }

    private function extractMessages(ClassMethod $method): array
    {
        $messages = [];

        $returnNode = $this->findReturnStatement($method);
        if (!$returnNode || !$returnNode->expr) {
            return $messages;
        }

        if ($returnNode->expr instanceof Array_) {
            foreach ($returnNode->expr->items as $item) {
                if (!$item instanceof ArrayItem) {
                    continue;
                }

                $key = null;
                if ($item->key instanceof String_) {
                    $key = $item->key->value;
                }

                $value = null;
                if ($item->value instanceof String_) {
                    $value = $item->value->value;
                }

                if ($key && $value) {
                    $messages[$key] = $value;
                }
            }
        }

        return $messages;
    }

    private function extractAttributes(ClassMethod $method): array
    {
        $attributes = [];

        $returnNode = $this->findReturnStatement($method);
        if (!$returnNode || !$returnNode->expr) {
            return $attributes;
        }

        if ($returnNode->expr instanceof Array_) {
            foreach ($returnNode->expr->items as $item) {
                if (!$item instanceof ArrayItem) {
                    continue;
                }

                $key = null;
                if ($item->key instanceof String_) {
                    $key = $item->key->value;
                }

                $value = null;
                if ($item->value instanceof String_) {
                    $value = $item->value->value;
                }

                if ($key && $value) {
                    $attributes[$key] = $value;
                }
            }
        }

        return $attributes;
    }

    private function extractMethods(Class_ $class): array
    {
        $methods = [];

        foreach ($class->getMethods() as $method) {
            $methods[] = [
                'name' => $method->name->toString(),
                'visibility' => $this->getVisibility($method),
            ];
        }

        return $methods;
    }

    private function getVisibility($node): string
    {
        if ($node->isPublic()) return 'public';
        if ($node->isProtected()) return 'protected';
        if ($node->isPrivate()) return 'private';
        return 'public';
    }
}
