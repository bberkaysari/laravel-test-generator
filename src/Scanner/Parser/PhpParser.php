<?php

declare(strict_types=1);

namespace Bberkaysari\LaravelTestGenerator\Scanner\Parser;

use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\ParserFactory;

class PhpParser
{
    private \PhpParser\Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * Parse PHP code and return AST.
     *
     * @throws \RuntimeException
     */
    public function parse(string $code): array
    {
        try {
            return $this->parser->parse($code) ?? [];
        } catch (Error $e) {
            throw new \RuntimeException("Parse error: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Traverse AST with visitor.
     */
    public function traverse(array $ast, NodeVisitor $visitor): array
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);

        return $traverser->traverse($ast);
    }
}
