<?php

namespace Tests\Unit\Scanner;

use PHPUnit\Framework\TestCase;
use Bberkaysari\LaravelTestGenerator\Scanner\Scanners\EventScanner;

class EventScannerTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturePath = __DIR__ . '/../../Fixtures/sample-project';
    }

    public function test_it_scans_events_listeners_and_jobs(): void
    {
        $scanner = new EventScanner($this->fixturePath);
        $result = $scanner->scan();

        $this->assertArrayHasKey('events', $result);
        $this->assertArrayHasKey('listeners', $result);
        $this->assertArrayHasKey('jobs', $result);
        $this->assertArrayHasKey('statistics', $result);
    }

    public function test_it_detects_event_classes(): void
    {
        $scanner = new EventScanner($this->fixturePath);
        $result = $scanner->scan();

        $events = $result['events'];

        // Should find UserCreated event
        $userCreated = $this->findByName($events, 'UserCreated');
        $this->assertNotNull($userCreated, 'UserCreated event should be found');

        $this->assertEquals('UserCreated', $userCreated['name']);
        $this->assertEquals('App\\Events', $userCreated['namespace']);
    }

    public function test_it_detects_broadcastable_events(): void
    {
        $scanner = new EventScanner($this->fixturePath);
        $result = $scanner->scan();

        $events = $result['events'];
        $userCreated = $this->findByName($events, 'UserCreated');

        $this->assertNotNull($userCreated);
        // Check if implements ShouldBroadcast
        $implements = $userCreated['implements'] ?? [];
        $isBroadcastable = in_array('ShouldBroadcast', $implements);
        $this->assertTrue($isBroadcastable, 'UserCreated should implement ShouldBroadcast');
    }

    public function test_it_extracts_event_constructor_properties(): void
    {
        $scanner = new EventScanner($this->fixturePath);
        $result = $scanner->scan();

        $events = $result['events'];
        $userCreated = $this->findByName($events, 'UserCreated');

        $this->assertNotNull($userCreated);
        $this->assertArrayHasKey('constructor', $userCreated);

        $params = $userCreated['constructor']['parameters'] ?? [];
        $this->assertNotEmpty($params);

        // Should have user parameter
        $userParam = $this->findParamByName($params, 'user');
        $this->assertNotNull($userParam);
        $this->assertEquals('User', $userParam['type']);
    }

    public function test_it_detects_listener_classes(): void
    {
        $scanner = new EventScanner($this->fixturePath);
        $result = $scanner->scan();

        $listeners = $result['listeners'];

        // Should find SendWelcomeEmail listener
        $sendEmail = $this->findByName($listeners, 'SendWelcomeEmail');
        $this->assertNotNull($sendEmail, 'SendWelcomeEmail listener should be found');

        $this->assertEquals('SendWelcomeEmail', $sendEmail['name']);
        $this->assertEquals('App\\Listeners', $sendEmail['namespace']);
    }

    public function test_it_detects_queueable_listeners(): void
    {
        $scanner = new EventScanner($this->fixturePath);
        $result = $scanner->scan();

        $listeners = $result['listeners'];
        $sendEmail = $this->findByName($listeners, 'SendWelcomeEmail');

        $this->assertNotNull($sendEmail);
        $this->assertTrue($sendEmail['is_queued'] ?? false);
    }

    public function test_it_extracts_listener_queue_config(): void
    {
        $scanner = new EventScanner($this->fixturePath);
        $result = $scanner->scan();

        $listeners = $result['listeners'];
        $sendEmail = $this->findByName($listeners, 'SendWelcomeEmail');

        $this->assertNotNull($sendEmail, 'SendWelcomeEmail should be found');

        // Check properties contain queue config
        $properties = $sendEmail['properties'] ?? [];
        $propertyNames = array_column($properties, 'name');

        // Listener should have queue-related properties
        $this->assertContains('connection', $propertyNames);
        $this->assertContains('queue', $propertyNames);
    }

    public function test_it_detects_job_classes(): void
    {
        $scanner = new EventScanner($this->fixturePath);
        $result = $scanner->scan();

        $jobs = $result['jobs'];

        // Should find ProcessUserData job
        $processJob = $this->findByName($jobs, 'ProcessUserData');
        $this->assertNotNull($processJob, 'ProcessUserData job should be found');

        $this->assertEquals('ProcessUserData', $processJob['name']);
        $this->assertEquals('App\\Jobs', $processJob['namespace']);
    }

    public function test_it_extracts_job_retry_config(): void
    {
        $scanner = new EventScanner($this->fixturePath);
        $result = $scanner->scan();

        $jobs = $result['jobs'];
        $processJob = $this->findByName($jobs, 'ProcessUserData');

        $this->assertNotNull($processJob);

        // Check properties contain retry config
        $properties = $processJob['properties'] ?? [];
        $propertyNames = array_column($properties, 'name');

        // Job should have retry-related properties
        $this->assertContains('tries', $propertyNames);
        $this->assertContains('timeout', $propertyNames);
    }

    public function test_it_extracts_job_dependencies(): void
    {
        $scanner = new EventScanner($this->fixturePath);
        $result = $scanner->scan();

        $jobs = $result['jobs'];
        $processJob = $this->findByName($jobs, 'ProcessUserData');

        $this->assertNotNull($processJob);
        $this->assertArrayHasKey('constructor', $processJob);

        $params = $processJob['constructor']['parameters'] ?? [];
        $userParam = $this->findParamByName($params, 'user');
        $this->assertNotNull($userParam);
        $this->assertEquals('User', $userParam['type']);
    }

    public function test_it_generates_statistics(): void
    {
        $scanner = new EventScanner($this->fixturePath);
        $result = $scanner->scan();

        $stats = $result['statistics'];

        $this->assertArrayHasKey('total_events', $stats);
        $this->assertArrayHasKey('total_listeners', $stats);
        $this->assertArrayHasKey('total_jobs', $stats);
        $this->assertArrayHasKey('queued_jobs', $stats);
    }

    public function test_it_handles_missing_directories(): void
    {
        $scanner = new EventScanner('/non/existent/path');
        $result = $scanner->scan();

        $this->assertEmpty($result['events']);
        $this->assertEmpty($result['listeners']);
        $this->assertEmpty($result['jobs']);
        $this->assertEquals(0, $result['statistics']['total_events']);
    }

    public function test_it_detects_queued_jobs(): void
    {
        $scanner = new EventScanner($this->fixturePath);
        $result = $scanner->scan();

        $jobs = $result['jobs'];
        $processJob = $this->findByName($jobs, 'ProcessUserData');

        $this->assertNotNull($processJob);
        $this->assertTrue($processJob['is_queued'] ?? false);
    }

    public function test_it_extracts_handled_events_from_listener(): void
    {
        $scanner = new EventScanner($this->fixturePath);
        $result = $scanner->scan();

        $listeners = $result['listeners'];
        $sendEmail = $this->findByName($listeners, 'SendWelcomeEmail');

        $this->assertNotNull($sendEmail);
        $this->assertArrayHasKey('handles_events', $sendEmail);

        $handledEvents = $sendEmail['handles_events'];
        $this->assertContains('UserCreated', $handledEvents);
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

    private function findParamByName(array $params, string $name): ?array
    {
        foreach ($params as $param) {
            if (($param['name'] ?? '') === $name) {
                return $param;
            }
        }
        return null;
    }
}
