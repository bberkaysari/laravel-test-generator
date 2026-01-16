<?php

namespace Bberkaysari\LaravelTestGenerator\Tests\Unit;

use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    public function test_example(): void
    {
        $this->assertTrue(true);
    }

    public function test_addition(): void
    {
        $this->assertEquals(4, 2 + 2);
    }
}