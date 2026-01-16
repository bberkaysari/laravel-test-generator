<?php

declare(strict_types=1);

namespace Bberkaysari\LaravelTestGenerator\Generator\Contracts;

interface GeneratorInterface
{
    /**
     * Generate test code from analyzed data.
     *
     * @param array $data Analyzed model/controller/service data
     * @return string Generated test code
     */
    public function generate(array $data): string;

    /**
     * Get the test file path for the given data.
     *
     * @param array $data Analyzed data
     * @return string Test file path
     */
    public function getTestPath(array $data): string;
}
