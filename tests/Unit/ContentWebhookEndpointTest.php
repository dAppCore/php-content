<?php

declare(strict_types=1);

namespace Core\Mod\Content\Tests\Unit;

use Core\Mod\Content\Models\ContentWebhookEndpoint;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContentWebhookEndpointTest extends TestCase
{
    #[Test]
    public function it_generates_uuid_on_creation(): void
    {
        $endpoint = ContentWebhookEndpoint::factory()->create();

        $this->assertNotNull($endpoint->uuid);
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/',
            $endpoint->uuid
        );
    }

    #[Test]
    public function it_generates_secret_on_creation(): void
    {
        $endpoint = ContentWebhookEndpoint::factory()->create(['secret' => null]);

        $this->assertNotNull($endpoint->secret);
        $this->assertEquals(64, strlen($endpoint->secret));
    }

    #[Test]
    public function it_can_verify_valid_signature(): void
    {
        $endpoint = ContentWebhookEndpoint::factory()->create([
            'secret' => 'test-secret-key',
        ]);

        $payload = '{"event": "test", "data": {}}';
        $signature = hash_hmac('sha256', $payload, 'test-secret-key');

        $this->assertTrue($endpoint->verifySignature($payload, $signature));
    }

    #[Test]
    public function it_can_verify_github_style_signature(): void
    {
        $endpoint = ContentWebhookEndpoint::factory()->create([
            'secret' => 'test-secret-key',
        ]);

        $payload = '{"event": "test"}';
        $signature = 'sha256='.hash_hmac('sha256', $payload, 'test-secret-key');

        $this->assertTrue($endpoint->verifySignature($payload, $signature));
    }

    #[Test]
    public function it_rejects_invalid_signature(): void
    {
        $endpoint = ContentWebhookEndpoint::factory()->create([
            'secret' => 'test-secret-key',
        ]);

        $payload = '{"event": "test"}';
        $invalidSignature = 'invalid-signature';

        $this->assertFalse($endpoint->verifySignature($payload, $invalidSignature));
    }

    #[Test]
    public function it_allows_webhook_without_secret(): void
    {
        $endpoint = ContentWebhookEndpoint::factory()->noSecret()->create();

        // When no secret, verification should pass
        $this->assertTrue($endpoint->verifySignature('any payload', null));
    }

    #[Test]
    public function it_checks_allowed_event_types(): void
    {
        $endpoint = ContentWebhookEndpoint::factory()->create([
            'allowed_types' => ['wordpress.post_created', 'wordpress.post_updated'],
        ]);

        $this->assertTrue($endpoint->isTypeAllowed('wordpress.post_created'));
        $this->assertTrue($endpoint->isTypeAllowed('wordpress.post_updated'));
        $this->assertFalse($endpoint->isTypeAllowed('wordpress.post_deleted'));
        $this->assertFalse($endpoint->isTypeAllowed('cms.content_created'));
    }

    #[Test]
    public function it_allows_all_types_when_empty(): void
    {
        $endpoint = ContentWebhookEndpoint::factory()->create([
            'allowed_types' => [],
        ]);

        $this->assertTrue($endpoint->isTypeAllowed('wordpress.post_created'));
        $this->assertTrue($endpoint->isTypeAllowed('cms.content_created'));
        $this->assertTrue($endpoint->isTypeAllowed('generic.payload'));
    }

    #[Test]
    public function it_tracks_failure_count(): void
    {
        $endpoint = ContentWebhookEndpoint::factory()->create([
            'failure_count' => 0,
            'is_enabled' => true,
        ]);

        $endpoint->incrementFailureCount();
        $endpoint->refresh();

        $this->assertEquals(1, $endpoint->failure_count);
        $this->assertTrue($endpoint->is_enabled);
    }

    #[Test]
    public function it_auto_disables_after_max_failures(): void
    {
        $endpoint = ContentWebhookEndpoint::factory()->create([
            'failure_count' => ContentWebhookEndpoint::MAX_FAILURES - 1,
            'is_enabled' => true,
        ]);

        $endpoint->incrementFailureCount();
        $endpoint->refresh();

        $this->assertEquals(ContentWebhookEndpoint::MAX_FAILURES, $endpoint->failure_count);
        $this->assertFalse($endpoint->is_enabled);
    }

    #[Test]
    public function it_detects_circuit_breaker_state(): void
    {
        $endpoint = ContentWebhookEndpoint::factory()->create([
            'failure_count' => ContentWebhookEndpoint::MAX_FAILURES,
        ]);

        $this->assertTrue($endpoint->isCircuitBroken());

        $endpoint->update(['failure_count' => 0]);
        $endpoint->refresh();

        $this->assertFalse($endpoint->isCircuitBroken());
    }

    #[Test]
    public function it_resets_failure_count(): void
    {
        $endpoint = ContentWebhookEndpoint::factory()->create([
            'failure_count' => 5,
        ]);

        $endpoint->resetFailureCount();
        $endpoint->refresh();

        $this->assertEquals(0, $endpoint->failure_count);
        $this->assertNotNull($endpoint->last_received_at);
    }

    #[Test]
    public function it_can_regenerate_secret(): void
    {
        $endpoint = ContentWebhookEndpoint::factory()->create();
        $originalSecret = $endpoint->secret;

        $newSecret = $endpoint->regenerateSecret();
        $endpoint->refresh();

        $this->assertNotEquals($originalSecret, $newSecret);
        $this->assertEquals($newSecret, $endpoint->secret);
    }

    #[Test]
    public function it_generates_endpoint_url(): void
    {
        $endpoint = ContentWebhookEndpoint::factory()->create();

        $url = $endpoint->getEndpointUrl();

        $this->assertStringContainsString('/api/content/webhooks/', $url);
        $this->assertStringContainsString($endpoint->uuid, $url);
    }

    #[Test]
    public function it_provides_status_attributes(): void
    {
        // Active endpoint
        $active = ContentWebhookEndpoint::factory()->create([
            'is_enabled' => true,
            'failure_count' => 0,
        ]);
        $this->assertEquals('green', $active->status_color);
        $this->assertEquals('Active', $active->status_label);

        // Disabled endpoint
        $disabled = ContentWebhookEndpoint::factory()->create([
            'is_enabled' => false,
            'failure_count' => 0,
        ]);
        $this->assertEquals('zinc', $disabled->status_color);
        $this->assertEquals('Disabled', $disabled->status_label);

        // Circuit broken
        $broken = ContentWebhookEndpoint::factory()->circuitBroken()->create();
        $this->assertEquals('red', $broken->status_color);
        $this->assertStringContainsString('Circuit', $broken->status_label);
    }
}
