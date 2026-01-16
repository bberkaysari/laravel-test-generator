<?php

namespace Bberkaysari\LaravelTestGenerator\Scanner\Contracts;

interface ScannerInterface
{
    /**
     * Scan and return collected data.
     *
     * @return array
     */
    public function scan(): array;
}