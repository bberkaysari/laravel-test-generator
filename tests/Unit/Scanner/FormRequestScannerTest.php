<?php

namespace Tests\Unit\Scanner;

use PHPUnit\Framework\TestCase;
use Bberkaysari\LaravelTestGenerator\Scanner\Scanners\FormRequestScanner;

class FormRequestScannerTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturePath = __DIR__ . '/../../Fixtures/sample-project';
    }

    public function test_it_scans_form_requests(): void
    {
        $scanner = new FormRequestScanner($this->fixturePath);
        $result = $scanner->scan();

        $this->assertArrayHasKey('form_requests', $result);
        $this->assertArrayHasKey('statistics', $result);
    }

    public function test_it_detects_form_request_classes(): void
    {
        $scanner = new FormRequestScanner($this->fixturePath);
        $result = $scanner->scan();

        $requests = $result['form_requests'];

        // Should find StoreUserRequest
        $storeRequest = $this->findByName($requests, 'StoreUserRequest');
        $this->assertNotNull($storeRequest, 'StoreUserRequest should be found');

        $this->assertEquals('StoreUserRequest', $storeRequest['name']);
        $this->assertEquals('App\\Http\\Requests', $storeRequest['namespace']);
    }

    public function test_it_extracts_validation_rules(): void
    {
        $scanner = new FormRequestScanner($this->fixturePath);
        $result = $scanner->scan();

        $requests = $result['form_requests'];
        $storeRequest = $this->findByName($requests, 'StoreUserRequest');

        $this->assertNotNull($storeRequest);
        $this->assertArrayHasKey('rules', $storeRequest);

        $rules = $storeRequest['rules'];
        $this->assertNotEmpty($rules);

        // Should have name, email, password rules
        $this->assertArrayHasKey('name', $rules);
        $this->assertArrayHasKey('email', $rules);
        $this->assertArrayHasKey('password', $rules);
    }

    public function test_it_detects_authorization_method(): void
    {
        $scanner = new FormRequestScanner($this->fixturePath);
        $result = $scanner->scan();

        $requests = $result['form_requests'];
        $storeRequest = $this->findByName($requests, 'StoreUserRequest');

        $this->assertNotNull($storeRequest);
        $this->assertTrue($storeRequest['has_authorize'] ?? false);
    }

    public function test_it_detects_custom_messages(): void
    {
        $scanner = new FormRequestScanner($this->fixturePath);
        $result = $scanner->scan();

        $requests = $result['form_requests'];
        $storeRequest = $this->findByName($requests, 'StoreUserRequest');

        $this->assertNotNull($storeRequest);
        $this->assertTrue($storeRequest['has_messages'] ?? false);

        if (isset($storeRequest['custom_messages'])) {
            $this->assertNotEmpty($storeRequest['custom_messages']);
        }
    }

    public function test_it_detects_prepare_for_validation(): void
    {
        $scanner = new FormRequestScanner($this->fixturePath);
        $result = $scanner->scan();

        $requests = $result['form_requests'];
        $storeRequest = $this->findByName($requests, 'StoreUserRequest');

        $this->assertNotNull($storeRequest);

        // Check if prepareForValidation method exists in the methods list
        $methods = $storeRequest['methods'] ?? [];
        $methodNames = array_column($methods, 'name');
        $this->assertContains('prepareForValidation', $methodNames);
    }

    public function test_it_generates_statistics(): void
    {
        $scanner = new FormRequestScanner($this->fixturePath);
        $result = $scanner->scan();

        $stats = $result['statistics'];

        $this->assertArrayHasKey('total_requests', $stats);
        $this->assertArrayHasKey('with_authorization', $stats);
        $this->assertArrayHasKey('with_custom_messages', $stats);
        $this->assertGreaterThanOrEqual(1, $stats['total_requests']);
    }

    public function test_it_handles_missing_directories(): void
    {
        $scanner = new FormRequestScanner('/non/existent/path');
        $result = $scanner->scan();

        $this->assertEmpty($result['form_requests']);
        $this->assertEquals(0, $result['statistics']['total_requests']);
    }

    public function test_it_extracts_rule_types(): void
    {
        $scanner = new FormRequestScanner($this->fixturePath);
        $result = $scanner->scan();

        $requests = $result['form_requests'];
        $storeRequest = $this->findByName($requests, 'StoreUserRequest');

        $this->assertNotNull($storeRequest, 'StoreUserRequest should be found');

        if (isset($storeRequest['rules'])) {
            $rules = $storeRequest['rules'];

            // name should have required rule
            if (isset($rules['name'])) {
                $ruleData = $rules['name'];
                $ruleString = $ruleData['rules'] ?? '';
                $this->assertStringContainsString('required', $ruleString);
            }

            // email should have email rule
            if (isset($rules['email'])) {
                $ruleData = $rules['email'];
                $ruleString = $ruleData['rules'] ?? '';
                $this->assertStringContainsString('email', $ruleString);
            }
        }
    }

    private function findByName(array $items, string $name): ?array
    {
        foreach ($items as $item) {
            if (($item['name'] ?? '') === $name) {
                return $item;
            }
        }
        return null;
    }
}
